<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceTargetAutomationSettingsService
{
    public const DEFAULT_CONFIDENCE = 0.96;

    public function get(int $workspaceId): array
    {
        $defaults = [
            'workspace_id' => max(0, $workspaceId),
            'mode' => 'review',
            'allow_user_target_opt_in' => true,
            'confidence_threshold' => self::DEFAULT_CONFIDENCE,
            'low_risk_only' => true,
            'allow_ai_creation' => true,
            'allow_ai_revision' => true,
            'allow_ai_completion' => true,
            'allow_automated_reminders' => true,
            'allow_ai_advice' => true,
        ];
        if ($workspaceId <= 0 || !Database::tableExists('workspace_target_automation_settings')) {
            return $defaults;
        }
        $row = Database::queryOne('SELECT * FROM workspace_target_automation_settings WHERE workspace_id = ? LIMIT 1', [$workspaceId]);
        if (!$row) {
            Database::execute('INSERT IGNORE INTO workspace_target_automation_settings (workspace_id) VALUES (?)', [$workspaceId]);
            return $defaults;
        }
        foreach (['allow_user_target_opt_in', 'low_risk_only', 'allow_ai_creation', 'allow_ai_revision', 'allow_ai_completion', 'allow_automated_reminders', 'allow_ai_advice'] as $flag) {
            $defaults[$flag] = !empty($row[$flag]);
        }
        $defaults['mode'] = in_array((string) ($row['mode'] ?? ''), ['off', 'review', 'full_auto'], true) ? (string) $row['mode'] : 'review';
        $defaults['confidence_threshold'] = max(0.0, min(1.0, (float) ($row['confidence_threshold'] ?? self::DEFAULT_CONFIDENCE)));
        return $defaults;
    }

    public function save(int $workspaceId, array $data, int $actorUserId): array
    {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('A workspace is required.');
        }
        $mode = (string) ($data['mode'] ?? 'review');
        if (!in_array($mode, ['off', 'review', 'full_auto'], true)) {
            throw new \InvalidArgumentException('Invalid target automation mode.');
        }
        $confidence = max(0.90, min(0.9999, (float) ($data['confidence_threshold'] ?? self::DEFAULT_CONFIDENCE)));
        $flags = [];
        foreach (['allow_user_target_opt_in', 'low_risk_only', 'allow_ai_creation', 'allow_ai_revision', 'allow_ai_completion', 'allow_automated_reminders', 'allow_ai_advice'] as $flag) {
            $flags[$flag] = array_key_exists($flag, $data) ? (!empty($data[$flag]) ? 1 : 0) : 1;
        }
        Database::execute(
            "INSERT INTO workspace_target_automation_settings
                (workspace_id, mode, allow_user_target_opt_in, confidence_threshold, low_risk_only,
                 allow_ai_creation, allow_ai_revision, allow_ai_completion, allow_automated_reminders, allow_ai_advice, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mode=VALUES(mode), allow_user_target_opt_in=VALUES(allow_user_target_opt_in),
                 confidence_threshold=VALUES(confidence_threshold), low_risk_only=VALUES(low_risk_only),
                 allow_ai_creation=VALUES(allow_ai_creation), allow_ai_revision=VALUES(allow_ai_revision),
                 allow_ai_completion=VALUES(allow_ai_completion), allow_automated_reminders=VALUES(allow_automated_reminders),
                 allow_ai_advice=VALUES(allow_ai_advice), updated_by=VALUES(updated_by)",
            [$workspaceId, $mode, $flags['allow_user_target_opt_in'], $confidence, $flags['low_risk_only'],
             $flags['allow_ai_creation'], $flags['allow_ai_revision'], $flags['allow_ai_completion'],
             $flags['allow_automated_reminders'], $flags['allow_ai_advice'], $actorUserId ?: null]
        );
        return $this->get($workspaceId);
    }
}
