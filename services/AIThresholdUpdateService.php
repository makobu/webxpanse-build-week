<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\UserPreferences;

class AIThresholdUpdateService
{
    private UserPreferences $preferences;
    private AIRoleProfileService $roleProfiles;
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->preferences = new UserPreferences();
        $this->roleProfiles = new AIRoleProfileService();
        $this->workspaceScope = new AIWorkspaceScopeService();
    }

    public function getCurrentThreshold(string $thresholdKey, ?string $surface = null, ?string $actionType = null, int $userId = 0): ?float
    {
        if ($this->tableExists('ai_threshold_tuning_log')) {
            $workspaceId = $this->workspaceScope->requireWorkspaceId();
            $row = Database::queryOne(
                "SELECT applied_value
                 FROM ai_threshold_tuning_log
                 WHERE workspace_id = ?
                   AND threshold_key = ?
                   AND (surface = ? OR surface = 'global')
                   AND (action_type = ? OR action_type = '*')
                 ORDER BY
                    CASE WHEN surface = ? AND action_type = ? THEN 0
                         WHEN surface = ? AND action_type = '*' THEN 1
                         WHEN surface = 'global' AND action_type = '*' THEN 2
                         ELSE 3 END,
                    created_at DESC, id DESC
                 LIMIT 1",
                [
                    $workspaceId,
                    $thresholdKey,
                    (string) ($surface ?? 'global'),
                    (string) ($actionType ?? '*'),
                    (string) ($surface ?? 'global'),
                    (string) ($actionType ?? '*'),
                    (string) ($surface ?? 'global'),
                ]
            );
            if ($row && $row['applied_value'] !== null) {
                return (float) $row['applied_value'];
            }
        }

        return $this->getFallbackThreshold($thresholdKey, $userId);
    }

    public function applyThreshold(string $thresholdKey, float $value, array $scope = []): bool
    {
        if (!$this->tableExists('ai_threshold_tuning_log')) {
            return false;
        }

        $surface = (string) ($scope['surface'] ?? 'global');
        $actionType = (string) ($scope['action_type'] ?? '*');
        $workspaceId = $this->workspaceScope->requireWorkspaceId(null, isset($scope['workspace_id']) ? (int) $scope['workspace_id'] : null);
        $previous = (float) ($scope['previous_value'] ?? $this->getCurrentThreshold($thresholdKey, $surface, $actionType, (int) ($scope['user_id'] ?? 0)) ?? $value);

        Database::execute(
            "INSERT INTO ai_threshold_tuning_log
                (workspace_id, surface, action_type, threshold_key, scope_type, previous_value, recommended_value, applied_value, sample_size,
                 observed_precision, observed_edit_rate, observed_rejection_rate, observed_reversal_rate, observed_failure_rate,
                 change_reason_summary, calibration_snapshot_json, applied_automatically)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $surface,
                $actionType,
                $thresholdKey,
                (string) ($scope['scope_type'] ?? 'global'),
                $previous,
                (float) ($scope['recommended_value'] ?? $value),
                $value,
                (int) ($scope['sample_size'] ?? 0),
                (float) ($scope['observed_precision'] ?? 0),
                (float) ($scope['observed_edit_rate'] ?? 0),
                (float) ($scope['observed_rejection_rate'] ?? 0),
                (float) ($scope['observed_reversal_rate'] ?? 0),
                (float) ($scope['observed_failure_rate'] ?? 0),
                (string) ($scope['change_reason_summary'] ?? 'manual update'),
                json_encode($scope['calibration_snapshot_json'] ?? []),
                !empty($scope['applied_automatically']) ? 1 : 0,
            ]
        );

        return true;
    }

    public function resolveThresholdTarget(string $surface, string $actionType): array
    {
        if ($surface === 'assistant' && in_array($actionType, ['send_customer_reply', 'send_document'], true)) {
            return ['threshold_key' => 'assistant_customer_send_min_confidence', 'surface' => 'assistant', 'action_type' => $actionType, 'scope_type' => 'surface_action'];
        }
        if ($surface === 'task_automation') {
            return ['threshold_key' => 'ai_auto_task_completion_min_confidence', 'surface' => 'task_automation', 'action_type' => $actionType, 'scope_type' => 'global'];
        }
        if (in_array($surface, ['coach', 'clarity_chat'], true)) {
            return ['threshold_key' => 'ai_advice_min_confidence', 'surface' => $surface, 'action_type' => $actionType, 'scope_type' => 'global'];
        }

        return ['threshold_key' => 'ai_action_min_confidence', 'surface' => $surface, 'action_type' => $actionType, 'scope_type' => 'global'];
    }

    public function rollback(int $tuningLogId): ?array
    {
        if (!$this->tableExists('ai_threshold_tuning_log')) {
            return null;
        }
        $row = Database::queryOne("SELECT * FROM ai_threshold_tuning_log WHERE id = ?", [$tuningLogId]);
        if (!$row) {
            return null;
        }

        $this->applyThreshold((string) $row['threshold_key'], (float) $row['previous_value'], [
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'surface' => (string) ($row['surface'] ?? 'global'),
            'action_type' => (string) ($row['action_type'] ?? '*'),
            'scope_type' => (string) ($row['scope_type'] ?? 'global'),
            'previous_value' => (float) ($row['applied_value'] ?? 0),
            'recommended_value' => (float) ($row['previous_value'] ?? 0),
            'sample_size' => 0,
            'observed_precision' => 0,
            'observed_edit_rate' => 0,
            'observed_rejection_rate' => 0,
            'observed_reversal_rate' => 0,
            'observed_failure_rate' => 0,
            'change_reason_summary' => 'rollback',
            'calibration_snapshot_json' => ['rollback_of' => $tuningLogId],
            'applied_automatically' => false,
        ]);

        return Database::queryOne("SELECT * FROM ai_threshold_tuning_log ORDER BY id DESC LIMIT 1");
    }

    public function getRoleAwareThresholdRecommendation(string $surface, string $actionType, int $userId = 0, array $roleProfile = []): array
    {
        $target = $this->resolveThresholdTarget($surface, $actionType);
        $thresholdKey = (string) ($target['threshold_key'] ?? 'ai_action_min_confidence');
        $currentThreshold = $this->getCurrentThreshold(
            $thresholdKey,
            (string) ($target['surface'] ?? $surface),
            (string) ($target['action_type'] ?? $actionType),
            $userId
        );

        if ($roleProfile === [] && $userId > 0) {
            $roleProfile = $this->roleProfiles->buildProfile($userId);
        }

        $roleName = (string) ($roleProfile['role_profile'] ?? 'sales_rep');
        $thresholdPosture = (string) ($roleProfile['threshold_posture'] ?? 'balanced');
        $baseline = $this->resolveBaselineThreshold($thresholdKey, $currentThreshold);
        $recommended = $baseline + $this->resolveRoleAdjustment($thresholdKey, $surface, $actionType, $roleName, $thresholdPosture);
        $recommended = round(max(0.50, min(0.99, $recommended)), 2);
        $current = round((float) ($currentThreshold ?? $baseline), 2);

        return [
            'threshold_key' => $thresholdKey,
            'surface' => (string) ($target['surface'] ?? $surface),
            'action_type' => (string) ($target['action_type'] ?? $actionType),
            'current_threshold' => $current,
            'recommended_threshold' => $recommended,
            'delta' => round($recommended - $current, 2),
            'threshold_posture' => $thresholdPosture,
            'differs' => abs($recommended - $current) >= 0.01,
            'rationale' => $this->buildThresholdRationale($roleName, $surface, $actionType, $thresholdPosture),
            'role_profile' => $roleName,
        ];
    }

    private function getFallbackThreshold(string $thresholdKey, int $userId): ?float
    {
        return match ($thresholdKey) {
            'ai_advice_min_confidence' => $this->preferences->getAIAdviceMinConfidence($userId) ?? 0.88,
            'ai_action_min_confidence', 'assistant_customer_send_min_confidence' => $this->preferences->getAIActionMinConfidence($userId) ?? 0.92,
            'ai_goal_relevance_min_score' => $this->preferences->getAIGoalRelevanceMinScore($userId) ?? 0.70,
            'ai_auto_task_completion_min_confidence' => $this->preferences->getAIAutoTaskCompletionMinConfidence($userId) ?? 0.95,
            default => null,
        };
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resolveBaselineThreshold(string $thresholdKey, ?float $currentThreshold): float
    {
        if ($currentThreshold !== null) {
            return (float) $currentThreshold;
        }

        return match ($thresholdKey) {
            'ai_advice_min_confidence' => 0.88,
            'ai_action_min_confidence', 'assistant_customer_send_min_confidence' => 0.92,
            'ai_goal_relevance_min_score' => 0.70,
            'ai_auto_task_completion_min_confidence' => 0.95,
            default => 0.90,
        };
    }

    private function resolveRoleAdjustment(string $thresholdKey, string $surface, string $actionType, string $roleProfile, string $thresholdPosture): float
    {
        $adjustment = match ($thresholdPosture) {
            'aggressive' => -0.02,
            'conservative' => 0.02,
            default => 0.0,
        };

        if ($thresholdKey === 'ai_advice_min_confidence') {
            $adjustment += match ($roleProfile) {
                'founder' => $surface === 'coach' ? -0.01 : 0.0,
                'ops_admin', 'support_operator' => 0.01,
                default => -0.01,
            };
        }

        if ($thresholdKey === 'ai_action_min_confidence' || $thresholdKey === 'assistant_customer_send_min_confidence') {
            $adjustment += match ($roleProfile) {
                'ops_admin', 'support_operator' => 0.01,
                'sales_rep' => str_contains($actionType, 'reply') ? -0.01 : 0.0,
                default => 0.0,
            };
        }

        if ($thresholdKey === 'ai_auto_task_completion_min_confidence' && $roleProfile === 'ops_admin') {
            $adjustment += 0.01;
        }

        return $adjustment;
    }

    private function buildThresholdRationale(string $roleProfile, string $surface, string $actionType, string $thresholdPosture): string
    {
        return sprintf(
            'Recommended threshold for %s on %s/%s reflects a %s posture for this role.',
            $roleProfile,
            $surface,
            $actionType,
            $thresholdPosture
        );
    }
}
