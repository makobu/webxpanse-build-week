<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceMarketplaceRecommendationEventService
{
    private const SURFACES = ['marketplace', 'clarity_chat', 'coach'];
    private const EVENT_TYPES = [
        'impression',
        'module_page_view',
        'catalog_click',
        'cta_clicked',
        'dismissed',
        'snoozed',
        'task_created',
        'installed',
        'uninstalled',
        'test_attempted',
        'test_passed',
        'test_failed',
    ];

    public function recordEvent(
        int $workspaceId,
        int $userId,
        string $skillKey,
        string $surface,
        string $eventType,
        array $context = []
    ): void {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return;
        }

        $skillKey = $this->normalizeKey($skillKey);
        if ($skillKey === '') {
            return;
        }

        $surface = $this->normalizeSurface($surface);
        $eventType = $this->normalizeEventType($eventType);
        $reasonCodes = array_values(array_filter(array_map('strval', (array) ($context['reason_codes'] ?? []))));
        $metadata = (array) ($context['metadata'] ?? []);

        Database::execute(
            "INSERT INTO workspace_marketplace_recommendation_events
                (workspace_id, user_id, skill_key, surface, event_type, recommendation_score, priority, reason_codes_json, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $skillKey,
                $surface,
                $eventType,
                isset($context['recommendation_score']) ? (int) $context['recommendation_score'] : null,
                $this->normalizePriority((string) ($context['priority'] ?? '')),
                json_encode($reasonCodes, JSON_UNESCAPED_SLASHES),
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public function recordRecommendationEvents(
        int $workspaceId,
        int $userId,
        string $surface,
        string $eventType,
        array $recommendations,
        array $metadata = []
    ): void {
        foreach ($recommendations as $recommendation) {
            if (!is_array($recommendation)) {
                continue;
            }
            $this->recordEvent(
                $workspaceId,
                $userId,
                (string) ($recommendation['skill_key'] ?? $recommendation['marketplace_skill_key'] ?? ''),
                $surface,
                $eventType,
                [
                    'recommendation_score' => $recommendation['score'] ?? $recommendation['marketplace_score'] ?? null,
                    'priority' => (string) ($recommendation['priority'] ?? ''),
                    'reason_codes' => (array) ($recommendation['reason_codes'] ?? []),
                    'metadata' => array_merge($metadata, [
                        'label' => (string) ($recommendation['label'] ?? $recommendation['title'] ?? ''),
                        'base_score' => isset($recommendation['base_score']) ? (int) $recommendation['base_score'] : null,
                        'adaptive_score_delta' => isset($recommendation['adaptive_score_delta']) ? (int) $recommendation['adaptive_score_delta'] : null,
                        'adaptive_reason_codes' => array_values(array_filter(array_map('strval', (array) ($recommendation['adaptive_reason_codes'] ?? [])))),
                        'adaptive_confidence' => (string) ($recommendation['adaptive_confidence'] ?? ''),
                        'setup_journey_present' => !empty($recommendation['setup_journey']) || !empty($recommendation['marketplace_setup_journey']),
                        'setup_journey_next_step_key' => (string) (
                            $recommendation['setup_journey']['next_step']['step_key']
                            ?? $recommendation['marketplace_setup_journey']['next_step']['step_key']
                            ?? $recommendation['marketplace_next_setup_step']['step_key']
                            ?? ''
                        ),
                        'admin_control_type' => (string) ($recommendation['admin_control_type'] ?? ''),
                        'admin_control_surface' => (string) ($recommendation['admin_control_surface'] ?? ''),
                        'admin_control_reason' => (string) ($recommendation['admin_control_reason'] ?? ''),
                    ]),
                ]
            );
        }
    }

    public function getSummary(array $filters = []): array
    {
        $rows = $this->fetchRows($filters, 1000);
        $counts = array_fill_keys(self::EVENT_TYPES, 0);
        $bySurface = [];
        $bySkill = [];

        foreach ($rows as $row) {
            $type = (string) ($row['event_type'] ?? '');
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
            $surface = (string) ($row['surface'] ?? '');
            if ($surface !== '') {
                $bySurface[$surface][$type] = (int) (($bySurface[$surface][$type] ?? 0) + 1);
            }
            $skillKey = (string) ($row['skill_key'] ?? '');
            if ($skillKey !== '') {
                if (!isset($bySkill[$skillKey])) {
                    $bySkill[$skillKey] = [
                        'skill_key' => $skillKey,
                        'events' => 0,
                        'impressions' => 0,
                        'module_page_view' => 0,
                        'catalog_click' => 0,
                        'cta_clicked' => 0,
                        'installed' => 0,
                        'uninstalled' => 0,
                        'test_attempted' => 0,
                        'test_passed' => 0,
                        'test_failed' => 0,
                    ];
                }
                $bySkill[$skillKey]['events']++;
                if (isset($bySkill[$skillKey][$type])) {
                    $bySkill[$skillKey][$type]++;
                }
            }
        }

        usort($bySkill, static function (array $left, array $right): int {
            $events = ((int) ($right['events'] ?? 0)) <=> ((int) ($left['events'] ?? 0));
            if ($events !== 0) {
                return $events;
            }
            return strcmp((string) ($left['skill_key'] ?? ''), (string) ($right['skill_key'] ?? ''));
        });

        $impressions = (int) ($counts['impression'] ?? 0);
        $clicks = (int) ($counts['cta_clicked'] ?? 0);

        return [
            'counts' => $counts,
            'by_surface' => $bySurface,
            'top_skills' => array_slice(array_values($bySkill), 0, 5),
            'total_events' => count($rows),
            'click_through_rate' => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0,
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

        $sql = "SELECT * FROM workspace_marketplace_recommendation_events";
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
        if (!empty($filters['surface'])) {
            $where[] = 'surface = ?';
            $params[] = $this->normalizeSurface((string) $filters['surface']);
        }
        if (!empty($filters['skill_key'])) {
            $where[] = 'skill_key = ?';
            $params[] = $this->normalizeKey((string) $filters['skill_key']);
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

    private function normalizeSurface(string $surface): string
    {
        return in_array($surface, self::SURFACES, true) ? $surface : 'marketplace';
    }

    private function normalizeEventType(string $eventType): string
    {
        return in_array($eventType, self::EVENT_TYPES, true) ? $eventType : 'impression';
    }

    private function normalizePriority(string $priority): ?string
    {
        $priority = strtolower(trim($priority));
        return in_array($priority, ['high', 'medium', 'low'], true) ? $priority : null;
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

    private function tableReady(): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                ['workspace_marketplace_recommendation_events']
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
