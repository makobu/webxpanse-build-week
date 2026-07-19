<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceTaskAutomationSettingsService
{
    public const DEFAULT_MIN_CONFIDENCE = 0.96;

    /** @return array{workspace_id:int,rollout_mode:string,allow_user_task_opt_in:bool,min_confidence:float,low_risk_only:bool} */
    public function get(int $workspaceId): array
    {
        $defaults = [
            'workspace_id' => max(0, $workspaceId),
            'rollout_mode' => 'review',
            'allow_user_task_opt_in' => true,
            'min_confidence' => self::DEFAULT_MIN_CONFIDENCE,
            'low_risk_only' => true,
        ];
        if ($workspaceId <= 0 || !Database::tableExists('workspace_task_automation_settings')) {
            return $defaults;
        }

        $row = Database::queryOne(
            'SELECT * FROM workspace_task_automation_settings WHERE workspace_id = ? LIMIT 1',
            [$workspaceId]
        );
        if (!$row) {
            Database::execute(
                "INSERT IGNORE INTO workspace_task_automation_settings
                    (workspace_id, rollout_mode, allow_user_task_opt_in, min_confidence, low_risk_only)
                 VALUES (?, 'review', 1, ?, 1)",
                [$workspaceId, self::DEFAULT_MIN_CONFIDENCE]
            );
            return $defaults;
        }

        return [
            'workspace_id' => $workspaceId,
            'rollout_mode' => in_array((string) ($row['rollout_mode'] ?? ''), ['review', 'full_auto'], true)
                ? (string) $row['rollout_mode']
                : 'review',
            'allow_user_task_opt_in' => !empty($row['allow_user_task_opt_in']),
            'min_confidence' => max(0.0, min(1.0, (float) ($row['min_confidence'] ?? self::DEFAULT_MIN_CONFIDENCE))),
            'low_risk_only' => !empty($row['low_risk_only']),
        ];
    }

    public function save(int $workspaceId, array $data, int $actorUserId): array
    {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('A workspace is required for task automation settings.');
        }
        $rolloutMode = in_array((string) ($data['rollout_mode'] ?? ''), ['review', 'full_auto'], true)
            ? (string) $data['rollout_mode']
            : 'review';
        $allowOptIn = !empty($data['allow_user_task_opt_in']) ? 1 : 0;
        $minConfidence = max(0.90, min(0.9999, (float) ($data['min_confidence'] ?? self::DEFAULT_MIN_CONFIDENCE)));
        $lowRiskOnly = array_key_exists('low_risk_only', $data) ? (!empty($data['low_risk_only']) ? 1 : 0) : 1;

        Database::execute(
            "INSERT INTO workspace_task_automation_settings
                (workspace_id, rollout_mode, allow_user_task_opt_in, min_confidence, low_risk_only, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                rollout_mode = VALUES(rollout_mode),
                allow_user_task_opt_in = VALUES(allow_user_task_opt_in),
                min_confidence = VALUES(min_confidence),
                low_risk_only = VALUES(low_risk_only),
                updated_by = VALUES(updated_by)",
            [$workspaceId, $rolloutMode, $allowOptIn, $minConfidence, $lowRiskOnly, $actorUserId ?: null]
        );

        return $this->get($workspaceId);
    }
}
