<?php

namespace CRM\Services;

use CRM\Database;

class AIRuntimeControlService
{
    private const VALID_SURFACES = [
        'global',
        'coach',
        'clarity_chat',
        'assistant',
        'customer_thread',
        'commercial_assistant',
        'marketing',
        'workflow',
        'task_automation',
        'autonomous_tuning',
    ];

    private const VALID_MODES = ['normal', 'suggest_only', 'paused', 'diagnostics_only'];
    private const MODE_WEIGHT = [
        'normal' => 0,
        'suggest_only' => 1,
        'diagnostics_only' => 2,
        'paused' => 3,
    ];

    public function listSurfaces(): array
    {
        return self::VALID_SURFACES;
    }

    public function listModes(): array
    {
        return self::VALID_MODES;
    }

    public function getEffectiveControl(string $surface, ?int $workspaceId = null): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($workspaceId <= 0) {
            return $this->buildDefaultControl($surface);
        }

        $this->clearExpiredControls($workspaceId);
        $surface = in_array($surface, self::VALID_SURFACES, true) ? $surface : 'global';
        $global = $this->getActiveControlRow('global', $workspaceId);
        $specific = $surface === 'global' ? null : $this->getActiveControlRow($surface, $workspaceId);

        $effective = $this->resolveStricterControl($global, $specific);
        if (!$effective) {
            return $this->buildDefaultControl($surface, $workspaceId);
        }

        $effective['effective_surface'] = $surface;
        $effective['workspace_id'] = $workspaceId;
        return $effective;
    }

    public function getAllEffectiveControls(?int $workspaceId = null): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($workspaceId > 0) {
            $this->clearExpiredControls($workspaceId);
        }

        $controls = [];
        foreach (self::VALID_SURFACES as $surface) {
            $controls[$surface] = $this->getEffectiveControl($surface, $workspaceId);
        }
        return $controls;
    }

    public function getConfiguredControls(?int $workspaceId = null): array
    {
        if (!$this->tableExists('ai_runtime_controls')) {
            return [];
        }

        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($workspaceId <= 0) {
            return [];
        }

        return Database::query(
            "SELECT *
             FROM ai_runtime_controls
             WHERE workspace_id = ?
             ORDER BY surface ASC",
            [$workspaceId]
        );
    }

    public function setControl(string $surface, string $mode, int $userId, string $reason, ?string $expiresAt = null, array $metadata = [], ?int $workspaceId = null): int
    {
        $this->assertValidSurface($surface);
        $this->assertValidMode($mode);
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace is required for runtime controls.');
        }

        $previous = $this->getActiveControlRow($surface, $workspaceId);
        $previousMode = (string) ($previous['control_mode'] ?? 'normal');
        $controlId = (int) ($previous['id'] ?? 0);

        Database::execute(
            "INSERT INTO ai_runtime_controls (workspace_id, surface, control_mode, reason, set_by, set_at, expires_at, metadata_json)
             VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE
                control_mode = VALUES(control_mode),
                reason = VALUES(reason),
                set_by = VALUES(set_by),
                set_at = NOW(),
                expires_at = VALUES(expires_at),
                metadata_json = VALUES(metadata_json)",
            [
                $workspaceId,
                $surface,
                $mode,
                trim($reason),
                $userId > 0 ? $userId : null,
                $expiresAt ?: null,
                json_encode($metadata),
            ]
        );

        if ($controlId === 0) {
            $row = Database::queryOne(
                "SELECT id FROM ai_runtime_controls WHERE workspace_id = ? AND surface = ?",
                [$workspaceId, $surface]
            );
            $controlId = (int) ($row['id'] ?? 0);
        }

        Database::execute(
            "INSERT INTO ai_runtime_control_log
                (workspace_id, surface, previous_mode, new_mode, reason, set_by, set_at, expires_at, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)",
            [
                $workspaceId,
                $surface,
                $previousMode,
                $mode,
                trim($reason),
                $userId > 0 ? $userId : null,
                $expiresAt ?: null,
                json_encode($metadata),
            ]
        );

        return $controlId;
    }

    public function clearControl(string $surface, int $userId, string $reason = 'Cleared by operator', ?int $workspaceId = null): bool
    {
        $this->assertValidSurface($surface);
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace is required for runtime controls.');
        }

        $previous = $this->getActiveControlRow($surface, $workspaceId);
        if (!$previous) {
            return false;
        }

        Database::execute(
            "DELETE FROM ai_runtime_controls WHERE workspace_id = ? AND surface = ?",
            [$workspaceId, $surface]
        );
        Database::execute(
            "INSERT INTO ai_runtime_control_log
                (workspace_id, surface, previous_mode, new_mode, reason, set_by, set_at, expires_at, metadata_json)
             VALUES (?, ?, ?, 'normal', ?, ?, NOW(), NULL, ?)",
            [
                $workspaceId,
                $surface,
                (string) ($previous['control_mode'] ?? 'normal'),
                trim($reason),
                $userId > 0 ? $userId : null,
                json_encode(['cleared' => true]),
            ]
        );

        return true;
    }

    public function clearExpiredControls(?int $workspaceId = null): int
    {
        if (!$this->tableExists('ai_runtime_controls')) {
            return 0;
        }

        $workspaceId = $workspaceId !== null ? max(0, $workspaceId) : null;
        $params = [];
        $workspaceSql = '';
        if ($workspaceId !== null && $workspaceId > 0) {
            $workspaceSql = ' AND workspace_id = ?';
            $params[] = $workspaceId;
        }

        $expired = Database::query(
            "SELECT * FROM ai_runtime_controls WHERE expires_at IS NOT NULL AND expires_at <= NOW()" . $workspaceSql,
            $params
        );
        if ($expired === []) {
            return 0;
        }

        foreach ($expired as $row) {
            Database::execute(
                "INSERT INTO ai_runtime_control_log
                    (workspace_id, surface, previous_mode, new_mode, reason, set_by, set_at, expires_at, metadata_json)
                 VALUES (?, ?, ?, 'normal', ?, ?, NOW(), NULL, ?)",
                [
                    (int) ($row['workspace_id'] ?? 0),
                    (string) ($row['surface'] ?? 'global'),
                    (string) ($row['control_mode'] ?? 'normal'),
                    'Expired control cleared automatically.',
                    !empty($row['set_by']) ? (int) $row['set_by'] : null,
                    json_encode(['expired' => true]),
                ]
            );
        }

        Database::execute(
            "DELETE FROM ai_runtime_controls WHERE expires_at IS NOT NULL AND expires_at <= NOW()" . $workspaceSql,
            $params
        );
        return count($expired);
    }

    public function isExecutionAllowed(string $surface, ?int $workspaceId = null): bool
    {
        return !in_array($this->getEffectiveControl($surface, $workspaceId)['control_mode'], ['paused', 'diagnostics_only'], true);
    }

    public function shouldForceSuggestOnly(string $surface, ?int $workspaceId = null): bool
    {
        return $this->getEffectiveControl($surface, $workspaceId)['control_mode'] === 'suggest_only';
    }

    public function isDiagnosticsOnly(string $surface, ?int $workspaceId = null): bool
    {
        return $this->getEffectiveControl($surface, $workspaceId)['control_mode'] === 'diagnostics_only';
    }

    public function getRecentLog(int $limit = 25, ?int $workspaceId = null): array
    {
        if (!$this->tableExists('ai_runtime_control_log')) {
            return [];
        }
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($workspaceId <= 0) {
            return [];
        }

        return Database::query(
            "SELECT *
             FROM ai_runtime_control_log
             WHERE workspace_id = ?
             ORDER BY set_at DESC, id DESC
             LIMIT " . max(1, min(100, $limit)),
            [$workspaceId]
        );
    }

    private function getActiveControlRow(string $surface, int $workspaceId): ?array
    {
        if ($workspaceId <= 0 || !$this->tableExists('ai_runtime_controls')) {
            return null;
        }
        $row = Database::queryOne(
            "SELECT * FROM ai_runtime_controls WHERE workspace_id = ? AND surface = ? LIMIT 1",
            [$workspaceId, $surface]
        );
        return $row ?: null;
    }

    private function resolveStricterControl(?array $global, ?array $specific): ?array
    {
        if (!$global) {
            return $specific;
        }
        if (!$specific) {
            return $global;
        }

        $globalMode = (string) ($global['control_mode'] ?? 'normal');
        $specificMode = (string) ($specific['control_mode'] ?? 'normal');
        if ((self::MODE_WEIGHT[$specificMode] ?? 0) >= (self::MODE_WEIGHT[$globalMode] ?? 0)) {
            return $specific;
        }
        return $global;
    }

    private function buildDefaultControl(string $surface, int $workspaceId = 0): array
    {
        return [
            'id' => null,
            'workspace_id' => $workspaceId,
            'surface' => $surface,
            'effective_surface' => $surface,
            'control_mode' => 'normal',
            'reason' => '',
            'set_by' => null,
            'set_at' => null,
            'expires_at' => null,
            'metadata' => [],
        ];
    }

    private function assertValidSurface(string $surface): void
    {
        if (!in_array($surface, self::VALID_SURFACES, true)) {
            throw new \InvalidArgumentException('Invalid runtime control surface.');
        }
    }

    private function assertValidMode(string $mode): void
    {
        if (!in_array($mode, self::VALID_MODES, true)) {
            throw new \InvalidArgumentException('Invalid runtime control mode.');
        }
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

    private function resolveWorkspaceId(?int $workspaceId): int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return $workspaceId;
        }

        try {
            $currentWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
            if ($currentWorkspaceId > 0) {
                return $currentWorkspaceId;
            }
        } catch (\Throwable $e) {
            // Fall through to the default workspace compatibility path.
        }

        return 0;
    }
}
