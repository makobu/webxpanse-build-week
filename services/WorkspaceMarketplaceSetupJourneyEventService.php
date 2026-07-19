<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceMarketplaceSetupJourneyEventService
{
    private const SURFACES = ['marketplace'];
    private const EVENT_TYPES = ['journey_impression', 'setup_opened', 'setup_saved', 'install_completed', 'step_completed', 'step_skipped', 'step_reset'];
    private const STEP_STATUSES = ['pending', 'completed', 'skipped'];

    public function recordEvent(
        int $workspaceId,
        int $userId,
        string $skillKey,
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

        Database::execute(
            "INSERT INTO workspace_marketplace_setup_journey_events
                (workspace_id, user_id, skill_key, step_key, label, surface, event_type, step_status, source, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $skillKey,
                $this->nullableStepKey((string) ($context['step_key'] ?? '')),
                $this->nullableLabel((string) ($context['label'] ?? '')),
                $this->normalizeSurface((string) ($context['surface'] ?? 'marketplace')),
                $this->normalizeEventType($eventType),
                $this->normalizeStepStatus((string) ($context['step_status'] ?? '')),
                $this->normalizeSource((string) ($context['source'] ?? 'workspace_marketplace_page')),
                json_encode($this->normalizeMetadata((array) ($context['metadata'] ?? [])), JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public function recordJourneyImpressions(
        int $workspaceId,
        int $userId,
        array $journeys,
        array $metadata = []
    ): void {
        foreach ($journeys as $journey) {
            if (!is_array($journey)) {
                continue;
            }

            $progress = (array) ($journey['progress'] ?? []);
            $this->recordEvent(
                $workspaceId,
                $userId,
                (string) ($journey['skill_key'] ?? ''),
                'journey_impression',
                [
                    'label' => (string) ($journey['label'] ?? ''),
                    'source' => (string) ($metadata['source'] ?? 'workspace_marketplace_page'),
                    'metadata' => array_merge($metadata, [
                        'setup_url' => (string) ($journey['setup_url'] ?? ''),
                        'step_total' => (int) ($progress['total'] ?? 0),
                        'step_completed' => (int) ($progress['completed'] ?? 0),
                        'step_skipped' => (int) ($progress['skipped'] ?? 0),
                        'step_pending' => (int) ($progress['pending'] ?? 0),
                    ]),
                ]
            );
        }
    }

    public function getSummary(array $filters = []): array
    {
        $rows = $this->fetchRows($filters, 1000);
        $counts = array_fill_keys(self::EVENT_TYPES, 0);
        $bySkill = [];
        $byStep = [];

        foreach ($rows as $row) {
            $type = (string) ($row['event_type'] ?? '');
            if (isset($counts[$type])) {
                $counts[$type]++;
            }

            $skillKey = (string) ($row['skill_key'] ?? '');
            if ($skillKey !== '') {
                if (!isset($bySkill[$skillKey])) {
                    $bySkill[$skillKey] = [
                        'skill_key' => $skillKey,
                        'events' => 0,
                        'journey_impression' => 0,
                        'setup_opened' => 0,
                        'install_completed' => 0,
                        'step_completed' => 0,
                        'step_skipped' => 0,
                        'step_reset' => 0,
                    ];
                }
                $bySkill[$skillKey]['events']++;
                if (isset($bySkill[$skillKey][$type])) {
                    $bySkill[$skillKey][$type]++;
                }
            }

            $stepKey = (string) ($row['step_key'] ?? '');
            if ($stepKey !== '') {
                $stepId = $skillKey . ':' . $stepKey;
                if (!isset($byStep[$stepId])) {
                    $byStep[$stepId] = [
                        'skill_key' => $skillKey,
                        'step_key' => $stepKey,
                        'label' => (string) ($row['label'] ?? ''),
                        'events' => 0,
                        'step_completed' => 0,
                        'step_skipped' => 0,
                        'step_reset' => 0,
                        'friction_events' => 0,
                    ];
                }
                $byStep[$stepId]['events']++;
                if (isset($byStep[$stepId][$type])) {
                    $byStep[$stepId][$type]++;
                }
                if (in_array($type, ['step_skipped', 'step_reset'], true)) {
                    $byStep[$stepId]['friction_events']++;
                }
                if ((string) ($byStep[$stepId]['label'] ?? '') === '' && !empty($row['label'])) {
                    $byStep[$stepId]['label'] = (string) $row['label'];
                }
            }
        }

        usort($bySkill, static function (array $left, array $right): int {
            $events = ((int) ($right['events'] ?? 0)) <=> ((int) ($left['events'] ?? 0));
            return $events !== 0 ? $events : strcmp((string) ($left['skill_key'] ?? ''), (string) ($right['skill_key'] ?? ''));
        });

        usort($byStep, static function (array $left, array $right): int {
            $friction = ((int) ($right['friction_events'] ?? 0)) <=> ((int) ($left['friction_events'] ?? 0));
            if ($friction !== 0) {
                return $friction;
            }
            $events = ((int) ($right['events'] ?? 0)) <=> ((int) ($left['events'] ?? 0));
            if ($events !== 0) {
                return $events;
            }
            $label = strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
            return $label !== 0 ? $label : strcmp((string) ($left['step_key'] ?? ''), (string) ($right['step_key'] ?? ''));
        });

        $journeyImpressions = (int) ($counts['journey_impression'] ?? 0);
        $setupOpened = (int) ($counts['setup_opened'] ?? 0);
        $manualActions = (int) ($counts['step_completed'] ?? 0) + (int) ($counts['step_skipped'] ?? 0) + (int) ($counts['step_reset'] ?? 0);

        return [
            'counts' => $counts,
            'top_skills' => array_slice(array_values($bySkill), 0, 5),
            'top_steps' => array_slice(array_values($byStep), 0, 5),
            'total_events' => count($rows),
            'setup_open_rate' => $journeyImpressions > 0 ? round($setupOpened / $journeyImpressions, 4) : 0.0,
            'manual_step_completion_rate' => $manualActions > 0 ? round(((int) ($counts['step_completed'] ?? 0)) / $manualActions, 4) : 0.0,
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

        $sql = "SELECT * FROM workspace_marketplace_setup_journey_events";
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
        if (!empty($filters['skill_key'])) {
            $where[] = 'skill_key = ?';
            $params[] = $this->normalizeKey((string) $filters['skill_key']);
        }
        if (!empty($filters['step_key'])) {
            $where[] = 'step_key = ?';
            $params[] = $this->normalizeStepKey((string) $filters['step_key']);
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
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                ['workspace_marketplace_setup_journey_events']
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function normalizeSurface(string $surface): string
    {
        return in_array($surface, self::SURFACES, true) ? $surface : 'marketplace';
    }

    private function normalizeEventType(string $eventType): string
    {
        return in_array($eventType, self::EVENT_TYPES, true) ? $eventType : 'journey_impression';
    }

    private function normalizeStepStatus(string $status): ?string
    {
        $status = strtolower(trim($status));
        return in_array($status, self::STEP_STATUSES, true) ? $status : null;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }

    private function normalizeStepKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }

    private function nullableStepKey(string $key): ?string
    {
        $key = $this->normalizeStepKey($key);
        return $key !== '' ? $key : null;
    }

    private function nullableLabel(string $label): ?string
    {
        $label = trim($label);
        return $label !== '' ? substr($label, 0, 255) : null;
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $source) ?? ''));
        return substr($source !== '' ? $source : 'workspace_marketplace_page', 0, 40);
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

    private function normalizeMetadata(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            $key = strtolower(trim((string) $key));
            if ($key === '' || preg_match('/secret|token|password|credential|api_key/', $key)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value) ? substr($value, 0, 500) : $value;
            }
        }

        return $safe;
    }
}
