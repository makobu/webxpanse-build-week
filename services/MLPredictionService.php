<?php
/**
 * ML Prediction Service
 * Real-time prediction service for conversion probability
 */

namespace CRM\Services;

use CRM\Database;
use CRM\CacheManager;
use CRM\Services\MLFeatureService;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Modules\MLModelManager;

class MLPredictionService
{
    private MLFeatureService $featureService;
    private MLModelManager $modelManager;
    private CacheManager $cache;
    private AnalyticsWorkspaceService $analyticsWorkspace;
    
    public function __construct(
        ?MLFeatureService $featureService = null,
        ?MLModelManager $modelManager = null,
        ?CacheManager $cache = null,
        ?AnalyticsWorkspaceService $analyticsWorkspace = null
    )
    {
        $this->featureService = $featureService ?? new MLFeatureService();
        $this->analyticsWorkspace = $analyticsWorkspace ?? new AnalyticsWorkspaceService();
        $this->modelManager = $modelManager ?? new MLModelManager(null, $this->analyticsWorkspace);
        $this->cache = $cache ?? new CacheManager();
    }
    
    /**
     * Predict conversion probability for a contact
     * 
     * @param int $contactId Contact ID
     * @param string $modelType Model type ('conversion', 'churn', 'engagement')
     * @return array Prediction with score, probability, confidence, and factors
     */
    public function predictConversion(int $contactId, string $modelType = 'conversion', ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $this->assertWorkspaceScopedContact($contactId, $resolvedWorkspaceId);

        // Check cache first
        $cacheKey = "ml_prediction_{$resolvedWorkspaceId}_{$contactId}_{$modelType}";
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        // Check database cache
        $cachedPrediction = $this->getCachedPrediction($contactId, $modelType, $resolvedWorkspaceId);
        if ($cachedPrediction && !$this->isCacheExpired($cachedPrediction)) {
            return [
                'contact_id' => $contactId,
                'prediction_score' => (float) $cachedPrediction['prediction_score'],
                'probability' => (float) $cachedPrediction['probability'],
                'confidence' => (float) $cachedPrediction['confidence'],
                'top_factors' => json_decode($cachedPrediction['top_factors'], true),
                'feature_values' => json_decode($cachedPrediction['feature_values'], true),
                'model_id' => (int) $cachedPrediction['model_id'],
                'model_version' => $cachedPrediction['model_version'] ?? null,
                'cached' => true
            ];
        }
        
        // Get active model
        $model = $this->modelManager->getActiveModel($modelType, $resolvedWorkspaceId);
        if (!$model) {
            // Fallback: return default prediction
            return $this->getDefaultPrediction($contactId, $modelType);
        }
        
        // Extract features
        $features = $this->featureService->extractAllFeatures($contactId, $resolvedWorkspaceId);
        
        // Make prediction
        $prediction = $this->makePrediction($model, $features);
        
        // Calculate confidence
        $confidence = $this->calculateConfidence($prediction, $features);
        
        // Get top factors
        $topFactors = $this->getTopFactors($model, $features);
        
        // Store prediction in cache and database
        $result = [
            'contact_id' => $contactId,
            'prediction_score' => $prediction['score'],
            'probability' => $prediction['probability'],
            'confidence' => $confidence,
            'top_factors' => $topFactors,
            'feature_values' => $features,
            'model_id' => $model['id'],
            'model_version' => $model['version'],
            'cached' => false
        ];
        
        $this->cachePrediction($contactId, $model['id'], $result, $resolvedWorkspaceId);
        $this->cache->set($cacheKey, $result, 3600); // Cache for 1 hour
        
        return $result;
    }
    
    /**
     * Batch prediction for multiple contacts
     * 
     * @param array $contactIds Array of contact IDs
     * @param string $modelType Model type
     * @return array Predictions indexed by contact ID
     */
    public function predictBatch(array $contactIds, string $modelType = 'conversion', ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $predictions = [];
        
        foreach ($contactIds as $contactId) {
            try {
                $predictions[$contactId] = $this->predictConversion($contactId, $modelType, $resolvedWorkspaceId);
            } catch (\Exception $e) {
                // Log error and continue
                error_log("Prediction error for contact {$contactId}: " . $e->getMessage());
                $predictions[$contactId] = $this->getDefaultPrediction($contactId, $modelType);
            }
        }
        
        return $predictions;
    }
    
    /**
     * Get prediction with confidence
     * 
     * @param int $contactId Contact ID
     * @param string $modelType Model type
     * @return array Prediction with confidence
     */
    public function getPredictionWithConfidence(int $contactId, string $modelType = 'conversion', ?int $workspaceId = null): array
    {
        $prediction = $this->predictConversion($contactId, $modelType, $workspaceId);
        
        return [
            'prediction' => $prediction,
            'confidence_level' => $this->getConfidenceLevel($prediction['confidence']),
            'recommendation' => $this->getRecommendation($prediction, $modelType)
        ];
    }
    
    /**
     * Refresh prediction (force recalculation)
     * 
     * @param int $contactId Contact ID
     * @param string $modelType Model type
     * @return array Fresh prediction
     */
    public function refreshPrediction(int $contactId, string $modelType = 'conversion', ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $this->assertWorkspaceScopedContact($contactId, $resolvedWorkspaceId);

        // Clear cache
        $cacheKey = "ml_prediction_{$resolvedWorkspaceId}_{$contactId}_{$modelType}";
        $this->cache->delete($cacheKey);
        
        // Clear database cache
        Database::execute(
            "DELETE FROM ml_predictions 
             WHERE workspace_id = ? AND contact_id = ? AND model_id IN (
                 SELECT id FROM ml_models WHERE workspace_id = ? AND model_type = ?
             )",
            [$resolvedWorkspaceId, $contactId, $resolvedWorkspaceId, $modelType]
        );
        
        // Clear feature cache
        $this->featureService->clearCache($contactId, $resolvedWorkspaceId);
        
        // Get fresh prediction
        return $this->predictConversion($contactId, $modelType, $resolvedWorkspaceId);
    }
    
    /**
     * Make prediction using model
     */
    private function makePrediction(array $model, array $features): array
    {
        $modelData = json_decode($model['model_data'], true);
        
        if (!$modelData) {
            throw new \Exception("Invalid model data");
        }
        
        $algorithm = $model['algorithm'];
        
        // Convert features to array in correct order
        $featureList = $model['feature_list'] ?? [];
        $featureVector = [];
        foreach ($featureList as $featureName) {
            $featureVector[] = (float) ($features[$featureName] ?? 0);
        }
        
        // Make prediction based on algorithm
        switch ($algorithm) {
            case 'logistic_regression':
                return $this->predictLogisticRegression($modelData, $featureVector);
            case 'random_forest':
                return $this->predictRandomForest($modelData, $featureVector);
            case 'gradient_boosting':
                return $this->predictGradientBoosting($modelData, $featureVector);
            default:
                throw new \Exception("Unsupported algorithm: {$algorithm}");
        }
    }
    
    /**
     * Predict using logistic regression
     */
    private function predictLogisticRegression(array $modelData, array $features): array
    {
        $coefficients = $modelData['coefficients'] ?? [];
        $bias = $modelData['bias'] ?? 0;
        
        // Calculate score
        $score = $bias;
        foreach ($features as $i => $feature) {
            $score += $feature * ($coefficients[$i] ?? 0);
        }
        
        // Convert to probability using sigmoid
        $probability = $this->sigmoid($score);
        
        // Convert to 0-100 score
        $predictionScore = $probability * 100;
        
        return [
            'score' => $predictionScore,
            'probability' => $probability,
            'raw_score' => $score
        ];
    }
    
    /**
     * Predict using random forest (simplified - would use actual RF model in production)
     */
    private function predictRandomForest(array $modelData, array $features): array
    {
        // For now, fallback to logistic regression approach
        // In production, would use actual random forest model
        return $this->predictLogisticRegression($modelData, $features);
    }
    
    /**
     * Predict using gradient boosting (simplified - would use actual GB model in production)
     */
    private function predictGradientBoosting(array $modelData, array $features): array
    {
        // For now, fallback to logistic regression approach
        // In production, would use actual gradient boosting model
        return $this->predictLogisticRegression($modelData, $features);
    }
    
    /**
     * Calculate prediction confidence
     */
    private function calculateConfidence(array $prediction, array $features): float
    {
        // Confidence based on:
        // 1. Probability distance from 0.5 (more confident if closer to 0 or 1)
        // 2. Feature completeness (more features = higher confidence)
        // 3. Feature values (extreme values = higher confidence)
        
        $probability = $prediction['probability'];
        
        // Distance from 0.5
        $distanceFromCenter = abs($probability - 0.5) * 2; // 0-1 scale
        
        // Feature completeness
        $filledFeatures = 0;
        foreach ($features as $value) {
            if ($value !== null && $value !== 0 && $value !== '') {
                $filledFeatures++;
            }
        }
        $completeness = min(1, $filledFeatures / max(1, count($features)));
        
        // Combine factors
        $confidence = ($distanceFromCenter * 0.6) + ($completeness * 0.4);
        
        return max(0, min(1, $confidence));
    }
    
    /**
     * Get top factors affecting prediction
     */
    private function getTopFactors(array $model, array $features): array
    {
        // Get feature importance from database
        $importance = Database::query(
            "SELECT feature_name, importance_score, contribution_positive, contribution_negative
             FROM ml_feature_importance
             WHERE workspace_id = ? AND model_id = ?
             ORDER BY importance_score DESC
             LIMIT 10",
            [
                (int) ($model['workspace_id'] ?? $this->analyticsWorkspace->requireAnalyticsWorkspaceId()),
                $model['id']
            ]
        );
        
        if (empty($importance)) {
            // Fallback: calculate from model coefficients
            return $this->calculateFactorsFromModel($model, $features);
        }
        
        $factors = [];
        foreach ($importance as $item) {
            $featureName = $item['feature_name'];
            $featureValue = $features[$featureName] ?? 0;
            $importanceScore = (float) $item['importance_score'];
            
            // Determine if positive or negative contribution
            $contribution = $featureValue > 0 
                ? ($item['contribution_positive'] ?? $importanceScore)
                : ($item['contribution_negative'] ?? -$importanceScore);
            
            $factors[] = [
                'feature' => $featureName,
                'value' => $featureValue,
                'importance' => $importanceScore,
                'contribution' => $contribution,
                'impact' => $contribution > 0 ? 'positive' : 'negative'
            ];
        }
        
        return $factors;
    }
    
    /**
     * Calculate factors from model coefficients
     */
    private function calculateFactorsFromModel(array $model, array $features): array
    {
        $modelData = json_decode($model['model_data'], true);
        $coefficients = $modelData['coefficients'] ?? [];
        $featureList = $model['feature_list'] ?? [];
        
        $factors = [];
        foreach ($featureList as $i => $featureName) {
            $coef = $coefficients[$i] ?? 0;
            $value = (float) ($features[$featureName] ?? 0);
            $contribution = $coef * $value;
            
            if (abs($contribution) > 0.01) { // Only include significant contributions
                $factors[] = [
                    'feature' => $featureName,
                    'value' => $value,
                    'importance' => abs($coef),
                    'contribution' => $contribution,
                    'impact' => $contribution > 0 ? 'positive' : 'negative'
                ];
            }
        }
        
        // Sort by absolute contribution
        usort($factors, fn($a, $b) => abs($b['contribution']) <=> abs($a['contribution']));
        
        return array_slice($factors, 0, 10); // Top 10
    }
    
    /**
     * Cache prediction in database
     */
    private function cachePrediction(int $contactId, int $modelId, array $prediction, int $workspaceId): void
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        Database::execute(
            "INSERT INTO ml_predictions 
             (workspace_id, contact_id, model_id, prediction_score, probability, confidence, 
              top_factors, feature_values, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
             prediction_score = VALUES(prediction_score),
             probability = VALUES(probability),
             confidence = VALUES(confidence),
             top_factors = VALUES(top_factors),
             feature_values = VALUES(feature_values),
             expires_at = VALUES(expires_at),
             cached_at = CURRENT_TIMESTAMP",
            [
                $workspaceId,
                $contactId,
                $modelId,
                $prediction['prediction_score'],
                $prediction['probability'],
                $prediction['confidence'],
                json_encode($prediction['top_factors']),
                json_encode($prediction['feature_values']),
                $expiresAt
            ]
        );
        
        // The contacts.ml_score component is persisted only by AILeadScoring::recalculateScore().
    }
    
    /**
     * Get cached prediction from database
     */
    private function getCachedPrediction(int $contactId, string $modelType, int $workspaceId): ?array
    {
        return Database::queryOne(
            "SELECT p.*, m.version as model_version
             FROM ml_predictions p
             JOIN ml_models m ON p.model_id = m.id
             WHERE p.workspace_id = ? 
               AND p.contact_id = ? 
               AND m.workspace_id = ?
               AND m.model_type = ? 
               AND m.is_active = TRUE
             ORDER BY p.cached_at DESC
             LIMIT 1",
            [$workspaceId, $contactId, $workspaceId, $modelType]
        );
    }
    
    /**
     * Check if cache is expired
     */
    private function isCacheExpired(array $cachedPrediction): bool
    {
        if (empty($cachedPrediction['expires_at'])) {
            return true;
        }
        
        return strtotime($cachedPrediction['expires_at']) < time();
    }
    
    /**
     * Get default prediction when model unavailable
     */
    private function getDefaultPrediction(int $contactId, string $modelType): array
    {
        return [
            'contact_id' => $contactId,
            'prediction_score' => 50.0,
            'probability' => 0.5,
            'confidence' => 0.0,
            'top_factors' => [],
            'feature_values' => [],
            'model_id' => null,
            'model_version' => null,
            'cached' => false,
            'fallback' => true
        ];
    }
    
    /**
     * Get confidence level string
     */
    private function getConfidenceLevel(float $confidence): string
    {
        if ($confidence >= 0.8) {
            return 'high';
        } elseif ($confidence >= 0.6) {
            return 'medium';
        } else {
            return 'low';
        }
    }
    
    /**
     * Get recommendation based on prediction
     */
    private function getRecommendation(array $prediction, string $modelType): string
    {
        $score = $prediction['prediction_score'];
        $confidence = $prediction['confidence'];
        
        if ($modelType === 'conversion') {
            if ($score >= 70 && $confidence >= 0.7) {
                return 'high_priority';
            } elseif ($score >= 50 && $confidence >= 0.6) {
                return 'medium_priority';
            } else {
                return 'low_priority';
            }
        } elseif ($modelType === 'churn') {
            if ($score >= 70 && $confidence >= 0.7) {
                return 'high_risk';
            } elseif ($score >= 50 && $confidence >= 0.6) {
                return 'medium_risk';
            } else {
                return 'low_risk';
            }
        }
        
        return 'monitor';
    }
    
    /**
     * Sigmoid function
     */
    private function sigmoid(float $x): float
    {
        return 1 / (1 + exp(-max(-500, min(500, $x))));
    }

    private function assertWorkspaceScopedContact(int $contactId, int $workspaceId): void
    {
        $contact = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ? AND id = ?
             LIMIT 1",
            [$workspaceId, $contactId]
        );

        if (!$contact) {
            throw new \RuntimeException('Contact not found in the active workspace.');
        }
    }
}
