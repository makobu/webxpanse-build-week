<?php

namespace CRM\Services;

use CRM\Database;

class AICoachRecommendationControlService
{
    private const CONTROL_SCOPES = ['feedback_signature', 'source_type', 'source_section'];
    private const CONTROL_TYPES = ['boosted', 'muted', 'reset_learning'];

    public function __construct(
        private ?AIWorkspaceScopeService $workspaceScope = null
    ) {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function activeControls(array $filters = []): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        $workspaceId = (int) ($filters['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            $workspaceId = $this->workspaceScope->requireWorkspaceId();
        }

        $where = ['workspace_id = ?', 'enabled = 1'];
        $params = [$workspaceId];
        if (!empty($filters['control_scope'])) {
            $where[] = 'control_scope = ?';
            $params[] = $this->normalizeScope((string) $filters['control_scope']);
        }
        if (!empty($filters['control_type'])) {
            $where[] = 'control_type = ?';
            $params[] = $this->normalizeType((string) $filters['control_type']);
        }

        $rows = Database::query(
            'SELECT *
             FROM ai_coach_recommendation_controls
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY updated_at DESC, id DESC
             LIMIT 200',
            $params
        );

        return array_map(fn(array $row): array => $this->shapeRow($row), $rows);
    }

    public function summary(array $filters = []): array
    {
        $controls = $this->activeControls($filters);
        $counts = ['boosted' => 0, 'muted' => 0, 'reset_learning' => 0];
        $byScope = ['feedback_signature' => 0, 'source_type' => 0, 'source_section' => 0];
        foreach ($controls as $control) {
            $type = (string) ($control['control_type'] ?? '');
            $scope = (string) ($control['control_scope'] ?? '');
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
            if (isset($byScope[$scope])) {
                $byScope[$scope]++;
            }
        }

        return [
            'counts' => $counts,
            'by_scope' => $byScope,
            'active_controls' => $controls,
            'total_controls' => count($controls),
        ];
    }

    public function setControl(
        string $scope,
        string $value,
        string $type,
        int $userId,
        string $reason = '',
        array $metadata = []
    ): int {
        $this->assertTableReady();
        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $scope = $this->normalizeScope($scope);
        $type = $this->normalizeType($type);
        $value = $this->normalizeValue($value);
        if ($value === '') {
            throw new \InvalidArgumentException('Control value is required.');
        }
        $previous = Database::queryOne(
            'SELECT * FROM ai_coach_recommendation_controls
             WHERE workspace_id = ? AND control_scope = ? AND control_value = ? AND control_type = ?
             ORDER BY id DESC LIMIT 1',
            [$workspaceId, $scope, $value, $type]
        );

        Database::execute(
            "INSERT INTO ai_coach_recommendation_controls
                (workspace_id, created_by, control_scope, control_value, control_type, enabled, reason, metadata_json)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                created_by = VALUES(created_by),
                enabled = 1,
                reason = VALUES(reason),
                metadata_json = VALUES(metadata_json),
                updated_at = NOW()",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $scope,
                $value,
                $type,
                $this->nullableString($reason),
                json_encode($this->filterMetadata($metadata), JSON_UNESCAPED_SLASHES),
            ]
        );

        $row = Database::queryOne(
            'SELECT * FROM ai_coach_recommendation_controls
             WHERE workspace_id = ? AND control_scope = ? AND control_value = ? AND control_type = ?
             ORDER BY id DESC LIMIT 1',
            [$workspaceId, $scope, $value, $type]
        );
        $controlId = (int) ($row['id'] ?? 0);
        $this->logControlAudit('ai_coach_control_created', $controlId, $previous, $row ?: [], $workspaceId, $userId);

        return $controlId;
    }

    public function disableControl(int $controlId, int $userId, string $reason = ''): bool
    {
        if ($controlId <= 0 || !$this->tableReady()) {
            return false;
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $row = Database::queryOne(
            'SELECT * FROM ai_coach_recommendation_controls WHERE id = ? AND workspace_id = ? LIMIT 1',
            [$controlId, $workspaceId]
        );
        if (!$row) {
            return false;
        }

        $metadata = $this->safeMetadata($row['metadata_json'] ?? null);
        $metadata['disabled_by'] = (string) max(0, $userId);
        if (trim($reason) !== '') {
            $metadata['disabled_reason'] = trim($reason);
        }

        Database::execute(
            'UPDATE ai_coach_recommendation_controls
             SET enabled = 0, metadata_json = ?, updated_at = NOW()
             WHERE id = ? AND workspace_id = ?',
            [json_encode($this->filterMetadata($metadata), JSON_UNESCAPED_SLASHES), $controlId, $workspaceId]
        );
        $updated = Database::queryOne(
            'SELECT * FROM ai_coach_recommendation_controls WHERE id = ? AND workspace_id = ? LIMIT 1',
            [$controlId, $workspaceId]
        );
        $this->logControlAudit('ai_coach_control_disabled', $controlId, $row, $updated ?: [], $workspaceId, $userId);

        return true;
    }

    public function controlsForRecommendations(array $recommendations): array
    {
        $controls = $this->activeControls();
        if ($controls === []) {
            return [];
        }

        $matched = [];
        foreach (['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'] as $section) {
            foreach ((array) ($recommendations[$section] ?? []) as $item) {
                $signature = trim((string) ($item['feedback_signature'] ?? ''));
                if ($signature === '') {
                    continue;
                }
                $sourceType = $this->itemSourceType($item);
                $sourceSection = $section;
                foreach ($controls as $control) {
                    if (!$this->controlMatches($control, $signature, $sourceType, $sourceSection)) {
                        continue;
                    }
                    $matched[$signature][] = $control;
                }
            }
        }

        return $matched;
    }

    public function resetCutoffsBySignature(array $recommendations, array $controlsBySignature): array
    {
        $cutoffs = [];
        foreach (['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'] as $section) {
            foreach ((array) ($recommendations[$section] ?? []) as $item) {
                $signature = trim((string) ($item['feedback_signature'] ?? ''));
                if ($signature === '' || empty($controlsBySignature[$signature])) {
                    continue;
                }
                foreach ((array) $controlsBySignature[$signature] as $control) {
                    if ((string) ($control['control_type'] ?? '') !== 'reset_learning') {
                        continue;
                    }
                    $updatedAt = (string) ($control['updated_at'] ?? '');
                    if ($updatedAt !== '' && (!isset($cutoffs[$signature]) || strcmp($updatedAt, $cutoffs[$signature]) > 0)) {
                        $cutoffs[$signature] = $updatedAt;
                    }
                }
            }
        }

        return $cutoffs;
    }

    public function tableReady(): bool
    {
        return Database::tableExists('ai_coach_recommendation_controls');
    }

    private function controlMatches(array $control, string $signature, string $sourceType, string $sourceSection): bool
    {
        $scope = (string) ($control['control_scope'] ?? '');
        $value = (string) ($control['control_value'] ?? '');
        return match ($scope) {
            'feedback_signature' => hash_equals($value, $signature),
            'source_type' => $value === $sourceType,
            'source_section' => $value === $sourceSection,
            default => false,
        };
    }

    private function itemSourceType(array $item): string
    {
        $sourceType = $this->normalizeValue((string) ($item['source_recommendation_type'] ?? ''));
        if ($sourceType !== '') {
            return $sourceType;
        }
        return $this->normalizeValue((string) ($item['category'] ?? 'coach_recommendation')) ?: 'coach_recommendation';
    }

    private function shapeRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'created_by' => !empty($row['created_by']) ? (int) $row['created_by'] : null,
            'control_scope' => (string) ($row['control_scope'] ?? ''),
            'control_value' => (string) ($row['control_value'] ?? ''),
            'control_type' => (string) ($row['control_type'] ?? ''),
            'enabled' => !empty($row['enabled']),
            'reason' => (string) ($row['reason'] ?? ''),
            'metadata' => $this->safeMetadata($row['metadata_json'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if (!in_array($scope, self::CONTROL_SCOPES, true)) {
            throw new \InvalidArgumentException('Unsupported AI Coach control scope.');
        }
        return $scope;
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::CONTROL_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported AI Coach control type.');
        }
        return $type;
    }

    private function normalizeValue(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $normalized = trim(preg_replace('/[^a-z0-9_:\-.]+/', '_', $value) ?? '', '_');
        return $normalized === '' ? '' : $normalized;
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : substr($value, 0, 255);
    }

    private function filterMetadata(array $metadata): array
    {
        $safe = [];
        foreach (['source', 'label', 'latest_event_id', 'disabled_by', 'disabled_reason'] as $key) {
            if (isset($metadata[$key]) && is_scalar($metadata[$key])) {
                $safe[$key] = substr((string) $metadata[$key], 0, 255);
            }
        }
        return $safe;
    }

    private function safeMetadata(mixed $json): array
    {
        $decoded = is_string($json) && trim($json) !== '' ? json_decode($json, true) : [];
        return is_array($decoded) ? $this->filterMetadata($decoded) : [];
    }

    private function assertTableReady(): void
    {
        if (!$this->tableReady()) {
            throw new \RuntimeException('AI Coach recommendation controls table is not available.');
        }
    }

    private function logControlAudit(
        string $action,
        int $controlId,
        ?array $oldRow,
        array $newRow,
        int $workspaceId,
        int $actorUserId
    ): void {
        try {
            if (!Database::tableExists('audit_log') || $controlId <= 0) {
                return;
            }

            Database::execute(
                'INSERT INTO audit_log
                    (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $actorUserId > 0 ? $actorUserId : null,
                    $action,
                    'ai_coach_recommendation_control',
                    (string) $controlId,
                    $oldRow ? json_encode($this->auditPayload($oldRow, $workspaceId, $actorUserId), JSON_UNESCAPED_SLASHES) : null,
                    json_encode($this->auditPayload($newRow, $workspaceId, $actorUserId), JSON_UNESCAPED_SLASHES),
                    substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                    substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
                ]
            );
        } catch (\Throwable $e) {
            error_log('AI Coach control audit failed: ' . $e->getMessage());
        }
    }

    private function auditPayload(array $row, int $workspaceId, int $actorUserId): array
    {
        return [
            'workspace_id' => (int) ($row['workspace_id'] ?? $workspaceId),
            'control_scope' => (string) ($row['control_scope'] ?? ''),
            'control_value' => (string) ($row['control_value'] ?? ''),
            'control_type' => (string) ($row['control_type'] ?? ''),
            'enabled' => !empty($row['enabled']),
            'reason' => (string) ($row['reason'] ?? ''),
            'actor_user_id' => max(0, $actorUserId),
        ];
    }
}
