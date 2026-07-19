<?php
/**
 * ML Outcome Tracker
 * Tracks actual outcomes (conversions, churn) to improve models
 */

namespace CRM\Services;

use CRM\Database;

class MLOutcomeTracker
{
    private AnalyticsWorkspaceService $analyticsWorkspace;

    public function __construct(?AnalyticsWorkspaceService $analyticsWorkspace = null)
    {
        $this->analyticsWorkspace = $analyticsWorkspace ?? new AnalyticsWorkspaceService();
    }

    /**
     * Record conversion outcome
     * 
     * @param int $contactId Contact ID
     * @param array $context Additional context (deal_value, conversion_date, etc.)
     */
    public function recordConversion(int $contactId, array $context = []): void
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $this->assertWorkspaceScopedContact($contactId, $workspaceId);

        // Get latest prediction for this contact
        $prediction = Database::queryOne(
            "SELECT p.*, m.model_type
             FROM ml_predictions p
             JOIN ml_models m ON p.model_id = m.id
             WHERE p.workspace_id = ?
               AND p.contact_id = ?
               AND m.workspace_id = ?
               AND m.model_type = 'conversion'
             ORDER BY p.cached_at DESC
             LIMIT 1",
            [$workspaceId, $contactId, $workspaceId]
        );
        
        if ($prediction) {
            // Record outcome
            Database::execute(
                "INSERT INTO ml_prediction_outcomes
                 (workspace_id, prediction_id, contact_id, model_id, predicted_probability, 
                  actual_outcome, outcome_type, outcome_date, outcome_value, prediction_error)
                 VALUES (?, ?, ?, ?, ?, ?, 'conversion', ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                 actual_outcome = VALUES(actual_outcome),
                 outcome_date = VALUES(outcome_date),
                 outcome_value = VALUES(outcome_value),
                 prediction_error = VALUES(prediction_error),
                 validated_at = CURRENT_TIMESTAMP",
                [
                    $workspaceId,
                    $prediction['id'],
                    $contactId,
                    $prediction['model_id'],
                    $prediction['probability'],
                    1, // Converted
                    $context['conversion_date'] ?? date('Y-m-d'),
                    $context['deal_value'] ?? null,
                    abs(1 - $prediction['probability']) // Prediction error
                ]
            );
        }
        
        // Update contact stage and retain the durable journey transition.
        $previousStage = (string) (Database::queryOne(
            'SELECT stage FROM contacts WHERE workspace_id = ? AND id = ? LIMIT 1',
            [$workspaceId, $contactId]
        )['stage'] ?? '');
        Database::execute(
            "UPDATE contacts SET stage = 'won' WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        );
        (new ContactStageHistoryService())->record($workspaceId, $contactId, $previousStage, 'won', null, 'ml_outcome');
    }
    
    /**
     * Record churn outcome
     * 
     * @param int $contactId Contact ID
     * @param array $context Additional context (churn_date, reason, etc.)
     */
    public function recordChurn(int $contactId, array $context = []): void
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $this->assertWorkspaceScopedContact($contactId, $workspaceId);

        // Get latest churn prediction for this contact
        $prediction = Database::queryOne(
            "SELECT p.*, m.model_type
             FROM ml_predictions p
             JOIN ml_models m ON p.model_id = m.id
             WHERE p.workspace_id = ?
               AND p.contact_id = ?
               AND m.workspace_id = ?
               AND m.model_type = 'churn'
             ORDER BY p.cached_at DESC
             LIMIT 1",
            [$workspaceId, $contactId, $workspaceId]
        );
        
        if ($prediction) {
            // Record outcome
            Database::execute(
                "INSERT INTO ml_prediction_outcomes
                 (workspace_id, prediction_id, contact_id, model_id, predicted_probability, 
                  actual_outcome, outcome_type, outcome_date, prediction_error)
                 VALUES (?, ?, ?, ?, ?, ?, 'churn', ?, ?)
                 ON DUPLICATE KEY UPDATE
                 actual_outcome = VALUES(actual_outcome),
                 outcome_date = VALUES(outcome_date),
                 prediction_error = VALUES(prediction_error),
                 validated_at = CURRENT_TIMESTAMP",
                [
                    $workspaceId,
                    $prediction['id'],
                    $contactId,
                    $prediction['model_id'],
                    $prediction['probability'],
                    1, // Churned
                    $context['churn_date'] ?? date('Y-m-d'),
                    abs(1 - $prediction['probability']) // Prediction error
                ]
            );
        }
        
        // Update contact (could add churned flag or stage)
        $previousStage = (string) (Database::queryOne(
            'SELECT stage FROM contacts WHERE workspace_id = ? AND id = ? LIMIT 1',
            [$workspaceId, $contactId]
        )['stage'] ?? '');
        Database::execute(
            "UPDATE contacts SET stage = 'lost' WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        );
        (new ContactStageHistoryService())->record($workspaceId, $contactId, $previousStage, 'lost', null, 'ml_outcome');
    }
    
    /**
     * Validate predictions against actual outcomes
     * 
     * @param string|null $modelType Filter by model type
     * @param int $daysBack Number of days to look back
     * @return array Validation results
     */
    public function validatePredictions(?string $modelType = null, int $daysBack = 90): array
    {
        $cutoffDate = date('Y-m-d', strtotime("-{$daysBack} days"));
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();

        $sql = "SELECT 
                    o.*,
                    o.predicted_probability,
                    p.probability as cached_probability,
                    m.model_type,
                    m.version as model_version
                FROM ml_prediction_outcomes o
                JOIN ml_predictions p ON o.prediction_id = p.id
                JOIN ml_models m ON o.model_id = m.id
                JOIN contacts c ON c.id = o.contact_id
                WHERE o.outcome_date >= ?
                  AND o.workspace_id = ?
                  AND c.workspace_id = ?";

        $params = [$cutoffDate, $workspaceId, $workspaceId];
        
        if ($modelType) {
            $sql .= " AND m.model_type = ?";
            $params[] = $modelType;
        }
        
        $outcomes = Database::query($sql, $params);
        
        if (empty($outcomes)) {
            return [
                'total_validated' => 0,
                'accuracy' => 0,
                'mae' => 0,
                'rmse' => 0
            ];
        }
        
        // Calculate metrics
        $totalValidated = count($outcomes);
        $correctPredictions = 0;
        $errors = [];
        
        foreach ($outcomes as $outcome) {
            $predicted = (float) $outcome['predicted_probability'];
            $actual = (int) $outcome['actual_outcome'];
            
            // For binary classification, consider prediction correct if:
            // - Predicted > 0.5 and actual = 1, OR
            // - Predicted <= 0.5 and actual = 0
            $predictedBinary = $predicted > 0.5 ? 1 : 0;
            
            if ($predictedBinary === $actual) {
                $correctPredictions++;
            }
            
            // Calculate error
            $error = abs($predicted - $actual);
            $errors[] = $error;
        }
        
        $accuracy = $totalValidated > 0 ? ($correctPredictions / $totalValidated) : 0;
        
        // Calculate MAE (Mean Absolute Error) and RMSE (Root Mean Squared Error)
        $mae = count($errors) > 0 ? (array_sum($errors) / count($errors)) : 0;
        $rmse = count($errors) > 0 ? sqrt(array_sum(array_map(fn($e) => $e * $e, $errors)) / count($errors)) : 0;
        
        return [
            'total_validated' => $totalValidated,
            'correct_predictions' => $correctPredictions,
            'accuracy' => round($accuracy, 4),
            'mae' => round($mae, 4),
            'rmse' => round($rmse, 4),
            'by_model_type' => $this->groupByModelType($outcomes)
        ];
    }
    
    /**
     * Get prediction accuracy metrics
     * 
     * @param string|null $modelType Filter by model type
     * @return array Accuracy metrics
     */
    public function getPredictionAccuracy(?string $modelType = null): array
    {
        $validation = $this->validatePredictions($modelType);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();

        // Get accuracy over time
        $accuracyOverTime = Database::query(
            "SELECT 
                DATE(o.validated_at) as date,
                COUNT(*) as total,
                COUNT(CASE WHEN ABS(o.predicted_probability - o.actual_outcome) < 0.3 THEN 1 END) as accurate,
                AVG(ABS(o.predicted_probability - o.actual_outcome)) as avg_error
             FROM ml_prediction_outcomes o
             JOIN ml_models m ON o.model_id = m.id
             JOIN contacts c ON c.id = o.contact_id
             WHERE o.validated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             AND o.workspace_id = ?
             AND c.workspace_id = ?
             " . ($modelType ? "AND m.model_type = ?" : "") . "
             GROUP BY DATE(o.validated_at)
             ORDER BY date DESC",
            $modelType ? [$workspaceId, $workspaceId, $modelType] : [$workspaceId, $workspaceId]
        );
        
        return [
            'overall' => $validation,
            'accuracy_over_time' => $accuracyOverTime,
            'recent_accuracy' => !empty($accuracyOverTime) ? round($accuracyOverTime[0]['accurate'] / max(1, $accuracyOverTime[0]['total']), 4) : 0
        ];
    }
    
    /**
     * Group validation results by model type
     */
    private function groupByModelType(array $outcomes): array
    {
        $grouped = [];
        
        foreach ($outcomes as $outcome) {
            $type = $outcome['model_type'];
            
            if (!isset($grouped[$type])) {
                $grouped[$type] = [
                    'total' => 0,
                    'correct' => 0,
                    'errors' => []
                ];
            }
            
            $grouped[$type]['total']++;
            
            $predicted = (float) $outcome['predicted_probability'];
            $actual = (int) $outcome['actual_outcome'];
            $predictedBinary = $predicted > 0.5 ? 1 : 0;
            
            if ($predictedBinary === $actual) {
                $grouped[$type]['correct']++;
            }
            
            $grouped[$type]['errors'][] = abs($predicted - $actual);
        }
        
        // Calculate metrics for each type
        foreach ($grouped as $type => &$data) {
            $data['accuracy'] = $data['total'] > 0 ? round($data['correct'] / $data['total'], 4) : 0;
            $data['mae'] = count($data['errors']) > 0 ? round(array_sum($data['errors']) / count($data['errors']), 4) : 0;
            unset($data['errors']);
        }
        
        return $grouped;
    }
    
    /**
     * Auto-detect and record conversions from deals
     */
    public function autoDetectConversions(): void
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        // Find contacts that converted (won deals) but don't have outcome recorded
        $conversions = Database::query(
            "SELECT DISTINCT c.id as contact_id, 
                    MAX(d.updated_at) as conversion_date,
                    SUM(d.value) as total_value
             FROM contacts c
             JOIN deals d ON c.id = d.contact_id
             WHERE c.workspace_id = ?
             AND d.workspace_id = ?
             AND d.stage = 'closed_won'
             AND c.id NOT IN (
                 SELECT DISTINCT contact_id 
                 FROM ml_prediction_outcomes 
                 WHERE workspace_id = ? AND outcome_type = 'conversion' AND actual_outcome = 1
             )
             GROUP BY c.id",
            [$workspaceId, $workspaceId, $workspaceId]
        );
        
        foreach ($conversions as $conversion) {
            $this->recordConversion($conversion['contact_id'], [
                'conversion_date' => date('Y-m-d', strtotime($conversion['conversion_date'])),
                'deal_value' => (float) $conversion['total_value']
            ]);
        }
    }
    
    /**
     * Auto-detect churn (no activity for 60+ days)
     */
    public function autoDetectChurn(): void
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        // Find contacts with no activity for 60+ days
        $churned = Database::query(
            "SELECT c.id as contact_id,
                    MAX(a.created_at) as last_activity_date
             FROM contacts c
             LEFT JOIN activities a ON c.id = a.contact_id
             WHERE c.workspace_id = ?
             AND (a.id IS NULL OR a.workspace_id = ?)
             AND c.stage NOT IN ('won', 'lost')
             AND (a.created_at IS NULL OR a.created_at < DATE_SUB(NOW(), INTERVAL 60 DAY))
             AND c.id NOT IN (
                 SELECT DISTINCT contact_id 
                 FROM ml_prediction_outcomes 
                 WHERE workspace_id = ? AND outcome_type = 'churn' AND actual_outcome = 1
             )
             GROUP BY c.id
             HAVING MAX(a.created_at) < DATE_SUB(NOW(), INTERVAL 60 DAY)
             OR MAX(a.created_at) IS NULL",
            [$workspaceId, $workspaceId, $workspaceId]
        );
        
        foreach ($churned as $churn) {
            $this->recordChurn($churn['contact_id'], [
                'churn_date' => date('Y-m-d', strtotime($churn['last_activity_date'] ?? 'now')),
                'reason' => 'inactivity'
            ]);
        }
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
