<?php
/**
 * ML Model Evaluator
 * Calculates comprehensive evaluation metrics for ML models
 */

namespace CRM\Services;

use CRM\Database;

class MLModelEvaluator
{
    private AnalyticsWorkspaceService $analyticsWorkspace;

    public function __construct(?AnalyticsWorkspaceService $analyticsWorkspace = null)
    {
        $this->analyticsWorkspace = $analyticsWorkspace ?? new AnalyticsWorkspaceService();
    }

    /**
     * Evaluate classification model
     * 
     * @param array $predictions Array of predicted labels (0 or 1)
     * @param array $actuals Array of actual labels (0 or 1)
     * @return array Evaluation metrics
     */
    public function evaluateClassification(array $predictions, array $actuals): array
    {
        if (count($predictions) !== count($actuals)) {
            throw new \Exception("Predictions and actuals must have same length");
        }
        
        $tp = $fp = $tn = $fn = 0;
        
        foreach ($predictions as $i => $pred) {
            $actual = $actuals[$i];
            
            if ($pred == 1 && $actual == 1) {
                $tp++; // True Positive
            } elseif ($pred == 1 && $actual == 0) {
                $fp++; // False Positive
            } elseif ($pred == 0 && $actual == 0) {
                $tn++; // True Negative
            } else {
                $fn++; // False Negative
            }
        }
        
        $total = count($predictions);
        
        // Calculate metrics
        $accuracy = ($tp + $tn) / max(1, $total);
        $precision = $tp / max(1, $tp + $fp);
        $recall = $tp / max(1, $tp + $fn);
        $specificity = $tn / max(1, $tn + $fp);
        $f1 = 2 * ($precision * $recall) / max(0.0001, $precision + $recall);
        
        // Calculate balanced accuracy
        $balancedAccuracy = ($recall + $specificity) / 2;
        
        return [
            'accuracy' => $accuracy,
            'precision' => $precision,
            'recall' => $recall,
            'specificity' => $specificity,
            'f1_score' => $f1,
            'balanced_accuracy' => $balancedAccuracy,
            'true_positives' => $tp,
            'false_positives' => $fp,
            'true_negatives' => $tn,
            'false_negatives' => $fn,
            'total_samples' => $total,
            'positive_samples' => $tp + $fn,
            'negative_samples' => $tn + $fp
        ];
    }
    
    /**
     * Calculate AUC-ROC (Area Under ROC Curve)
     * 
     * @param array $probabilities Array of prediction probabilities (0-1)
     * @param array $labels Array of actual labels (0 or 1)
     * @return float AUC-ROC score
     */
    public function calculateAUC(array $probabilities, array $labels): float
    {
        if (count($probabilities) !== count($labels)) {
            throw new \Exception("Probabilities and labels must have same length");
        }
        
        // Pair probabilities with labels
        $pairs = [];
        foreach ($probabilities as $i => $prob) {
            $pairs[] = [
                'prob' => (float) $prob,
                'label' => (int) $labels[$i]
            ];
        }
        
        // Sort by probability descending
        usort($pairs, fn($a, $b) => $b['prob'] <=> $a['prob']);
        
        $positiveCount = array_sum($labels);
        $negativeCount = count($labels) - $positiveCount;
        
        if ($positiveCount == 0 || $negativeCount == 0) {
            return 0.5; // Cannot calculate AUC if all labels are same
        }
        
        // Calculate AUC using trapezoidal rule
        $auc = 0;
        $tp = 0;
        $fp = 0;
        $prevTP = 0;
        $prevFP = 0;
        
        foreach ($pairs as $pair) {
            if ($pair['label'] == 1) {
                $tp++;
            } else {
                $fp++;
            }
            
            // Calculate area under curve segment
            $auc += ($fp - $prevFP) * ($tp + $prevTP) / 2;
            
            $prevTP = $tp;
            $prevFP = $fp;
        }
        
        // Normalize by total area
        $auc = $auc / ($positiveCount * $negativeCount);
        
        return max(0, min(1, $auc));
    }
    
    /**
     * Calculate Log Loss
     * 
     * @param array $probabilities Array of prediction probabilities (0-1)
     * @param array $labels Array of actual labels (0 or 1)
     * @return float Log loss
     */
    public function calculateLogLoss(array $probabilities, array $labels): float
    {
        if (count($probabilities) !== count($labels)) {
            throw new \Exception("Probabilities and labels must have same length");
        }
        
        $logLoss = 0;
        $epsilon = 1e-15; // Small value to avoid log(0)
        
        foreach ($probabilities as $i => $prob) {
            $prob = max($epsilon, min(1 - $epsilon, (float) $prob));
            $label = (int) $labels[$i];
            $logLoss -= $label * log($prob) + (1 - $label) * log(1 - $prob);
        }
        
        return $logLoss / count($probabilities);
    }
    
    /**
     * Calculate Brier Score
     * 
     * @param array $probabilities Array of prediction probabilities (0-1)
     * @param array $labels Array of actual labels (0 or 1)
     * @return float Brier score
     */
    public function calculateBrierScore(array $probabilities, array $labels): float
    {
        if (count($probabilities) !== count($labels)) {
            throw new \Exception("Probabilities and labels must have same length");
        }
        
        $brierScore = 0;
        
        foreach ($probabilities as $i => $prob) {
            $label = (int) $labels[$i];
            $brierScore += pow($prob - $label, 2);
        }
        
        return $brierScore / count($probabilities);
    }
    
    /**
     * Generate confusion matrix
     * 
     * @param array $predictions Array of predicted labels (0 or 1)
     * @param array $actuals Array of actual labels (0 or 1)
     * @return array Confusion matrix
     */
    public function generateConfusionMatrix(array $predictions, array $actuals): array
    {
        $metrics = $this->evaluateClassification($predictions, $actuals);
        
        return [
            'matrix' => [
                [['label' => 'True Negative', 'value' => $metrics['true_negatives']],
                 ['label' => 'False Positive', 'value' => $metrics['false_positives']]],
                [['label' => 'False Negative', 'value' => $metrics['false_negatives']],
                 ['label' => 'True Positive', 'value' => $metrics['true_positives']]]
            ],
            'metrics' => $metrics
        ];
    }
    
    /**
     * Calculate business metrics
     * 
     * @param array $predictions Array of predicted labels
     * @param array $actuals Array of actual labels
     * @param array $values Optional array of deal values for revenue calculation
     * @return array Business metrics
     */
    public function calculateBusinessMetrics(array $predictions, array $actuals, array $values = []): array
    {
        $metrics = $this->evaluateClassification($predictions, $actuals);
        
        // Calculate conversion rate by score bucket
        $buckets = [
            'high' => ['count' => 0, 'converted' => 0, 'revenue' => 0],
            'medium' => ['count' => 0, 'converted' => 0, 'revenue' => 0],
            'low' => ['count' => 0, 'converted' => 0, 'revenue' => 0]
        ];
        
        // Group predictions into buckets (assuming probabilities)
        foreach ($predictions as $i => $pred) {
            $bucket = 'low';
            if ($pred >= 0.7) {
                $bucket = 'high';
            } elseif ($pred >= 0.4) {
                $bucket = 'medium';
            }
            
            $buckets[$bucket]['count']++;
            if ($actuals[$i] == 1) {
                $buckets[$bucket]['converted']++;
                if (!empty($values[$i])) {
                    $buckets[$bucket]['revenue'] += $values[$i];
                }
            }
        }
        
        // Calculate conversion rates
        foreach ($buckets as $bucket => &$data) {
            $data['conversion_rate'] = $data['count'] > 0 
                ? ($data['converted'] / $data['count']) 
                : 0;
        }
        
        return [
            'score_buckets' => $buckets,
            'overall_conversion_rate' => $metrics['positive_samples'] / max(1, $metrics['total_samples']),
            'precision_by_bucket' => [
                'high' => $buckets['high']['conversion_rate'],
                'medium' => $buckets['medium']['conversion_rate'],
                'low' => $buckets['low']['conversion_rate']
            ]
        ];
    }
    
    /**
     * Get feature importance for a model
     * 
     * @param int $modelId Model ID
     * @return array Feature importance scores
     */
    public function getFeatureImportance(int $modelId, ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->resolveModelWorkspaceId($modelId, $workspaceId);
        $importance = Database::query(
            "SELECT feature_name, importance_score, contribution_positive, contribution_negative
             FROM ml_feature_importance
             WHERE workspace_id = ? AND model_id = ?
             ORDER BY importance_score DESC",
            [$resolvedWorkspaceId, $modelId]
        );
        
        return $importance;
    }
    
    /**
     * Calculate comprehensive metrics for a model
     * 
     * @param array $predictions Array of predictions (probabilities or labels)
     * @param array $actuals Array of actual labels
     * @param bool $isProbability Whether predictions are probabilities (true) or labels (false)
     * @param array $values Optional deal values for business metrics
     * @return array Comprehensive metrics
     */
    public function calculateComprehensiveMetrics(
        array $predictions, 
        array $actuals, 
        bool $isProbability = true,
        array $values = []
    ): array {
        $labels = $isProbability ? array_map(fn($p) => $p > 0.5 ? 1 : 0, $predictions) : $predictions;
        
        $classificationMetrics = $this->evaluateClassification($labels, $actuals);
        
        $metrics = [
            'classification' => $classificationMetrics,
            'confusion_matrix' => $this->generateConfusionMatrix($labels, $actuals)
        ];
        
        if ($isProbability) {
            $metrics['probability'] = [
                'auc_roc' => $this->calculateAUC($predictions, $actuals),
                'log_loss' => $this->calculateLogLoss($predictions, $actuals),
                'brier_score' => $this->calculateBrierScore($predictions, $actuals)
            ];
        }
        
        if (!empty($values)) {
            $metrics['business'] = $this->calculateBusinessMetrics($labels, $actuals, $values);
        }
        
        return $metrics;
    }
    
    /**
     * Store metrics in database
     * 
     * @param int $modelId Model ID
     * @param array $metrics Metrics to store
     */
    public function storeMetrics(int $modelId, array $metrics, ?int $workspaceId = null): void
    {
        $resolvedWorkspaceId = $this->resolveModelWorkspaceId($modelId, $workspaceId);
        $metricsToStore = [
            ['name' => 'accuracy', 'value' => $metrics['classification']['accuracy'] ?? null, 'type' => 'classification'],
            ['name' => 'precision', 'value' => $metrics['classification']['precision'] ?? null, 'type' => 'classification'],
            ['name' => 'recall', 'value' => $metrics['classification']['recall'] ?? null, 'type' => 'classification'],
            ['name' => 'f1_score', 'value' => $metrics['classification']['f1_score'] ?? null, 'type' => 'classification'],
            ['name' => 'auc_roc', 'value' => $metrics['probability']['auc_roc'] ?? null, 'type' => 'probability'],
            ['name' => 'log_loss', 'value' => $metrics['probability']['log_loss'] ?? null, 'type' => 'probability'],
            ['name' => 'brier_score', 'value' => $metrics['probability']['brier_score'] ?? null, 'type' => 'probability']
        ];
        
        foreach ($metricsToStore as $metric) {
            if ($metric['value'] !== null) {
                Database::execute(
                    "INSERT INTO ml_model_metrics (workspace_id, model_id, metric_name, metric_value, metric_type)
                     VALUES (?, ?, ?, ?, ?)",
                    [$resolvedWorkspaceId, $modelId, $metric['name'], $metric['value'], $metric['type']]
                );
            }
        }
    }

    private function resolveModelWorkspaceId(int $modelId, ?int $workspaceId = null): int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return $workspaceId;
        }

        $model = Database::queryOne(
            "SELECT workspace_id
             FROM ml_models
             WHERE id = ?
             LIMIT 1",
            [$modelId]
        );

        if ($model && (int) ($model['workspace_id'] ?? 0) > 0) {
            return (int) $model['workspace_id'];
        }

        return $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
    }
}
