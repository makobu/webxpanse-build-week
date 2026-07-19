<?php

namespace CRM\Services;

use CRM\Modules\HRAnalyticsSettings;

/**
 * Read-only compatibility adapter for the pre evidence-gated score contract.
 * It never performs queries or writes and exists only for controlled rollback.
 */
class OrganizationIntelligenceV1Adapter
{
    public function adapt(array $dashboard, ?int $workspaceId = null): array
    {
        $settings = (new HRAnalyticsSettings())->get($workspaceId);
        $thresholds = (array) ($settings['thresholds'] ?? []);
        $employees = [];
        foreach ((array) ($dashboard['employees'] ?? []) as $employee) {
            $role = (string) ($employee['role'] ?? 'general');
            $weights = (array) (($settings['scoring_weights'][$role] ?? null)
                ?: ($settings['scoring_weights']['general'] ?? []));
            $score = $this->score((array) ($employee['metrics'] ?? []), $weights);
            $employee['score'] = $score;
            $employee['legacy_role_score'] = $score;
            $employee['score_status'] = 'legacy_v1';
            $employee['score_eligibility_reason'] = 'Legacy V1 rollback calculation; evidence gates are not applied.';
            $employee['band'] = $this->band($score, $thresholds);
            $employees[] = $employee;
        }

        $scores = array_map(static fn(array $employee): float => (float) ($employee['score'] ?? 0), $employees);
        $average = $scores !== [] ? array_sum($scores) / count($scores) : 0.0;
        $overloaded = count(array_filter($employees, static fn(array $employee): bool => !empty($employee['is_overloaded'])));
        $atRisk = count(array_filter($employees, static fn(array $employee): bool => (string) ($employee['band'] ?? '') === 'at_risk'));
        $staffCount = max(1, count($employees));
        $workloadScore = max(0, 100 - (($overloaded / $staffCount) * 100));
        $riskScore = max(0, 100 - (($atRisk / $staffCount) * 30));
        $readiness = (float) ($dashboard['structural_readiness']['score'] ?? 50);
        $healthScore = (int) round(($average * .48) + ($riskScore * .22) + ($workloadScore * .14) + ($readiness * .16));
        $healthScore = max(0, min(100, $healthScore));

        $dashboard['schema_version'] = 1;
        $dashboard['calculation_version'] = 'oi-score-v1';
        $dashboard['employees'] = $employees;
        $dashboard['summary']['avg_score'] = round($average, 1);
        $dashboard['summary']['staff_count'] = count($employees);
        $dashboard['summary']['overloaded_count'] = $overloaded;
        $dashboard['summary']['at_risk_count'] = $atRisk;
        $dashboard['organization_health'] = [
            'score' => $healthScore,
            'score_status' => 'legacy_v1',
            'label' => $healthScore >= 75 ? 'Strong operating health' : ($healthScore >= 62 ? 'Stable with watchpoints' : ($healthScore >= 48 ? 'Needs leadership attention' : 'Fragile operating health')),
            'confidence' => 'legacy',
            'summary' => 'Legacy V1 rollback calculation. Use only while V2 is paused for this workspace.',
            'components' => [
                'average_score' => round($average, 1),
                'risk_score' => round($riskScore, 1),
                'workload_score' => round($workloadScore, 1),
                'structural_readiness_score' => round($readiness, 1),
            ],
        ];
        $dashboard['data_quality']['calculation_version'] = 'oi-score-v1';
        $dashboard['data_quality']['legacy_rollback'] = true;
        return $dashboard;
    }

    private function score(array $metrics, array $weights): float
    {
        $score = 0.0;
        $weightTotal = 0.0;
        foreach ($weights as $metric => $weight) {
            if (!array_key_exists($metric, $metrics) || $metrics[$metric] === null) {
                continue;
            }
            $score += (float) $metrics[$metric] * (float) $weight;
            $weightTotal += (float) $weight;
        }
        return $weightTotal > 0 ? round(max(0, min(100, $score / $weightTotal)), 1) : 0.0;
    }

    private function band(float $score, array $thresholds): string
    {
        if ($score >= (float) ($thresholds['high_performer'] ?? 75)) {
            return 'high_performer';
        }
        if ($score < (float) ($thresholds['at_risk'] ?? 45)) {
            return 'at_risk';
        }
        if ($score < (float) ($thresholds['needs_coaching'] ?? 55)) {
            return 'needs_coaching';
        }
        return 'steady';
    }
}
