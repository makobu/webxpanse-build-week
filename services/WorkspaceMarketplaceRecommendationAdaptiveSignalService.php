<?php

namespace CRM\Services;

class WorkspaceMarketplaceRecommendationAdaptiveSignalService
{
    private const POSITIVE_DELTA = 6;
    private const INTEREST_DELTA = 4;
    private const DISMISSAL_DELTA = -8;
    private const LOW_ENGAGEMENT_DELTA = -5;

    public function signalsBySkill(array $filters = []): array
    {
        $filters = $this->withDefaultWindow($filters);
        $recommendationEvents = (new WorkspaceMarketplaceRecommendationEventService())->getEvents($filters, 1000);
        $journeyEvents = (new WorkspaceMarketplaceSetupJourneyEventService())->getEvents($filters, 1000);
        $insights = (new WorkspaceMarketplaceRecommendationInsightService())->getInsights($filters);

        $metrics = [];
        foreach ($recommendationEvents as $row) {
            $skillKey = $this->normalizeKey((string) ($row['skill_key'] ?? ''));
            if ($skillKey === '') {
                continue;
            }
            $metrics[$skillKey] ??= $this->blankMetrics($skillKey);
            $eventType = (string) ($row['event_type'] ?? '');
            $metrics[$skillKey]['recommendation_counts'][$eventType] = (int) (($metrics[$skillKey]['recommendation_counts'][$eventType] ?? 0) + 1);
            $metrics[$skillKey]['total_events']++;
        }

        foreach ($journeyEvents as $row) {
            $skillKey = $this->normalizeKey((string) ($row['skill_key'] ?? ''));
            if ($skillKey === '') {
                continue;
            }
            $metrics[$skillKey] ??= $this->blankMetrics($skillKey);
            $eventType = (string) ($row['event_type'] ?? '');
            $metrics[$skillKey]['journey_counts'][$eventType] = (int) (($metrics[$skillKey]['journey_counts'][$eventType] ?? 0) + 1);
            $metrics[$skillKey]['total_events']++;
        }

        foreach ($insights as $insight) {
            $skillKey = $this->normalizeKey((string) ($insight['skill_key'] ?? ''));
            if ($skillKey === '') {
                continue;
            }
            $metrics[$skillKey] ??= $this->blankMetrics($skillKey);
            $metrics[$skillKey]['insights'][] = (string) ($insight['insight_key'] ?? '');
            $metrics[$skillKey]['total_events'] += 0;
        }

        $signals = [];
        foreach ($metrics as $skillKey => $metric) {
            $signal = $this->buildSignal($metric, $filters);
            if ((int) ($signal['adaptive_score_delta'] ?? 0) !== 0 || !empty($signal['adaptive_reason_codes'])) {
                $signals[$skillKey] = $signal;
            }
        }

        ksort($signals);
        return $signals;
    }

    public function getSummary(array $filters = []): array
    {
        $signals = $this->signalsBySkill($filters);
        $reasonCounts = [];
        $positive = 0;
        $negative = 0;
        $topSkills = [];

        foreach ($signals as $skillKey => $signal) {
            $delta = (int) ($signal['adaptive_score_delta'] ?? 0);
            if ($delta > 0) {
                $positive++;
            } elseif ($delta < 0) {
                $negative++;
            }
            foreach ((array) ($signal['adaptive_reason_codes'] ?? []) as $reasonCode) {
                $reasonCounts[$reasonCode] = (int) (($reasonCounts[$reasonCode] ?? 0) + 1);
            }
            $topSkills[] = [
                'skill_key' => $skillKey,
                'adaptive_score_delta' => $delta,
                'adaptive_confidence' => (string) ($signal['adaptive_confidence'] ?? 'low'),
                'adaptive_reason_codes' => (array) ($signal['adaptive_reason_codes'] ?? []),
                'total_events' => (int) ($signal['metric_snapshot']['total_events'] ?? 0),
            ];
        }

        usort($topSkills, static function (array $left, array $right): int {
            $impact = abs((int) ($right['adaptive_score_delta'] ?? 0)) <=> abs((int) ($left['adaptive_score_delta'] ?? 0));
            if ($impact !== 0) {
                return $impact;
            }
            $events = ((int) ($right['total_events'] ?? 0)) <=> ((int) ($left['total_events'] ?? 0));
            return $events !== 0 ? $events : strcmp((string) ($left['skill_key'] ?? ''), (string) ($right['skill_key'] ?? ''));
        });
        arsort($reasonCounts);

        return [
            'positive_boosts' => $positive,
            'negative_dampening' => $negative,
            'top_skills' => array_slice($topSkills, 0, 5),
            'reason_counts' => $reasonCounts,
            'total_signals' => count($signals),
        ];
    }

    private function buildSignal(array $metric, array $filters): array
    {
        $recommendation = (array) ($metric['recommendation_counts'] ?? []);
        $journey = (array) ($metric['journey_counts'] ?? []);
        $insights = (array) ($metric['insights'] ?? []);
        $delta = 0;
        $reasonCodes = [];
        $guidance = '';

        $hasMomentum = (int) ($journey['setup_opened'] ?? 0) > 0
            || (int) ($journey['step_completed'] ?? 0) > 0
            || (int) ($recommendation['task_created'] ?? 0) > 0
            || (int) ($recommendation['installed'] ?? 0) > 0;
        if ($hasMomentum) {
            $delta += self::POSITIVE_DELTA;
            $reasonCodes[] = 'adaptive_recent_setup_momentum';
            $guidance = 'Recent setup activity suggests this recommendation is getting useful follow-through.';
        }

        if ($this->hasInsight($insights, ['high_interest_low_install', 'setup_interest'])) {
            $delta += self::INTEREST_DELTA;
            $reasonCodes[] = 'adaptive_interest_needs_setup';
            $guidance = 'People are showing setup interest; keep the next setup step visible and specific.';
        }

        $impressions = (int) ($recommendation['impression'] ?? 0);
        $negative = (int) ($recommendation['dismissed'] ?? 0) + (int) ($recommendation['snoozed'] ?? 0);
        $dismissalRate = $impressions > 0 ? $negative / $impressions : 0.0;
        if ($this->hasInsight($insights, ['high_dismissal_rate']) || ($impressions >= 3 && $dismissalRate >= 0.4)) {
            $delta += self::DISMISSAL_DELTA;
            $reasonCodes[] = 'adaptive_high_dismissal_rate';
            $guidance = 'Dismissals are high, so keep this suggestion quieter until fit or timing improves.';
        }

        $actionCount = (int) ($recommendation['cta_clicked'] ?? 0)
            + (int) ($recommendation['task_created'] ?? 0)
            + (int) ($recommendation['installed'] ?? 0)
            + (int) ($journey['setup_opened'] ?? 0)
            + (int) ($journey['step_completed'] ?? 0)
            + $negative;
        if ($this->hasInsight($insights, ['low_engagement']) || ($impressions >= 3 && $actionCount === 0)) {
            $delta += self::LOW_ENGAGEMENT_DELTA;
            $reasonCodes[] = 'adaptive_low_engagement';
            $guidance = 'Repeated impressions have not produced engagement yet, so this recommendation can be less prominent.';
        }

        $delta = max(-12, min(10, $delta));
        $totalEvents = (int) ($metric['total_events'] ?? 0);

        return [
            'skill_key' => (string) ($metric['skill_key'] ?? ''),
            'adaptive_score_delta' => $delta,
            'adaptive_reason_codes' => array_values(array_unique($reasonCodes)),
            'adaptive_guidance' => $guidance,
            'adaptive_confidence' => $totalEvents >= 5 ? 'high' : ($totalEvents >= 2 ? 'medium' : ($totalEvents > 0 ? 'low' : 'none')),
            'metric_snapshot' => [
                'recommendation_counts' => $recommendation,
                'journey_counts' => $journey,
                'insights' => $insights,
                'total_events' => $totalEvents,
                'date_from' => (string) ($filters['date_from'] ?? ''),
                'date_to' => (string) ($filters['date_to'] ?? ''),
            ],
        ];
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

    private function blankMetrics(string $skillKey): array
    {
        return [
            'skill_key' => $skillKey,
            'recommendation_counts' => [],
            'journey_counts' => [],
            'insights' => [],
            'total_events' => 0,
        ];
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }
}
