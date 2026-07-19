<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceMarketplaceActivationBundleInsightService
{
    private const EVENT_TYPES = ['bundle_impression', 'cta_clicked', 'selected', 'dismissed', 'completed', 'module_installed'];

    public function getInsights(array $filters = []): array
    {
        return array_slice($this->buildInsights($filters), 0, 8);
    }

    public function marketplaceInsightsByBundle(array $filters = []): array
    {
        $mapped = [];
        foreach ($this->buildInsights($filters) as $insight) {
            $bundleKey = $this->normalizeKey((string) ($insight['bundle_key'] ?? ''));
            if ($bundleKey === '' || isset($mapped[$bundleKey])) {
                continue;
            }

            $mapped[$bundleKey] = [
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

    private function buildInsights(array $filters = []): array
    {
        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '' && $source !== 'marketplace_activation_bundle') {
            return [];
        }

        $eventRows = (new WorkspaceMarketplaceActivationBundleEventService())->getEvents($filters, 1000);
        $stateRows = $this->selectedStateRows($filters);
        if ($eventRows === [] && $stateRows === []) {
            return [];
        }

        $bundleMetrics = [];
        foreach ($eventRows as $row) {
            $bundleKey = $this->normalizeKey((string) ($row['bundle_key'] ?? ''));
            if ($bundleKey === '') {
                continue;
            }
            if (!isset($bundleMetrics[$bundleKey])) {
                $bundleMetrics[$bundleKey] = $this->blankBundleMetrics($bundleKey, $filters);
            }

            $eventType = (string) ($row['event_type'] ?? '');
            if (!in_array($eventType, self::EVENT_TYPES, true)) {
                continue;
            }

            $bundleMetrics[$bundleKey]['counts'][$eventType]++;
            $bundleMetrics[$bundleKey]['total_events']++;
            $bundleMetrics[$bundleKey]['label'] = $this->firstLabel($bundleMetrics[$bundleKey]['label'], $row);
            $bundleMetrics[$bundleKey]['status'] = $this->firstStatus($bundleMetrics[$bundleKey]['status'], $row);
        }

        foreach ($stateRows as $row) {
            $bundleKey = $this->normalizeKey((string) ($row['bundle_key'] ?? ''));
            if ($bundleKey === '') {
                continue;
            }
            if (!isset($bundleMetrics[$bundleKey])) {
                $bundleMetrics[$bundleKey] = $this->blankBundleMetrics($bundleKey, $filters);
            }
            $bundleMetrics[$bundleKey]['status'] = (string) ($row['status'] ?? 'selected');
            $bundleMetrics[$bundleKey]['state_updated_at'] = (string) ($row['updated_at'] ?? '');
            $bundleMetrics[$bundleKey]['label'] = $this->firstStateLabel($bundleMetrics[$bundleKey]['label'], $row);
        }

        $insights = [];
        foreach ($bundleMetrics as $metrics) {
            foreach ($this->insightsForBundle($metrics) as $insight) {
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

            return strcmp((string) ($left['insight_key'] ?? ''), (string) ($right['insight_key'] ?? ''));
        });

        return $insights;
    }

    private function insightsForBundle(array $metrics): array
    {
        $counts = (array) ($metrics['counts'] ?? []);
        $impressions = (int) ($counts['bundle_impression'] ?? 0);
        $clicks = (int) ($counts['cta_clicked'] ?? 0);
        $selected = (int) ($counts['selected'] ?? 0);
        $dismissals = (int) ($counts['dismissed'] ?? 0);
        $completed = (int) ($counts['completed'] ?? 0);
        $installs = (int) ($counts['module_installed'] ?? 0);
        $engagement = $clicks + $selected + $dismissals + $completed + $installs;
        $dismissalRate = $impressions > 0 ? round($dismissals / $impressions, 4) : 0.0;

        $insights = [];
        if (($impressions > 0 || $clicks > 0) && $completed === 0) {
            $insights[] = $this->buildInsight(
                'high_interest_low_completion',
                ($clicks + $selected) >= 2 ? 'medium' : 'low',
                'Interest is not becoming bundle completion',
                $this->label($metrics) . ' has activation signals but no completion in this range.',
                'Review the next action and setup path before changing the bundle definition.',
                $metrics,
                ['high_interest_low_completion', 'completion_gap']
            );
        }

        if ($impressions >= 3 && $dismissals > 0 && $dismissalRate >= 0.4) {
            $insights[] = $this->buildInsight(
                'high_dismissal_rate',
                $dismissalRate >= 0.67 ? 'high' : 'medium',
                'High bundle dismissal rate',
                $this->label($metrics) . ' is being dismissed often compared with impressions.',
                'Check whether this bundle is too prominent or mismatched for the current workspace profile.',
                $metrics,
                ['high_dismissal_rate', 'bundle_feedback']
            );
        }

        if ($clicks > 0 && $installs === 0 && $completed === 0) {
            $insights[] = $this->buildInsight(
                'setup_interest',
                $clicks >= 2 ? 'medium' : 'low',
                'Setup interest needs follow-through',
                $this->label($metrics) . ' is getting next-step clicks without install or completion events.',
                'Inspect setup friction and confirm the CTA lands on the clearest activation step.',
                $metrics,
                ['setup_interest', 'setup_followthrough']
            );
        }

        if ($installs > 0) {
            $insights[] = $this->buildInsight(
                'install_followthrough',
                $installs >= 2 ? 'medium' : 'low',
                'Bundle is driving module installs',
                $this->label($metrics) . ' has bundle-attributed install activity.',
                'Use this as a positive activation signal when reviewing bundle usefulness.',
                $metrics,
                ['install_followthrough', 'module_installed']
            );
        }

        if ($impressions >= 3 && $engagement === 0) {
            $insights[] = $this->buildInsight(
                'low_engagement',
                'low',
                'Repeated bundle impressions with no engagement',
                $this->label($metrics) . ' is showing repeatedly without clicks, selections, dismissals, completions, or installs.',
                'Consider whether the bundle copy or placement needs clearer relevance before adding more surfaces.',
                $metrics,
                ['low_engagement', 'no_followthrough']
            );
        }

        if (($metrics['status'] ?? '') === 'selected' && ($clicks + $installs + $completed) === 0) {
            $insights[] = $this->buildInsight(
                'stale_selected_bundle',
                $impressions > 0 ? 'medium' : 'low',
                'Selected bundle has no recent activation',
                $this->label($metrics) . ' is selected but has no recent setup click, install, or completion signal.',
                'Prompt an operator to revisit the next action or mark the bundle complete when appropriate.',
                $metrics,
                ['stale_selected_bundle', 'selected_without_recent_progress']
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
        array $reasonCodes
    ): array {
        $bundleKey = (string) ($metrics['bundle_key'] ?? '');

        return [
            'insight_key' => $type . ':' . $bundleKey,
            'severity' => $severity,
            'title' => $title,
            'summary' => $summary,
            'recommendation' => $recommendation,
            'bundle_key' => $bundleKey,
            'metric_snapshot' => $this->metricSnapshot($metrics),
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'created_from_range' => (array) ($metrics['created_from_range'] ?? []),
        ];
    }

    private function metricSnapshot(array $metrics): array
    {
        $counts = (array) ($metrics['counts'] ?? []);
        $impressions = (int) ($counts['bundle_impression'] ?? 0);
        $clicks = (int) ($counts['cta_clicked'] ?? 0);
        $selected = (int) ($counts['selected'] ?? 0);
        $completed = (int) ($counts['completed'] ?? 0);

        return [
            'total_events' => (int) ($metrics['total_events'] ?? 0),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'selected' => $selected,
            'dismissals' => (int) ($counts['dismissed'] ?? 0),
            'completed' => $completed,
            'module_installed' => (int) ($counts['module_installed'] ?? 0),
            'click_through_rate' => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0,
            'dismissal_rate' => $impressions > 0 ? round(((int) ($counts['dismissed'] ?? 0)) / $impressions, 4) : 0.0,
            'completion_rate' => $selected > 0 ? round($completed / $selected, 4) : 0.0,
            'current_status' => (string) ($metrics['status'] ?? ''),
        ];
    }

    private function blankBundleMetrics(string $bundleKey, array $filters): array
    {
        return [
            'bundle_key' => $bundleKey,
            'label' => $this->definitionLabel($bundleKey),
            'status' => '',
            'state_updated_at' => '',
            'counts' => array_fill_keys(self::EVENT_TYPES, 0),
            'total_events' => 0,
            'created_from_range' => [
                'date_from' => (string) ($filters['date_from'] ?? ''),
                'date_to' => (string) ($filters['date_to'] ?? ''),
            ],
        ];
    }

    private function selectedStateRows(array $filters): array
    {
        if (!Database::tableExists('workspace_marketplace_activation_bundle_state')) {
            return [];
        }

        $workspaceId = (int) ($filters['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            return [];
        }

        $sql = "SELECT bundle_key, status, metadata_json, updated_at
                FROM workspace_marketplace_activation_bundle_state
                WHERE workspace_id = ? AND status = 'selected'";
        $params = [$workspaceId];
        if (!empty($filters['user_id'])) {
            $sql .= ' AND user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['bundle_key'])) {
            $sql .= ' AND bundle_key = ?';
            $params[] = $this->normalizeKey((string) $filters['bundle_key']);
        }
        $sql .= ' ORDER BY updated_at DESC, bundle_key ASC';

        return Database::query($sql, $params);
    }

    private function firstLabel(string $current, array $row): string
    {
        if ($current !== '') {
            return $current;
        }

        $metadata = $this->decodeJson($row['metadata_json'] ?? null);
        return trim((string) ($metadata['label'] ?? ''));
    }

    private function firstStateLabel(string $current, array $row): string
    {
        if ($current !== '') {
            return $current;
        }

        $metadata = $this->decodeJson($row['metadata_json'] ?? null);
        return trim((string) ($metadata['label'] ?? ''));
    }

    private function firstStatus(string $current, array $row): string
    {
        if ($current !== '') {
            return $current;
        }

        return trim((string) ($row['bundle_status'] ?? ''));
    }

    private function label(array $metrics): string
    {
        $label = trim((string) ($metrics['label'] ?? ''));
        return $label !== '' ? $label : str_replace('_', ' ', (string) ($metrics['bundle_key'] ?? 'Activation bundle'));
    }

    private function definitionLabel(string $bundleKey): string
    {
        $definition = (new WorkspaceMarketplaceActivationBundleService())->definitions()[$bundleKey] ?? null;
        if (is_array($definition) && trim((string) ($definition['label'] ?? '')) !== '') {
            return trim((string) $definition['label']);
        }

        return str_replace('_', ' ', $bundleKey);
    }

    private function marketplaceLabel(string $insightKey): string
    {
        $type = explode(':', $insightKey, 2)[0] ?? '';

        return match ($type) {
            'high_interest_low_completion' => 'Interest without completion',
            'high_dismissal_rate' => 'High dismissal rate',
            'setup_interest' => 'Setup interest',
            'install_followthrough' => 'Install follow-through',
            'low_engagement' => 'Low engagement',
            'stale_selected_bundle' => 'Stale selected bundle',
            default => 'Bundle insight',
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
