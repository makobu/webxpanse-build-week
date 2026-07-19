<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceMarketplaceRecommendationControlService
{
    private const CONTROL_TYPES = ['pinned', 'muted', 'surface_disabled'];
    private const SURFACES = ['all', 'marketplace', 'clarity_chat', 'coach'];

    public function activeControlsBySkill(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return [];
        }

        $rows = Database::query(
            "SELECT skill_key, control_type, surface, reason_code, metadata_json, updated_at
             FROM workspace_marketplace_recommendation_controls
             WHERE workspace_id = ? AND enabled = 1
             ORDER BY updated_at DESC, id DESC",
            [$workspaceId]
        );

        $out = [];
        foreach ($rows as $row) {
            $skillKey = $this->normalizeKey((string) ($row['skill_key'] ?? ''));
            $controlType = $this->normalizeControlType((string) ($row['control_type'] ?? ''));
            $surface = $this->normalizeSurface((string) ($row['surface'] ?? 'all'));
            if ($skillKey === '') {
                continue;
            }
            $out[$skillKey][] = [
                'control_type' => $controlType,
                'surface' => $surface,
                'reason_code' => (string) ($row['reason_code'] ?? ''),
                'metadata' => $this->safeMetadata($row['metadata_json'] ?? null),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        ksort($out);
        return $out;
    }

    public function setControl(
        int $workspaceId,
        int $userId,
        string $skillKey,
        string $controlType,
        string $surface = 'all',
        bool $enabled = true,
        string $reasonCode = '',
        array $metadata = []
    ): void {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return;
        }

        $skillKey = $this->normalizeKey($skillKey);
        if ($skillKey === '') {
            throw new \InvalidArgumentException('Marketplace item is required.');
        }

        $controlType = $this->normalizeControlType($controlType);
        $surface = $controlType === 'surface_disabled' ? $this->normalizeSurface($surface) : 'all';
        $reasonCode = substr($this->normalizeReason($reasonCode), 0, 80);

        Database::execute(
            "INSERT INTO workspace_marketplace_recommendation_controls
                (workspace_id, user_id, skill_key, control_type, surface, enabled, reason_code, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                enabled = VALUES(enabled),
                reason_code = VALUES(reason_code),
                metadata_json = VALUES(metadata_json),
                updated_at = NOW()",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $skillKey,
                $controlType,
                $surface,
                $enabled ? 1 : 0,
                $reasonCode !== '' ? $reasonCode : null,
                json_encode($this->filterMetadata($metadata), JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public function marketplaceControlsBySkill(array $filters = []): array
    {
        $workspaceId = (int) ($filters['workspace_id'] ?? 0);
        $controls = $this->activeControlsBySkill($workspaceId);
        $out = [];
        foreach ($controls as $skillKey => $items) {
            $out[$skillKey] = $this->shapeControls($items);
        }
        ksort($out);
        return $out;
    }

    public function summary(array $filters = []): array
    {
        $workspaceId = (int) ($filters['workspace_id'] ?? 0);
        $controls = $this->activeControlsBySkill($workspaceId);
        $counts = ['pinned' => 0, 'muted' => 0, 'surface_disabled' => 0];
        $bySurface = [];
        $rows = [];

        foreach ($controls as $skillKey => $items) {
            foreach ($items as $item) {
                $type = (string) ($item['control_type'] ?? '');
                $surface = (string) ($item['surface'] ?? 'all');
                if (isset($counts[$type])) {
                    $counts[$type]++;
                }
                $bySurface[$surface] = (int) (($bySurface[$surface] ?? 0) + 1);
                $rows[] = [
                    'skill_key' => $skillKey,
                    'control_type' => $type,
                    'surface' => $surface,
                    'reason_code' => (string) ($item['reason_code'] ?? ''),
                    'updated_at' => (string) ($item['updated_at'] ?? ''),
                ];
            }
        }

        usort($rows, static function (array $left, array $right): int {
            $time = strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
            if ($time !== 0) {
                return $time;
            }
            return strcmp((string) ($left['skill_key'] ?? ''), (string) ($right['skill_key'] ?? ''));
        });

        return [
            'counts' => $counts,
            'by_surface' => $bySurface,
            'active_controls' => array_slice($rows, 0, 20),
            'total_controls' => count($rows),
        ];
    }

    public function shapeControls(array $controls): array
    {
        $out = [
            'pinned' => false,
            'muted' => false,
            'surface_disabled' => [],
            'admin_control_type' => '',
            'admin_control_surface' => '',
            'admin_control_reason' => '',
        ];

        foreach ($controls as $control) {
            $type = (string) ($control['control_type'] ?? '');
            $surface = (string) ($control['surface'] ?? 'all');
            if ($type === 'pinned') {
                $out['pinned'] = true;
            } elseif ($type === 'muted') {
                $out['muted'] = true;
            } elseif ($type === 'surface_disabled') {
                $out['surface_disabled'][] = $surface;
            }
            if ($out['admin_control_type'] === '') {
                $out['admin_control_type'] = $type;
                $out['admin_control_surface'] = $surface;
                $out['admin_control_reason'] = (string) ($control['reason_code'] ?? '');
            }
        }

        $out['surface_disabled'] = array_values(array_unique($out['surface_disabled']));
        sort($out['surface_disabled']);
        return $out;
    }

    public function tableReady(): bool
    {
        return Database::tableExists('workspace_marketplace_recommendation_controls');
    }

    private function normalizeControlType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::CONTROL_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported Marketplace recommendation control.');
        }
        return $type;
    }

    private function normalizeSurface(string $surface): string
    {
        $surface = strtolower(trim($surface));
        return in_array($surface, self::SURFACES, true) ? $surface : 'all';
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }

    private function normalizeReason(string $reason): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $reason) ?? ''));
    }

    private function filterMetadata(array $metadata): array
    {
        $safe = [];
        foreach (['source', 'label', 'surface', 'control_type'] as $key) {
            if (isset($metadata[$key]) && is_scalar($metadata[$key])) {
                $safe[$key] = (string) $metadata[$key];
            }
        }
        return $safe;
    }

    private function safeMetadata(mixed $json): array
    {
        $decoded = is_string($json) && trim($json) !== '' ? json_decode($json, true) : [];
        return is_array($decoded) ? $this->filterMetadata($decoded) : [];
    }
}
