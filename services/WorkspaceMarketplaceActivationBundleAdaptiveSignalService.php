<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceMarketplaceActivationBundleAdaptiveSignalService
{
    private const POSITIVE_DELTA = 6;
    private const INTEREST_DELTA = 4;
    private const DISMISSAL_DELTA = -8;
    private const LOW_ENGAGEMENT_DELTA = -5;

    public function signalsByBundle(array $filters = []): array
    {
        $filters = $this->withDefaultWindow($filters);
        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '' && $source !== 'marketplace_activation_bundle') {
            return [];
        }

        $eventRows = (new WorkspaceMarketplaceActivationBundleEventService())->getEvents($filters, 1000);
        $insights = (new WorkspaceMarketplaceActivationBundleInsightService())->getInsights($filters);
        $stateRows = $this->selectedStateRows($filters);

        $metrics = [];
        foreach ($eventRows as $row) {
            $bundleKey = $this->normalizeKey((string) ($row['bundle_key'] ?? ''));
            if ($bundleKey === '') {
                continue;
            }
            $metrics[$bundleKey] ??= $this->blankMetrics($bundleKey);
            $eventType = (string) ($row['event_type'] ?? '');
            $metrics[$bundleKey]['event_counts'][$eventType] = (int) (($metrics[$bundleKey]['event_counts'][$eventType] ?? 0) + 1);
            $metrics[$bundleKey]['total_events']++;
        }

        foreach ($insights as $insight) {
            $bundleKey = $this->normalizeKey((string) ($insight['bundle_key'] ?? ''));
            if ($bundleKey === '') {
                continue;
            }
            $metrics[$bundleKey] ??= $this->blankMetrics($bundleKey);
            $metrics[$bundleKey]['insights'][] = (string) ($insight['insight_key'] ?? '');
        }

        foreach ($stateRows as $row) {
            $bundleKey = $this->normalizeKey((string) ($row['bundle_key'] ?? ''));
            if ($bundleKey === '') {
                continue;
            }
            $metrics[$bundleKey] ??= $this->blankMetrics($bundleKey);
            $metrics[$bundleKey]['current_status'] = (string) ($row['status'] ?? 'selected');
        }

        $signals = [];
        foreach ($metrics as $bundleKey => $metric) {
            $signal = $this->buildSignal($metric, $filters);
            if ((int) ($signal['adaptive_score_delta'] ?? 0) !== 0 || !empty($signal['adaptive_reason_codes'])) {
                $signals[$bundleKey] = $signal;
            }
        }

        ksort($signals);
        return $signals;
    }

    public function getSummary(array $filters = []): array
    {
        $signals = $this->signalsByBundle($filters);
        $reasonCounts = [];
        $positive = 0;
        $negative = 0;
        $topBundles = [];

        foreach ($signals as $bundleKey => $signal) {
            $delta = (int) ($signal['adaptive_score_delta'] ?? 0);
            if ($delta > 0) {
                $positive++;
            } elseif ($delta < 0) {
                $negative++;
            }
            foreach ((array) ($signal['adaptive_reason_codes'] ?? []) as $reasonCode) {
                $reasonCounts[$reasonCode] = (int) (($reasonCounts[$reasonCode] ?? 0) + 1);
            }
            $topBundles[] = [
                'bundle_key' => $bundleKey,
                'adaptive_score_delta' => $delta,
                'adaptive_confidence' => (string) ($signal['adaptive_confidence'] ?? 'low'),
                'adaptive_reason_codes' => (array) ($signal['adaptive_reason_codes'] ?? []),
                'total_events' => (int) ($signal['metric_snapshot']['total_events'] ?? 0),
            ];
        }

        usort($topBundles, static function (array $left, array $right): int {
            $impact = abs((int) ($right['adaptive_score_delta'] ?? 0)) <=> abs((int) ($left['adaptive_score_delta'] ?? 0));
            if ($impact !== 0) {
                return $impact;
            }
            $events = ((int) ($right['total_events'] ?? 0)) <=> ((int) ($left['total_events'] ?? 0));
            return $events !== 0 ? $events : strcmp((string) ($left['bundle_key'] ?? ''), (string) ($right['bundle_key'] ?? ''));
        });
        arsort($reasonCounts);

        return [
            'positive_boosts' => $positive,
            'negative_dampening' => $negative,
            'top_bundles' => array_slice($topBundles, 0, 5),
            'reason_counts' => $reasonCounts,
            'total_signals' => count($signals),
        ];
    }

    private function buildSignal(array $metric, array $filters): array
    {
        $events = (array) ($metric['event_counts'] ?? []);
        $insights = (array) ($metric['insights'] ?? []);
        $delta = 0;
        $reasonCodes = [];
        $guidance = '';

        $hasMomentum = (int) ($events['cta_clicked'] ?? 0) > 0
            || (int) ($events['module_installed'] ?? 0) > 0
            || (int) ($events['completed'] ?? 0) > 0
            || (int) ($events['selected'] ?? 0) > 0
            || (string) ($metric['current_status'] ?? '') === 'selected';
        if ($hasMomentum) {
            $delta += self::POSITIVE_DELTA;
            $reasonCodes[] = 'adaptive_bundle_activation_momentum';
            $guidance = 'Recent bundle activity suggests this activation path is getting useful follow-through.';
        }

        if ((int) ($events['cta_clicked'] ?? 0) > 0
            && $this->hasInsight($insights, ['high_interest_low_completion', 'setup_interest'])) {
            $delta += self::INTEREST_DELTA;
            $reasonCodes[] = 'adaptive_bundle_interest_needs_setup';
            $guidance = 'There is bundle interest without enough completion yet; keep the next step clear.';
        }

        $impressions = (int) ($events['bundle_impression'] ?? 0);
        $dismissals = (int) ($events['dismissed'] ?? 0);
        $dismissalRate = $impressions > 0 ? $dismissals / $impressions : 0.0;
        if ($this->hasInsight($insights, ['high_dismissal_rate']) || ($impressions >= 3 && $dismissalRate >= 0.4)) {
            $delta += self::DISMISSAL_DELTA;
            $reasonCodes[] = 'adaptive_bundle_high_dismissal_rate';
            $guidance = 'Dismissals are high, so keep this activation bundle quieter until fit or timing improves.';
        }

        $actionCount = (int) ($events['cta_clicked'] ?? 0)
            + (int) ($events['selected'] ?? 0)
            + (int) ($events['completed'] ?? 0)
            + (int) ($events['module_installed'] ?? 0)
            + $dismissals;
        if ($this->hasInsight($insights, ['low_engagement', 'stale_selected_bundle'])
            || ($impressions >= 3 && $actionCount === 0)) {
            $delta += self::LOW_ENGAGEMENT_DELTA;
            $reasonCodes[] = $this->hasInsight($insights, ['stale_selected_bundle'])
                ? 'adaptive_bundle_stale_selected'
                : 'adaptive_bundle_low_engagement';
            $guidance = $this->hasInsight($insights, ['stale_selected_bundle'])
                ? 'This bundle is selected but lacks recent activation; review the next action before pushing it harder.'
                : 'Repeated impressions have not produced engagement yet, so this bundle can be less prominent.';
        }

        $delta = max(-10, min(10, $delta));
        $totalEvents = (int) ($metric['total_events'] ?? 0);

        return [
            'bundle_key' => (string) ($metric['bundle_key'] ?? ''),
            'adaptive_score_delta' => $delta,
            'adaptive_reason_codes' => array_values(array_unique($reasonCodes)),
            'adaptive_guidance' => $guidance,
            'adaptive_confidence' => $totalEvents >= 5 ? 'high' : ($totalEvents >= 2 ? 'medium' : ($totalEvents > 0 ? 'low' : (!empty($metric['current_status']) ? 'low' : 'none'))),
            'metric_snapshot' => [
                'event_counts' => $events,
                'insights' => $insights,
                'current_status' => (string) ($metric['current_status'] ?? ''),
                'total_events' => $totalEvents,
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

        $sql = "SELECT bundle_key, status, updated_at
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

    private function hasInsight(array $insights, array $types): bool
    {
        foreach ($insights as $insightKey) {
            foreach ($types as $type) {
                if (str_starts_with((string) $insightKey, $type . ':')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function withDefaultWindow(array $filters): array
    {
        $filters['date_from'] = !empty($filters['date_from']) ? (string) $filters['date_from'] : date('Y-m-d', strtotime('-30 days'));
        $filters['date_to'] = !empty($filters['date_to']) ? (string) $filters['date_to'] : date('Y-m-d');
        return $filters;
    }

    private function blankMetrics(string $bundleKey): array
    {
        return [
            'bundle_key' => $bundleKey,
            'event_counts' => [],
            'insights' => [],
            'current_status' => '',
            'total_events' => 0,
        ];
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }
}
