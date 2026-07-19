<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceMarketplaceActivationBundleEventService
{
    private const SURFACES = ['marketplace', 'clarity_chat'];
    private const EVENT_TYPES = ['bundle_impression', 'cta_clicked', 'selected', 'dismissed', 'completed', 'module_installed'];
    private const STATUSES = ['suggested', 'selected', 'dismissed', 'completed'];

    public function recordEvent(
        int $workspaceId,
        int $userId,
        string $bundleKey,
        string $eventType,
        array $context = []
    ): void {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return;
        }

        $bundleKey = $this->normalizeKey($bundleKey);
        if ($bundleKey === '') {
            return;
        }

        $bundle = (array) ($context['bundle'] ?? []);
        $progress = (array) ($context['progress'] ?? $bundle['progress'] ?? []);

        Database::execute(
            "INSERT INTO workspace_marketplace_activation_bundle_events
                (workspace_id, user_id, bundle_key, surface, event_type, bundle_status, priority, included_skill_keys_json, recommended_skill_keys_json, progress_json, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $bundleKey,
                $this->normalizeSurface((string) ($context['surface'] ?? 'marketplace')),
                $this->normalizeEventType($eventType),
                $this->normalizeStatus((string) ($context['bundle_status'] ?? $bundle['status'] ?? '')),
                $this->normalizePriority((string) ($context['priority'] ?? $bundle['priority'] ?? '')),
                json_encode($this->normalizeKeyList((array) ($context['included_skill_keys'] ?? $bundle['included_skill_keys'] ?? [])), JSON_UNESCAPED_SLASHES),
                json_encode($this->normalizeKeyList((array) ($context['recommended_skill_keys'] ?? $bundle['recommended_skill_keys'] ?? [])), JSON_UNESCAPED_SLASHES),
                json_encode($this->normalizeProgress($progress), JSON_UNESCAPED_SLASHES),
                json_encode($this->normalizeMetadata((array) ($context['metadata'] ?? []), $bundle), JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public function recordBundleImpressions(int $workspaceId, int $userId, array $bundles, array $metadata = []): void
    {
        foreach ($bundles as $bundle) {
            if (!is_array($bundle)) {
                continue;
            }

            $this->recordEvent(
                $workspaceId,
                $userId,
                (string) ($bundle['bundle_key'] ?? ''),
                'bundle_impression',
                [
                    'bundle' => $bundle,
                    'metadata' => array_merge($metadata, [
                        'label' => (string) ($bundle['label'] ?? ''),
                        'setup_url' => (string) ($bundle['setup_url'] ?? ''),
                        'next_action_skill_key' => (string) ($bundle['next_action']['skill_key'] ?? ''),
                    ]),
                ]
            );
        }
    }

    public function getSummary(array $filters = []): array
    {
        $rows = $this->fetchRows($filters, 1000);
        $counts = array_fill_keys(self::EVENT_TYPES, 0);
        $byBundle = [];

        foreach ($rows as $row) {
            $type = (string) ($row['event_type'] ?? '');
            if (isset($counts[$type])) {
                $counts[$type]++;
            }

            $bundleKey = (string) ($row['bundle_key'] ?? '');
            if ($bundleKey === '') {
                continue;
            }

            if (!isset($byBundle[$bundleKey])) {
                $byBundle[$bundleKey] = [
                    'bundle_key' => $bundleKey,
                    'events' => 0,
                    'bundle_impression' => 0,
                    'cta_clicked' => 0,
                    'selected' => 0,
                    'dismissed' => 0,
                    'completed' => 0,
                    'module_installed' => 0,
                ];
            }
            $byBundle[$bundleKey]['events']++;
            if (isset($byBundle[$bundleKey][$type])) {
                $byBundle[$bundleKey][$type]++;
            }
        }

        usort($byBundle, static function (array $left, array $right): int {
            $events = ((int) ($right['events'] ?? 0)) <=> ((int) ($left['events'] ?? 0));
            return $events !== 0 ? $events : strcmp((string) ($left['bundle_key'] ?? ''), (string) ($right['bundle_key'] ?? ''));
        });

        $impressions = (int) ($counts['bundle_impression'] ?? 0);
        $clicks = (int) ($counts['cta_clicked'] ?? 0);
        $selected = (int) ($counts['selected'] ?? 0);
        $completed = (int) ($counts['completed'] ?? 0);

        return [
            'counts' => $counts,
            'top_bundles' => array_slice(array_values($byBundle), 0, 5),
            'total_events' => count($rows),
            'click_through_rate' => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0,
            'completion_rate' => $selected > 0 ? round($completed / $selected, 4) : 0.0,
        ];
    }

    public function getEvents(array $filters = [], int $limit = 100): array
    {
        return $this->fetchRows($filters, $limit);
    }

    private function fetchRows(array $filters, int $limit): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        $sql = "SELECT * FROM workspace_marketplace_activation_bundle_events";
        $where = [];
        $params = [];

        if (!empty($filters['workspace_id'])) {
            $where[] = 'workspace_id = ?';
            $params[] = (int) $filters['workspace_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['bundle_key'])) {
            $where[] = 'bundle_key = ?';
            $params[] = $this->normalizeKey((string) $filters['bundle_key']);
        }
        if (!empty($filters['surface'])) {
            $where[] = 'surface = ?';
            $params[] = $this->normalizeSurface((string) $filters['surface']);
        }
        if (!empty($filters['event_type'])) {
            $where[] = 'event_type = ?';
            $params[] = $this->normalizeEventType((string) $filters['event_type']);
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $this->startOfDay((string) $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at < ?';
            $params[] = $this->startOfNextDay((string) $filters['date_to']);
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(1000, $limit));

        return Database::query($sql, $params);
    }

    private function tableReady(): bool
    {
        return Database::tableExists('workspace_marketplace_activation_bundle_events');
    }

    private function normalizeSurface(string $surface): string
    {
        return in_array($surface, self::SURFACES, true) ? $surface : 'marketplace';
    }

    private function normalizeEventType(string $eventType): string
    {
        return in_array($eventType, self::EVENT_TYPES, true) ? $eventType : 'bundle_impression';
    }

    private function normalizeStatus(string $status): ?string
    {
        $status = strtolower(trim($status));
        return in_array($status, self::STATUSES, true) ? $status : null;
    }

    private function normalizePriority(string $priority): ?string
    {
        $priority = strtolower(trim($priority));
        return in_array($priority, ['high', 'medium', 'low'], true) ? $priority : null;
    }

    private function normalizeKeyList(array $keys): array
    {
        return array_values(array_filter(array_unique(array_map(
            fn($key): string => $this->normalizeKey((string) $key),
            $keys
        ))));
    }

    private function normalizeProgress(array $progress): array
    {
        $safe = [];
        foreach (['total', 'installed', 'recommended'] as $key) {
            if (isset($progress[$key])) {
                $safe[$key] = max(0, (int) $progress[$key]);
            }
        }
        if (isset($progress['percent'])) {
            $safe['percent'] = max(0.0, min(1.0, (float) $progress['percent']));
        }
        return $safe;
    }

    private function normalizeMetadata(array $metadata, array $bundle): array
    {
        $safe = [];
        foreach (['source', 'surface', 'label', 'setup_url', 'target_url', 'next_action_skill_key', 'module_skill_key', 'module_label'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_scalar($value)) {
                $safe[$key] = substr((string) $value, 0, 255);
            }
        }
        if (!isset($safe['label']) && isset($bundle['label']) && is_scalar($bundle['label'])) {
            $safe['label'] = substr((string) $bundle['label'], 0, 255);
        }
        return $safe;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }

    private function startOfDay(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($date), 0, 10));
        return ($parsed ?: new \DateTimeImmutable($date))->format('Y-m-d 00:00:00');
    }

    private function startOfNextDay(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($date), 0, 10));
        return ($parsed ?: new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d 00:00:00');
    }
}
