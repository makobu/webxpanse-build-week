<?php
/**
 * ML Model Manager
 * Manages ML model lifecycle: versioning, comparison, promotion, rollback
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\MLModelEvaluator;

class MLModelManager
{
    private MLModelEvaluator $evaluator;
    private AnalyticsWorkspaceService $analyticsWorkspace;
    
    public function __construct(
        ?MLModelEvaluator $evaluator = null,
        ?AnalyticsWorkspaceService $analyticsWorkspace = null
    )
    {
        $this->evaluator = $evaluator ?? new MLModelEvaluator();
        $this->analyticsWorkspace = $analyticsWorkspace ?? new AnalyticsWorkspaceService();
    }
    
    /**
     * Create a new model version
     * 
     * @param array $modelData Model data including type, algorithm, metrics
     * @return string Model version ID
     */
    public function createModelVersion(array $modelData, ?int $workspaceId = null): string
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $version = $modelData['version'] ?? $this->generateVersion(
            $modelData['model_type'] ?? 'conversion',
            $modelData['algorithm'] ?? 'logistic_regression'
        );
        
        $userId = $_SESSION['user_id'] ?? null;
        
        Database::execute(
            "INSERT INTO ml_models 
             (workspace_id, model_type, version, algorithm, model_data, feature_list, hyperparameters,
              accuracy, precision_score, recall_score, f1_score, auc_roc,
              training_samples, validation_samples, test_samples, trained_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $resolvedWorkspaceId,
                $modelData['model_type'],
                $version,
                $modelData['algorithm'],
                $modelData['model_data'],
                json_encode($modelData['feature_list'] ?? []),
                json_encode($modelData['hyperparameters'] ?? []),
                $modelData['accuracy'] ?? null,
                $modelData['precision_score'] ?? null,
                $modelData['recall_score'] ?? null,
                $modelData['f1_score'] ?? null,
                $modelData['auc_roc'] ?? null,
                $modelData['training_samples'] ?? 0,
                $modelData['validation_samples'] ?? 0,
                $modelData['test_samples'] ?? 0,
                $userId,
                $modelData['notes'] ?? null
            ]
        );
        
        return Database::lastInsertId();
    }
    
    /**
     * Compare two models
     * 
     * @param int $modelId1 First model ID
     * @param int $modelId2 Second model ID
     * @return array Comparison results
     */
    public function compareModels(int $modelId1, int $modelId2, ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $model1 = $this->getModel($modelId1, $resolvedWorkspaceId);
        $model2 = $this->getModel($modelId2, $resolvedWorkspaceId);
        
        if (!$model1 || !$model2) {
            throw new \Exception("One or both models not found");
        }
        
        if ($model1['model_type'] !== $model2['model_type']) {
            throw new \Exception("Cannot compare models of different types");
        }
        
        $comparison = [
            'model1' => [
                'id' => $model1['id'],
                'version' => $model1['version'],
                'algorithm' => $model1['algorithm'],
                'accuracy' => $model1['accuracy'],
                'precision_score' => $model1['precision_score'],
                'recall_score' => $model1['recall_score'],
                'f1_score' => $model1['f1_score'],
                'auc_roc' => $model1['auc_roc']
            ],
            'model2' => [
                'id' => $model2['id'],
                'version' => $model2['version'],
                'algorithm' => $model2['algorithm'],
                'accuracy' => $model2['accuracy'],
                'precision_score' => $model2['precision_score'],
                'recall_score' => $model2['recall_score'],
                'f1_score' => $model2['f1_score'],
                'auc_roc' => $model2['auc_roc']
            ],
            'improvements' => [],
            'winner' => null
        ];
        
        // Calculate improvements
        $metrics = ['accuracy', 'precision_score', 'recall_score', 'f1_score', 'auc_roc'];
        $model1Better = 0;
        $model2Better = 0;
        
        foreach ($metrics as $metric) {
            $val1 = (float) ($model1[$metric] ?? 0);
            $val2 = (float) ($model2[$metric] ?? 0);
            
            if ($val1 > 0 && $val2 > 0) {
                $improvement = (($val2 - $val1) / $val1) * 100;
                $comparison['improvements'][$metric] = [
                    'absolute' => $val2 - $val1,
                    'percentage' => $improvement,
                    'better' => $val2 > $val1 ? 'model2' : 'model1'
                ];
                
                if ($val2 > $val1) {
                    $model2Better++;
                } else {
                    $model1Better++;
                }
            }
        }
        
        // Determine winner (prefer AUC-ROC as primary metric)
        $auc1 = (float) ($model1['auc_roc'] ?? 0);
        $auc2 = (float) ($model2['auc_roc'] ?? 0);
        
        if ($auc2 > $auc1) {
            $comparison['winner'] = 'model2';
        } elseif ($auc1 > $auc2) {
            $comparison['winner'] = 'model1';
        } else {
            // Tie-breaker: use F1 score
            $f1_1 = (float) ($model1['f1_score'] ?? 0);
            $f1_2 = (float) ($model2['f1_score'] ?? 0);
            $comparison['winner'] = $f1_2 > $f1_1 ? 'model2' : 'model1';
        }
        
        // Store comparison
        $this->storeComparison($modelId1, $modelId2, $comparison, $resolvedWorkspaceId);
        
        return $comparison;
    }
    
    /**
     * Promote model to active (production)
     * 
     * @param int $modelId Model ID to promote
     * @return bool Success
     */
    public function promoteModel(int $modelId, ?int $workspaceId = null): bool
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $model = $this->getModel($modelId, $resolvedWorkspaceId);
        
        if (!$model) {
            throw new \Exception("Model not found: {$modelId}");
        }
        
        // Deactivate all other models of the same type
        Database::execute(
            "UPDATE ml_models 
             SET is_active = FALSE 
             WHERE workspace_id = ? AND model_type = ? AND id != ?",
            [$resolvedWorkspaceId, $model['model_type'], $modelId]
        );
        
        // Activate this model
        Database::execute(
            "UPDATE ml_models 
             SET is_active = TRUE 
             WHERE workspace_id = ? AND id = ?",
            [$resolvedWorkspaceId, $modelId]
        );
        
        // Update contacts to use this model
        Database::execute(
            "UPDATE contacts 
             SET ml_model_id = ? 
             WHERE workspace_id = ?
               AND (ml_model_id IS NULL OR ml_model_id != ?)",
            [$modelId, $resolvedWorkspaceId, $modelId]
        );
        
        return true;
    }
    
    /**
     * Get active model for a type
     * 
     * @param string $modelType Model type ('conversion', 'churn', 'engagement')
     * @return array|null Active model or null
     */
    public function getActiveModel(string $modelType = 'conversion', ?int $workspaceId = null): ?array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $model = Database::queryOne(
            "SELECT * FROM ml_models 
             WHERE workspace_id = ? AND model_type = ? AND is_active = TRUE 
             ORDER BY trained_at DESC 
             LIMIT 1",
            [$resolvedWorkspaceId, $modelType]
        );
        
        return $this->hydrateModel($model);
    }
    
    /**
     * Get model by ID
     * 
     * @param int $modelId Model ID
     * @return array|null Model data or null
     */
    public function getModel(int $modelId, ?int $workspaceId = null): ?array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $model = Database::queryOne(
            "SELECT * FROM ml_models WHERE workspace_id = ? AND id = ?",
            [$resolvedWorkspaceId, $modelId]
        );
        
        return $this->hydrateModel($model);
    }
    
    /**
     * List all models
     * 
     * @param string|null $modelType Filter by model type
     * @param bool $activeOnly Only return active models
     * @return array List of models
     */
    public function listModels(?string $modelType = null, bool $activeOnly = false, ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $sql = "SELECT id, model_type, version, algorithm, is_active, 
                       accuracy, precision_score, recall_score, f1_score, auc_roc,
                       training_samples, trained_at, trained_by, notes
                FROM ml_models
                WHERE workspace_id = ?";
        
        $params = [$resolvedWorkspaceId];
        $conditions = [];
        
        if ($modelType) {
            $conditions[] = "model_type = ?";
            $params[] = $modelType;
        }
        
        if ($activeOnly) {
            $conditions[] = "is_active = TRUE";
        }
        
        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }
        
        $sql .= " ORDER BY trained_at DESC";
        
        return Database::query($sql, $params);
    }
    
    /**
     * Rollback to previous model version
     * 
     * @param string $modelType Model type
     * @return bool Success
     */
    public function rollbackModel(string $modelType = 'conversion', ?int $workspaceId = null): bool
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        // Get current active model
        $currentModel = $this->getActiveModel($modelType, $resolvedWorkspaceId);
        
        if (!$currentModel) {
            throw new \Exception("No active model found for type: {$modelType}");
        }
        
        // Get previous model (second most recent)
        $previousModel = Database::queryOne(
            "SELECT * FROM ml_models 
             WHERE workspace_id = ? AND model_type = ? AND id != ? 
             ORDER BY trained_at DESC 
             LIMIT 1",
            [$resolvedWorkspaceId, $modelType, $currentModel['id']]
        );
        
        if (!$previousModel) {
            throw new \Exception("No previous model version found");
        }
        
        // Promote previous model
        return $this->promoteModel($previousModel['id'], $resolvedWorkspaceId);
    }
    
    /**
     * Store model comparison
     */
    private function storeComparison(int $modelId1, int $modelId2, array $comparison, int $workspaceId): void
    {
        foreach ($comparison['improvements'] as $metric => $improvement) {
            Database::execute(
                "INSERT INTO ml_model_comparisons 
                 (workspace_id, model_id_a, model_id_b, comparison_type, metric_name, 
                  value_a, value_b, improvement, is_significant)
                 VALUES (?, ?, ?, 'performance', ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    $modelId1,
                    $modelId2,
                    $metric,
                    $comparison['model1'][$metric] ?? null,
                    $comparison['model2'][$metric] ?? null,
                    $improvement['absolute'],
                    abs($improvement['percentage']) > 5 // Significant if >5% improvement
                ]
            );
        }
    }
    
    /**
     * Generate version string
     */
    private function generateVersion(string $modelType, string $algorithm): string
    {
        $timestamp = date('YmdHis');
        $random = substr(md5(uniqid()), 0, 6);
        return "{$modelType}_{$algorithm}_{$timestamp}_{$random}";
    }
    
    /**
     * Get model performance history
     * 
     * @param string $modelType Model type
     * @return array Performance metrics over time
     */
    public function getPerformanceHistory(string $modelType = 'conversion', ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        return Database::query(
            "SELECT id, version, trained_at, accuracy, precision_score, 
                   recall_score, f1_score, auc_roc, is_active
             FROM ml_models 
             WHERE workspace_id = ? AND model_type = ?
             ORDER BY trained_at ASC",
            [$resolvedWorkspaceId, $modelType]
        );
    }
    
    /**
     * Delete model (soft delete by deactivating)
     * 
     * @param int $modelId Model ID
     * @return bool Success
     */
    public function deleteModel(int $modelId, ?int $workspaceId = null): bool
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        // Don't allow deletion of active models
        $model = $this->getModel($modelId, $resolvedWorkspaceId);
        if ($model && $model['is_active']) {
            throw new \Exception("Cannot delete active model. Promote another model first.");
        }
        
        Database::execute(
            "DELETE FROM ml_models WHERE workspace_id = ? AND id = ?",
            [$resolvedWorkspaceId, $modelId]
        );
        
        return true;
    }

    private function hydrateModel(?array $model): ?array
    {
        if (!$model) {
            return null;
        }

        $model['feature_list'] = json_decode($model['feature_list'], true) ?: [];
        $model['hyperparameters'] = json_decode($model['hyperparameters'], true) ?: [];

        return $model;
    }
}
