<?php

namespace CRM\Services;

use CRM\Database;

class PluginRuntimeEventService
{
    private const EVENT_TYPES = [
        'capability_invoked',
        'capability_succeeded',
        'capability_failed',
        'task_created',
        'task_enriched',
        'target_rollup_computed',
        'workflow_action_executed',
        'authorization_blocked',
        'readiness_blocked',
    ];

    private const STATUSES = ['success', 'failed', 'blocked', 'info'];

    public function record(array $event): void
    {
        if (!Database::tableExists('workspace_plugin_runtime_events')) {
            return;
        }

        $workspaceId = (int) ($event['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            return;
        }

        $eventType = $this->normalize((string) ($event['event_type'] ?? 'capability_invoked'), self::EVENT_TYPES, 'capability_invoked');
        $status = $this->normalize((string) ($event['status'] ?? 'info'), self::STATUSES, 'info');

        Database::execute(
            "INSERT INTO workspace_plugin_runtime_events (
                workspace_id, user_id, skill_key, capability_key, entity_type, entity_id,
                event_type, status, duration_ms, error_code, error_message, metadata_json
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                !empty($event['user_id']) ? (int) $event['user_id'] : null,
                $this->stringOrNull($event['skill_key'] ?? null, 80),
                $this->stringOrNull($event['capability_key'] ?? null, 120),
                $this->stringOrNull($event['entity_type'] ?? null, 80),
                !empty($event['entity_id']) ? (int) $event['entity_id'] : null,
                $eventType,
                $status,
                isset($event['duration_ms']) ? max(0, (int) $event['duration_ms']) : null,
                $this->stringOrNull($event['error_code'] ?? null, 80),
                $this->stringOrNull($event['error_message'] ?? null, 4000),
                json_encode((array) ($event['metadata'] ?? []), JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public function recent(array $filters = [], int $limit = 50): array
    {
        if (!Database::tableExists('workspace_plugin_runtime_events')) {
            return [];
        }

        $where = ['1=1'];
        $params = [];
        foreach (['workspace_id', 'user_id', 'skill_key', 'capability_key', 'event_type', 'status', 'entity_type'] as $field) {
            if (!isset($filters[$field]) || $filters[$field] === '') {
                continue;
            }
            $where[] = $field . ' = ?';
            $params[] = $filters[$field];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= ?';
            $params[] = (string) $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= ?';
            $params[] = (string) $filters['date_to'] . ' 23:59:59';
        }

        return Database::query(
            "SELECT *
             FROM workspace_plugin_runtime_events
             WHERE " . implode(' AND ', $where) . "
             ORDER BY created_at DESC, id DESC
             LIMIT " . max(1, min(250, (int) $limit)),
            $params
        );
    }

    public function summary(array $filters = []): array
    {
        $rows = $this->recent($filters, 1000);
        $bySkill = [];
        $counts = array_fill_keys(self::EVENT_TYPES, 0);
        foreach ($rows as $row) {
            $type = (string) ($row['event_type'] ?? '');
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
            $skillKey = (string) ($row['skill_key'] ?? '');
            if ($skillKey !== '') {
                $bySkill[$skillKey] = (int) (($bySkill[$skillKey] ?? 0) + 1);
            }
        }
        arsort($bySkill);

        return [
            'counts' => $counts,
            'top_skills' => array_map(
                static fn(string $skillKey, int $events): array => ['skill_key' => $skillKey, 'events' => $events],
                array_keys($bySkill),
                array_values($bySkill)
            ),
            'total_events' => count($rows),
        ];
    }

    private function normalize(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function stringOrNull(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : substr($value, 0, $limit);
    }
}
