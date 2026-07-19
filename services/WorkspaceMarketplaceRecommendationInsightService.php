<?php

namespace CRM\Services;

class WorkspaceMarketplaceRecommendationInsightService
{
    private const EVENT_TYPES = ['impression', 'cta_clicked', 'dismissed', 'snoozed', 'task_created', 'installed'];
    private const SURFACES = ['marketplace', 'clarity_chat', 'coach'];

    public function getInsights(array $filters = []): array
    {
        $rows = (new WorkspaceMarketplaceRecommendationEventService())->getEvents($filters, 1000);
        if ($rows === []) {
            return [];
        }

        $skillMetrics = [];
        foreach ($rows as $row) {
            $skillKey = $this->normalizeKey((string) ($row['skill_key'] ?? ''));
            if ($skillKey === '') {
                continue;
            }
            if (!isset($skillMetrics[$skillKey])) {
                $skillMetrics[$skillKey] = $this->blankSkillMetrics($skillKey, $filters);
            }

            $eventType = (string) ($row['event_type'] ?? '');
            $surface = (string) ($row['surface'] ?? 'marketplace');
            if (!in_array($eventType, self::EVENT_TYPES, true)) {
                continue;
            }
            if (!in_array($surface, self::SURFACES, true)) {
                $surface = 'marketplace';
            }

            $skillMetrics[$skillKey]['counts'][$eventType]++;
            $skillMetrics[$skillKey]['total_events']++;
            $skillMetrics[$skillKey]['by_surface'][$surface][$eventType]++;
            $skillMetrics[$skillKey]['by_surface'][$surface]['total_events']++;
            $skillMetrics[$skillKey]['label'] = $this->firstLabel($skillMetrics[$skillKey]['label'], $row);
            $skillMetrics[$skillKey]['reason_codes'] = array_values(array_unique(array_merge(
                $skillMetrics[$skillKey]['reason_codes'],
                $this->decodeJson($row['reason_codes_json'] ?? null)
            )));
        }

        $insights = [];
        foreach ($skillMetrics as $metrics) {
            foreach ($this->insightsForSkill($metrics) as $insight) {
                $insights[] = $insight;
            }
        }

        usort($insights, static function (array $left, array $right): int {
            $severityRank = ['high' => 3, 'medium' => 2, 'low' => 1];
            $severity = (($severityRank[(string) ($right['severity'] ?? 'low')] ?? 0) <=> ($severityRank[(string) ($left['severity'] ?? 'low')] ?? 0));
            if ($severity !== 0) {
                return $severity;
            }

            $leftEvents = (int) ($left['metric_snapshot']['total_events'] ?? 0);
            $rightEvents = (int) ($right['metric_snapshot']['total_events'] ?? 0);
            $events = $rightEvents <=> $leftEvents;
            if ($events !== 0) {
                return $events;
            }

            $key = strcmp((string) ($left['insight_key'] ?? ''), (string) ($right['insight_key'] ?? ''));
            if ($key !== 0) {
                return $key;
            }

            return strcmp((string) ($left['skill_key'] ?? ''), (string) ($right['skill_key'] ?? ''));
        });

        return array_slice($insights, 0, 8);
    }

    public function marketplaceInsightsBySkill(array $filters = []): array
    {
        $mapped = [];
        foreach ($this->getInsights($filters) as $insight) {
            $skillKey = $this->normalizeKey((string) ($insight['skill_key'] ?? ''));
            if ($skillKey === '' || isset($mapped[$skillKey])) {
                continue;
            }

            $mapped[$skillKey] = [
                'insight_key' => (string) ($insight['insight_key'] ?? ''),
                'severity' => (string) ($insight['severity'] ?? 'low'),
                'label' => $this->marketplaceLabel((string) ($insight['insight_key'] ?? '')),
                'summary' => (string) ($insight['summary'] ?? ''),
                'recommendation' => (string) ($insight['recommendation'] ?? ''),
                'metric_snapshot' => (array) ($insight['metric_snapshot'] ?? []),
            ];
        }

        ksort($mapped);
        return $mapped;
    }

    private function insightsForSkill(array $metrics): array
    {
        $counts = (array) ($metrics['counts'] ?? []);
        $impressions = (int) ($counts['impression'] ?? 0);
        $clicks = (int) ($counts['cta_clicked'] ?? 0);
        $dismissals = (int) ($counts['dismissed'] ?? 0);
        $snoozes = (int) ($counts['snoozed'] ?? 0);
        $tasks = (int) ($counts['task_created'] ?? 0);
        $installs = (int) ($counts['installed'] ?? 0);
        $negative = $dismissals + $snoozes;
        $actionCount = $clicks + $tasks + $installs + $negative;
        $dismissalRate = $impressions > 0 ? round($negative / $impressions, 4) : 0.0;

        $insights = [];
        if ($impressions >= 3 && $negative > 0 && $dismissalRate >= 0.4) {
            $insights[] = $this->buildInsight(
                'high_dismissal_rate',
                $dismissalRate >= 0.67 ? 'high' : 'medium',
                'High marketplace dismissal rate',
                $this->label($metrics) . ' is being dismissed or snoozed often.',
                'Review the recommendation copy, timing, or fit before expanding this suggestion.',
                $metrics,
                $this->dominantSurface($metrics, ['dismissed', 'snoozed']),
                ['high_dismissal_rate', 'marketplace_feedback']
            );
        }

        if (($clicks > 0 || $tasks > 0) && $installs === 0) {
            $insights[] = $this->buildInsight(
                'high_interest_low_install',
                ($clicks + $tasks) >= 3 ? 'high' : 'medium',
                'Interest is not turning into installs',
                $this->label($metrics) . ' is getting follow-through signals but no install events.',
                'Inspect setup blockers and confirm the Marketplace route makes the next step obvious.',
                $metrics,
                $this->dominantSurface($metrics, ['cta_clicked', 'task_created']),
                ['high_interest_low_install', 'install_gap']
            );
        }

        if (($clicks > 0 || $tasks > 0) && $installs === 0) {
            $insights[] = $this->buildInsight(
                'setup_interest',
                $tasks > 0 ? 'medium' : 'low',
                'Setup intent needs follow-through',
                $this->label($metrics) . ' has setup interest without a matching install signal.',
                'Use the diagnostics timeline to inspect which surface is creating intent and whether setup guidance is clear.',
                $metrics,
                $this->dominantSurface($metrics, ['cta_clicked', 'task_created']),
                ['setup_interest', 'setup_followthrough']
            );
        }

        if ($tasks > 0) {
            $insights[] = $this->buildInsight(
                'coach_task_followthrough',
                $tasks >= 3 ? 'medium' : 'low',
                'Coach recommendations are becoming tasks',
                $this->label($metrics) . ' has been converted into Coach follow-through tasks.',
                'Review those tasks for completion patterns before deciding whether to strengthen Coach guidance.',
                $metrics,
                'coach',
                ['coach_task_followthrough', 'task_created']
            );
        }

        if ($impressions >= 3 && $actionCount === 0) {
            $insights[] = $this->buildInsight(
                'low_engagement',
                'low',
                'Repeated impressions with no engagement',
                $this->label($metrics) . ' is showing repeatedly without clicks, feedback, tasks, or installs.',
                'Consider whether this recommendation should be less prominent for this workspace profile.',
                $metrics,
                $this->dominantSurface($metrics, ['impression']),
                ['low_engagement', 'no_followthrough']
            );
        }

        $mismatch = $this->surfaceMismatch($metrics);
        if ($mismatch !== null) {
            $insights[] = $this->buildInsight(
                'surface_mismatch',
                'medium',
                'Recommendation performs differently by surface',
                $this->label($metrics) . ' gets clicks from ' . $this->surfaceLabel($mismatch['click_surface']) . ' but dismissals from ' . $this->surfaceLabel($mismatch['dismiss_surface']) . '.',
                'Tune copy or placement separately by surface before changing the underlying recommendation rule.',
                $metrics,
                (string) $mismatch['dismiss_surface'],
                ['surface_mismatch', 'surface_quality']
            );
        }

        return $insights;
    }

    private function buildInsight(
        string $type,
        string $severity,
        string $title,
        string $summary,
        string $recommendation,
        array $metrics,
        string $surface,
        array $reasonCodes
    ): array {
        $skillKey = (string) ($metrics['skill_key'] ?? '');

        return [
            'insight_key' => $type . ':' . $skillKey . ':' . $surface,
            'severity' => $severity,
            'title' => $title,
            'summary' => $summary,
            'recommendation' => $recommendation,
            'skill_key' => $skillKey,
            'surface' => $surface,
            'metric_snapshot' => $this->metricSnapshot($metrics),
            'reason_codes' => array_values(array_unique(array_merge($reasonCodes, (array) ($metrics['reason_codes'] ?? [])))),
            'created_from_range' => (array) ($metrics['created_from_range'] ?? []),
        ];
    }

    private function metricSnapshot(array $metrics): array
    {
        $counts = (array) ($metrics['counts'] ?? []);
        $impressions = (int) ($counts['impression'] ?? 0);
        $clicks = (int) ($counts['cta_clicked'] ?? 0);
        $negative = (int) ($counts['dismissed'] ?? 0) + (int) ($counts['snoozed'] ?? 0);

        return [
            'total_events' => (int) ($metrics['total_events'] ?? 0),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'dismissals' => (int) ($counts['dismissed'] ?? 0),
            'snoozes' => (int) ($counts['snoozed'] ?? 0),
            'task_created' => (int) ($counts['task_created'] ?? 0),
            'installs' => (int) ($counts['installed'] ?? 0),
            'click_through_rate' => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0,
            'dismissal_rate' => $impressions > 0 ? round($negative / $impressions, 4) : 0.0,
            'by_surface' => (array) ($metrics['by_surface'] ?? []),
        ];
    }

    private function blankSkillMetrics(string $skillKey, array $filters): array
    {
        $bySurface = [];
        foreach (self::SURFACES as $surface) {
            $bySurface[$surface] = array_fill_keys(self::EVENT_TYPES, 0);
            $bySurface[$surface]['total_events'] = 0;
        }

        return [
            'skill_key' => $skillKey,
            'label' => '',
            'counts' => array_fill_keys(self::EVENT_TYPES, 0),
            'by_surface' => $bySurface,
            'total_events' => 0,
            'reason_codes' => [],
            'created_from_range' => [
                'date_from' => (string) ($filters['date_from'] ?? ''),
                'date_to' => (string) ($filters['date_to'] ?? ''),
            ],
        ];
    }

    private function dominantSurface(array $metrics, array $eventTypes): string
    {
        $bestSurface = 'marketplace';
        $bestCount = -1;
        foreach ((array) ($metrics['by_surface'] ?? []) as $surface => $surfaceCounts) {
            $count = 0;
            foreach ($eventTypes as $eventType) {
                $count += (int) ($surfaceCounts[$eventType] ?? 0);
            }
            if ($count > $bestCount || ($count === $bestCount && strcmp((string) $surface, $bestSurface) < 0)) {
                $bestSurface = (string) $surface;
                $bestCount = $count;
            }
        }

        return $bestSurface;
    }

    private function surfaceMismatch(array $metrics): ?array
    {
        $clickSurface = $this->dominantSurface($metrics, ['cta_clicked']);
        $dismissSurface = $this->dominantSurface($metrics, ['dismissed', 'snoozed']);
        $clicks = (int) (($metrics['by_surface'][$clickSurface]['cta_clicked'] ?? 0));
        $dismissals = (int) (($metrics['by_surface'][$dismissSurface]['dismissed'] ?? 0)) + (int) (($metrics['by_surface'][$dismissSurface]['snoozed'] ?? 0));

        if ($clickSurface !== $dismissSurface && $clicks > 0 && $dismissals > 0) {
            return ['click_surface' => $clickSurface, 'dismiss_surface' => $dismissSurface];
        }

        return null;
    }

    private function firstLabel(string $current, array $row): string
    {
        if ($current !== '') {
            return $current;
        }

        $metadata = $this->decodeJson($row['metadata_json'] ?? null);
        return trim((string) ($metadata['label'] ?? ''));
    }

    private function label(array $metrics): string
    {
        $label = trim((string) ($metrics['label'] ?? ''));
        return $label !== '' ? $label : str_replace('_', ' ', (string) ($metrics['skill_key'] ?? 'Marketplace item'));
    }

    private function surfaceLabel(string $surface): string
    {
        return match ($surface) {
            'clarity_chat' => 'Clarity',
            'coach' => 'Coach',
            default => 'Marketplace',
        };
    }

    private function marketplaceLabel(string $insightKey): string
    {
        $type = explode(':', $insightKey, 2)[0] ?? '';

        return match ($type) {
            'high_dismissal_rate' => 'High dismissal rate',
            'setup_interest' => 'Setup interest',
            'coach_task_followthrough' => 'Task follow-through',
            'low_engagement' => 'Low engagement',
            'surface_mismatch' => 'Surface mismatch',
            'high_interest_low_install' => 'Interest without install',
            default => 'Marketplace insight',
        };
    }

    private function decodeJson($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }
}
