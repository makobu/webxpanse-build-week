<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceAutoAdminSettingsService
{
    public const DEFAULTS_VERSION = 3;

    private const MANAGED_TABS = [
        'ai',
        'ai_autoresponder',
        'commercial_automation',
        'deal_automation',
        'workflow_automation',
    ];

    private const TARGET_MODES = [
        'deal_automation' => 'suggest_only',
        'workflow_automation' => 'auto_safe',
        'ai_autoresponder' => 'draft_only',
        'commercial_automation' => 'auto_safe',
    ];

    /**
     * @return array<string,mixed>
     */
    public function getSettings(int $workspaceId): array
    {
        $workspaceId = max(0, $workspaceId);
        if ($workspaceId <= 0 || !Database::tableExists('workspace_auto_admin_settings')) {
            return $this->defaultSettings($workspaceId);
        }

        $this->ensureRow($workspaceId);
        $row = Database::queryOne(
            "SELECT *
             FROM workspace_auto_admin_settings
             WHERE workspace_id = ?
             LIMIT 1",
            [$workspaceId]
        );

        return $row ? $this->hydrateRow($row) : $this->defaultSettings($workspaceId);
    }

    public function isEnabled(int $workspaceId): bool
    {
        return !empty($this->getSettings($workspaceId)['enabled']);
    }

    public function setEnabled(int $workspaceId, bool $enabled, int $actorUserId = 0, string $reason = ''): array
    {
        $workspaceId = max(0, $workspaceId);
        if ($workspaceId <= 0) {
            return $this->defaultSettings(0);
        }

        $before = $this->getSettings($workspaceId);

        if (Database::tableExists('workspace_auto_admin_settings')) {
            Database::execute(
                "INSERT INTO workspace_auto_admin_settings (
                    workspace_id, enabled, managed_tabs_json, target_modes_json, effective_modes_json,
                    readiness_snapshot_json, managed_defaults_version, updated_by_user_id
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    managed_tabs_json = COALESCE(workspace_auto_admin_settings.managed_tabs_json, VALUES(managed_tabs_json)),
                    target_modes_json = COALESCE(workspace_auto_admin_settings.target_modes_json, VALUES(target_modes_json)),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    updated_at = CURRENT_TIMESTAMP",
                [
                    $workspaceId,
                    $enabled ? 1 : 0,
                    json_encode(self::MANAGED_TABS),
                    json_encode(self::TARGET_MODES),
                    json_encode([]),
                    json_encode([]),
                    self::DEFAULTS_VERSION,
                    $actorUserId > 0 ? $actorUserId : null,
                ]
            );
        }

        $after = $this->getSettings($workspaceId);
        $this->recordEvent(
            $workspaceId,
            $enabled ? 'enabled' : 'disabled',
            $before,
            $after,
            $reason ?: ($enabled ? 'Workspace Auto Admin enabled.' : 'Workspace Auto Admin disabled.'),
            $actorUserId
        );

        return $after;
    }

    public function setFreeze(int $workspaceId, bool $manualFreeze, string $freezeReason = '', int $actorUserId = 0): array
    {
        $workspaceId = max(0, $workspaceId);
        if ($workspaceId <= 0) {
            return $this->defaultSettings(0);
        }

        $before = $this->getSettings($workspaceId);
        if (Database::tableExists('workspace_auto_admin_settings')) {
            Database::execute(
                "UPDATE workspace_auto_admin_settings
                 SET manual_freeze = ?,
                     freeze_reason = ?,
                     updated_by_user_id = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE workspace_id = ?",
                [
                    $manualFreeze ? 1 : 0,
                    $manualFreeze && trim($freezeReason) !== '' ? substr(trim($freezeReason), 0, 500) : null,
                    $actorUserId > 0 ? $actorUserId : null,
                    $workspaceId,
                ]
            );
        }

        $after = $this->getSettings($workspaceId);
        $this->recordEvent(
            $workspaceId,
            'freeze_changed',
            $before,
            $after,
            $manualFreeze ? ((string) ($after['freeze_reason'] ?? 'Workspace Auto Admin frozen.')) : 'Workspace Auto Admin freeze cleared.',
            $actorUserId
        );

        return $after;
    }

    /**
     * @param array<string,mixed> $targetModes
     * @return array<string,mixed>
     */
    public function setTargetModes(int $workspaceId, array $targetModes, int $actorUserId = 0, string $reason = ''): array
    {
        $workspaceId = max(0, $workspaceId);
        if ($workspaceId <= 0) {
            return $this->defaultSettings(0);
        }

        $before = $this->getSettings($workspaceId);
        $normalized = $this->normalizeTargetModes(array_merge(
            (array) ($before['target_modes'] ?? self::TARGET_MODES),
            $targetModes
        ));

        if (Database::tableExists('workspace_auto_admin_settings')) {
            Database::execute(
                "UPDATE workspace_auto_admin_settings
                 SET target_modes_json = ?,
                     updated_by_user_id = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE workspace_id = ?",
                [
                    json_encode($normalized),
                    $actorUserId > 0 ? $actorUserId : null,
                    $workspaceId,
                ]
            );
        }

        $after = $this->getSettings($workspaceId);
        $this->recordEvent(
            $workspaceId,
            'target_modes_updated',
            $before,
            $after,
            $reason !== '' ? $reason : 'Workspace Auto Admin target modes updated.',
            $actorUserId
        );

        return $after;
    }

    /**
     * @param array<string,mixed> $effectiveModes
     * @param array<string,mixed> $readinessSnapshot
     */
    public function recordEvaluation(
        int $workspaceId,
        array $effectiveModes,
        array $readinessSnapshot,
        int $actorUserId = 0,
        bool $applied = false,
        string $reason = ''
    ): array {
        $workspaceId = max(0, $workspaceId);
        $before = $this->getSettings($workspaceId);
        if ($workspaceId <= 0 || !Database::tableExists('workspace_auto_admin_settings')) {
            return $before;
        }

        Database::execute(
            "UPDATE workspace_auto_admin_settings
             SET effective_modes_json = ?,
                 readiness_snapshot_json = ?,
                 last_evaluated_at = NOW(),
                 last_applied_at = CASE WHEN ? = 1 THEN NOW() ELSE last_applied_at END,
                 managed_defaults_version = ?,
                 updated_by_user_id = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE workspace_id = ?",
            [
                json_encode($effectiveModes),
                json_encode($readinessSnapshot),
                $applied ? 1 : 0,
                self::DEFAULTS_VERSION,
                $actorUserId > 0 ? $actorUserId : null,
                $workspaceId,
            ]
        );

        $after = $this->getSettings($workspaceId);
        $this->recordEvent(
            $workspaceId,
            $applied ? 'defaults_applied' : 'evaluated',
            $before,
            $after,
            $reason ?: ($applied ? 'Workspace Auto Admin defaults applied.' : 'Workspace Auto Admin evaluated.'),
            $actorUserId
        );

        return $after;
    }

    public function recordSkippedFrozen(int $workspaceId, int $actorUserId = 0): void
    {
        $settings = $this->getSettings($workspaceId);
        $this->recordEvent(
            $workspaceId,
            'skipped_frozen',
            $settings,
            $settings,
            (string) ($settings['freeze_reason'] ?? 'Workspace Auto Admin is manually frozen.'),
            $actorUserId
        );
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public function recordRuntimeBlocked(int $workspaceId, array $before, array $after, string $reason, int $actorUserId = 0): void
    {
        $this->recordEvent($workspaceId, 'runtime_blocked', $before, $after, $reason, $actorUserId);
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public function recordPromoted(int $workspaceId, array $before, array $after, string $reason, int $actorUserId = 0): void
    {
        $this->recordEvent($workspaceId, 'promoted', $before, $after, $reason, $actorUserId);
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public function recordDowngraded(int $workspaceId, array $before, array $after, string $reason, int $actorUserId = 0): void
    {
        $this->recordEvent($workspaceId, 'downgraded', $before, $after, $reason, $actorUserId);
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function recordEvent(
        int $workspaceId,
        string $eventType,
        ?array $before = null,
        ?array $after = null,
        string $reason = '',
        int $actorUserId = 0
    ): void {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_auto_admin_events')) {
            return;
        }

        Database::execute(
            "INSERT INTO workspace_auto_admin_events
                (workspace_id, event_type, before_json, after_json, reason, actor_user_id)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                substr(trim($eventType), 0, 64),
                $before !== null ? json_encode($before) : null,
                $after !== null ? json_encode($after) : null,
                trim($reason) !== '' ? substr(trim($reason), 0, 500) : null,
                $actorUserId > 0 ? $actorUserId : null,
            ]
        );
    }

    private function ensureRow(int $workspaceId): void
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_auto_admin_settings')) {
            return;
        }

        $platformEnabled = (new PlatformAutoAdminSettingsService())->isEnabled();
        Database::execute(
            "INSERT INTO workspace_auto_admin_settings (
                workspace_id, enabled, managed_tabs_json, target_modes_json, effective_modes_json,
                readiness_snapshot_json, managed_defaults_version
             ) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE workspace_id = VALUES(workspace_id)",
            [
                $workspaceId,
                $platformEnabled ? 1 : 0,
                json_encode(self::MANAGED_TABS),
                json_encode(self::TARGET_MODES),
                json_encode([]),
                json_encode([]),
                self::DEFAULTS_VERSION,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function hydrateRow(array $row): array
    {
        return [
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'enabled' => !empty($row['enabled']),
            'manual_freeze' => !empty($row['manual_freeze']),
            'freeze_reason' => (string) ($row['freeze_reason'] ?? ''),
            'managed_tabs' => $this->decodeArray($row['managed_tabs_json'] ?? null, self::MANAGED_TABS),
            'target_modes' => $this->normalizeTargetModes($this->decodeArray($row['target_modes_json'] ?? null, self::TARGET_MODES)),
            'effective_modes' => $this->decodeArray($row['effective_modes_json'] ?? null, []),
            'readiness_snapshot' => $this->decodeArray($row['readiness_snapshot_json'] ?? null, []),
            'managed_defaults_version' => (int) ($row['managed_defaults_version'] ?? self::DEFAULTS_VERSION),
            'last_evaluated_at' => $row['last_evaluated_at'] ?? null,
            'last_applied_at' => $row['last_applied_at'] ?? null,
            'updated_by_user_id' => !empty($row['updated_by_user_id']) ? (int) $row['updated_by_user_id'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultSettings(int $workspaceId): array
    {
        $platformEnabled = false;
        try {
            $platformEnabled = (new PlatformAutoAdminSettingsService())->isEnabled();
        } catch (\Throwable $e) {
            $platformEnabled = false;
        }

        return [
            'workspace_id' => max(0, $workspaceId),
            'enabled' => $workspaceId > 0 && $platformEnabled,
            'manual_freeze' => false,
            'freeze_reason' => '',
            'managed_tabs' => self::MANAGED_TABS,
            'target_modes' => self::TARGET_MODES,
            'effective_modes' => [],
            'readiness_snapshot' => [],
            'managed_defaults_version' => self::DEFAULTS_VERSION,
            'last_evaluated_at' => null,
            'last_applied_at' => null,
            'updated_by_user_id' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /**
     * @param mixed $value
     * @param array<int|string,mixed> $fallback
     * @return array<int|string,mixed>
     */
    private function decodeArray(mixed $value, array $fallback): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    /**
     * @param array<string,mixed> $targetModes
     * @return array<string,string>
     */
    private function normalizeTargetModes(array $targetModes): array
    {
        $normalized = self::TARGET_MODES;
        $allowed = [
            'deal_automation' => ['suggest_only', 'auto_safe', 'full_auto'],
            'workflow_automation' => ['suggest_only', 'auto_safe', 'full_auto'],
            'ai_autoresponder' => ['off', 'draft_only', 'hybrid', 'full_auto'],
            'commercial_automation' => ['suggest_only', 'auto_safe', 'full_auto'],
        ];

        foreach ($allowed as $domain => $domainAllowedModes) {
            $mode = strtolower(trim((string) ($targetModes[$domain] ?? $normalized[$domain] ?? '')));
            if (in_array($mode, $domainAllowedModes, true)) {
                $normalized[$domain] = $mode;
            }
        }

        return $normalized;
    }
}
