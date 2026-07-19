<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;

class OrganizationIntelligenceContextService
{
    public const ROOMS = ['brief', 'people', 'analytics', 'priorities', 'methodology', 'settings'];
    public const SUB_ROOMS = ['leadership', 'action_plan', 'tasks', 'access', 'diagnostics', ''];
    public const TIMEFRAMES = ['today', 'week', 'month', 'quarter', 'year'];
    public const ROLE_FAMILIES = ['', 'marketing', 'sales', 'general'];

    public function build(int $workspaceId, int $userId, array $input): array
    {
        $requester = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);
        if (!$requester || !Authorization::can('hr.analytics.view', $requester)) {
            throw new \RuntimeException('Organization Intelligence access is required.');
        }
        $room = strtolower(trim((string) ($input['room'] ?? 'brief')));
        if ($room === 'settings') {
            $room = 'methodology';
        }
        $subRoom = strtolower(trim((string) ($input['sub_room'] ?? '')));
        $timeframe = strtolower(trim((string) ($input['timeframe'] ?? 'month')));
        $role = strtolower(trim((string) ($input['role'] ?? '')));
        if (!in_array($room, self::ROOMS, true)) {
            throw new \InvalidArgumentException('Unknown Organization Intelligence room.');
        }
        if (!in_array($subRoom, self::SUB_ROOMS, true)) {
            throw new \InvalidArgumentException('Unknown Organization Intelligence sub-room.');
        }
        if (!in_array($timeframe, self::TIMEFRAMES, true)) {
            throw new \InvalidArgumentException('Unknown Organization Intelligence timeframe.');
        }
        if (!in_array($role, self::ROLE_FAMILIES, true)) {
            throw new \InvalidArgumentException('Unknown Organization Intelligence role family.');
        }
        $requestedTargetUserId = !empty($input['user_id']) ? (int) $input['user_id'] : null;
        $targetUserId = (new AnalyticsWorkspaceService())->ensureScopedUserId($requestedTargetUserId, $workspaceId);
        if ($requestedTargetUserId !== null && $targetUserId === null) {
            (new OrganizationIntelligenceMonitoringService())->recordSignal($workspaceId, 'scope', 'oi_cross_workspace_rejection', $userId, true);
            throw new \InvalidArgumentException('Person is outside the active workspace scope.');
        }
        $department = $this->scopedDepartment($workspaceId, (string) ($input['department'] ?? ''), $userId);
        $filters = ['timeframe' => $timeframe, 'role' => $role, 'department' => $department, 'user_id' => $targetUserId];
        $dashboard = (new OrganizationIntelligenceEngineService())->buildDashboard($filters, false, [
            'capability' => 'clarity_context',
            'swot' => false,
            'manager_tips' => false,
            'strategic_pointers' => false,
        ]);
        $profile = (array) ($dashboard['organization_profile'] ?? []);
        $questionIntent = (array) ($input['question_intent'] ?? []);
        $context = [
            'source' => 'server_owned_organization_intelligence',
            'schema_version' => 2,
            'room' => $room,
            'sub_room' => $subRoom,
            'scope' => $filters,
            'organization_profile' => $profile,
            'data_quality' => (array) ($dashboard['data_quality'] ?? []),
            'structural_readiness' => (array) ($dashboard['structural_readiness'] ?? []),
            'organization_health' => (array) ($dashboard['organization_health'] ?? []),
        ];
        if ($room === 'brief') {
            $context += [
                'founder_brief' => (array) ($dashboard['founder_brief'] ?? []),
                'founder_load' => (array) ($dashboard['founder_load'] ?? []),
                'priorities' => array_slice((array) ($dashboard['leadership_attention'] ?? []), 0, 5),
            ];
        } elseif ($room === 'people') {
            $functionProfiles = (array) ($dashboard['user_function_profiles'] ?? []);
            $context += [
                'people' => array_slice(array_map(static function (array $employee) use ($functionProfiles): array {
                    $userId = (int) ($employee['id'] ?? 0);
                    $personProfile = (array) ($functionProfiles[$userId] ?? []);
                    return [
                        'user_id' => $userId,
                        'name' => (string) ($employee['name'] ?? ''),
                        'score' => $employee['score'] ?? null,
                        'score_status' => (string) ($employee['score_status'] ?? 'insufficient_evidence'),
                        'score_band' => (string) ($personProfile['score_band'] ?? $employee['band'] ?? 'insufficient_evidence'),
                        'risk_reason' => (string) ($personProfile['risk_reason'] ?? ''),
                        'evidence_coverage' => (float) ($employee['evidence_coverage'] ?? 0),
                        'open_tasks' => (int) ($employee['open_tasks'] ?? 0),
                        'overdue_open_tasks' => (int) ($employee['overdue_open_tasks'] ?? 0),
                        'manager_action' => (string) ($personProfile['manager_action'] ?? ''),
                    ];
                }, (array) ($dashboard['employees'] ?? [])), 0, 12),
                'people_risks' => array_slice((array) ($dashboard['people_risks'] ?? []), 0, 8),
            ];
        } elseif ($room === 'analytics') {
            $window = (array) ($dashboard['operating_trends']['window'] ?? []);
            $start = (string) ($window['start'] ?? date('Y-m-d 00:00:00', strtotime('-30 days')));
            $end = (string) ($window['end'] ?? date('Y-m-d 23:59:59'));
            $stageHistory = new ContactStageHistoryService();
            $context += [
                'trends' => (array) ($dashboard['operating_trends'] ?? []),
                'function_coverage' => (array) ($dashboard['function_coverage'] ?? []),
                'department_intelligence' => (array) ($dashboard['department_intelligence'] ?? []),
                'pipeline' => [
                    'stage_distribution' => $stageHistory->distribution($workspaceId, $start, $end, $targetUserId, $role, $department),
                    'conversion_journey' => $stageHistory->journey($workspaceId, $start, $end, $targetUserId, $role, $department),
                ],
                'metric_definitions' => [
                    'stage_distribution' => 'Point-in-time count and share by current contact stage; not conversion.',
                    'organization_health' => 'Evidence-gated execution health combined with structural readiness.',
                    'structural_readiness' => 'Function ownership, continuity, and applicable organization structure.',
                ],
                'calculation_version' => (string) ($dashboard['calculation_version'] ?? OrganizationIntelligenceSnapshotService::CALCULATION_VERSION),
            ];
        } elseif ($room === 'priorities') {
            $context += [
                'priorities' => array_slice((array) ($dashboard['leadership_attention'] ?? []), 0, 8),
                'people_risks' => array_slice((array) ($dashboard['people_risks'] ?? []), 0, 8),
            ];
            if (Authorization::can('hr.analytics.settings', $requester)) {
                $context['snapshot_diagnostics'] = (new OrganizationIntelligenceSnapshotService())->diagnostics($workspaceId);
            }
        } else {
            $context += [
                'settings' => (array) ($dashboard['settings'] ?? []),
                'evidence_policy' => (array) ($dashboard['data_quality']['evidence_policy'] ?? []),
            ];
        }
        if (
            (string) ($questionIntent['intent'] ?? '') === 'explanation'
            && !empty($questionIntent['references_score'])
        ) {
            $context['score_explanation'] = $this->scoreExplanation($dashboard, $targetUserId);
        }
        return ['context' => $context, 'dashboard' => $dashboard, 'filters' => $filters, 'room' => $room, 'sub_room' => $subRoom];
    }

    /** @return array<string,mixed> */
    private function scoreExplanation(array $dashboard, ?int $targetUserId): array
    {
        if ($targetUserId === null || $targetUserId <= 0) {
            $health = (array) ($dashboard['organization_health'] ?? []);
            $components = (array) ($health['components'] ?? []);
            $riskExplanation = (array) ($health['risk_score_explanation'] ?? []);
            if (($components['risk_score'] ?? null) !== null) {
                return [
                    'status' => 'available',
                    'scope' => 'organization',
                    'metric' => 'risk_score',
                    'label' => 'Risk Score',
                    'score' => (float) $components['risk_score'],
                    'meaning' => (string) ($riskExplanation['meaning'] ?? 'Higher is healthier; the score reflects remaining resilience after risk penalties.'),
                    'answer_lead' => sprintf(
                        'The organization Risk Score of %.0f is a resilience score: higher is healthier, and it does not mean the organization has that percentage of risk.',
                        (float) $components['risk_score']
                    ),
                    'calculation_version' => (string) ($dashboard['calculation_version'] ?? ''),
                    'contributors' => [
                        'starting_score' => (float) ($riskExplanation['starting_score'] ?? 100),
                        'people_risk_penalty' => (float) ($riskExplanation['people_risk_penalty'] ?? 0),
                        'at_risk_share_penalty' => (float) ($riskExplanation['at_risk_share_penalty'] ?? 0),
                        'department_spread_penalty' => (float) ($riskExplanation['department_spread_penalty'] ?? 0),
                        'risk_item_count' => (int) ($riskExplanation['risk_item_count'] ?? 0),
                        'high_risk_item_count' => (int) ($riskExplanation['high_risk_item_count'] ?? 0),
                    ],
                    'formula' => (string) ($riskExplanation['formula'] ?? '100 minus current organization risk penalties.'),
                    'confidence' => (string) ($health['confidence'] ?? 'low'),
                ];
            }

            return [
                'status' => 'person_required',
                'message' => 'Select a staff member or ask about your own score so Clarity can explain one calculation safely.',
            ];
        }

        $employee = null;
        foreach ((array) ($dashboard['employees'] ?? []) as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $targetUserId) {
                $employee = (array) $candidate;
                break;
            }
        }
        if ($employee === null) {
            return ['status' => 'not_found', 'message' => 'The selected person is not present in the current scoped evidence.'];
        }

        $profiles = (array) ($dashboard['user_function_profiles'] ?? []);
        $profile = (array) ($profiles[$targetUserId] ?? []);
        $settings = (array) ($dashboard['settings'] ?? []);
        $thresholds = (array) ($settings['thresholds'] ?? []);
        $role = (string) ($employee['role'] ?? 'general');
        $metricWeights = (array) ($settings['scoring_weights'][$role] ?? $settings['scoring_weights']['general'] ?? []);
        $metrics = (array) ($employee['metrics'] ?? []);
        $representedWeight = 0.0;
        foreach ($metricWeights as $metric => $weight) {
            if (array_key_exists($metric, $metrics) && $metrics[$metric] !== null) {
                $representedWeight += (float) $weight;
            }
        }

        $metricContributions = [];
        foreach ($metricWeights as $metric => $weight) {
            $value = $metrics[$metric] ?? null;
            $included = $value !== null && $representedWeight > 0;
            $effectiveWeight = $included ? (float) $weight / $representedWeight : 0.0;
            $metricContributions[] = [
                'metric' => (string) $metric,
                'value' => $included ? round((float) $value, 1) : null,
                'configured_weight' => round((float) $weight, 4),
                'effective_weight' => round($effectiveWeight, 4),
                'contribution_points' => $included ? round(((float) $value) * $effectiveWeight, 2) : null,
                'evidence_available' => $included,
            ];
        }
        usort($metricContributions, static fn(array $a, array $b): int => ((float) ($b['effective_weight'] ?? 0)) <=> ((float) ($a['effective_weight'] ?? 0)));

        $measurableFunctions = array_values(array_filter(
            (array) ($employee['function_profiles'] ?? []),
            static fn(array $function): bool => ($function['score'] ?? null) !== null
        ));
        $functionWeightTotal = 0.0;
        foreach ($measurableFunctions as $function) {
            $weight = !empty($function['is_primary']) ? 1.35 : 0.85;
            if ((string) ($function['confidence'] ?? 'low') === 'low') {
                $weight *= 0.55;
            }
            $functionWeightTotal += $weight;
        }
        $functionContributions = [];
        foreach ($measurableFunctions as $function) {
            $weight = !empty($function['is_primary']) ? 1.35 : 0.85;
            if ((string) ($function['confidence'] ?? 'low') === 'low') {
                $weight *= 0.55;
            }
            $effectiveWeight = $functionWeightTotal > 0 ? $weight / $functionWeightTotal : 0.0;
            $functionContributions[] = [
                'function' => (string) ($function['name'] ?? $function['slug'] ?? ''),
                'score' => round((float) ($function['score'] ?? 0), 1),
                'confidence' => (string) ($function['confidence'] ?? 'low'),
                'is_primary' => !empty($function['is_primary']),
                'effective_weight' => round($effectiveWeight, 4),
                'contribution_points' => round(((float) ($function['score'] ?? 0)) * $effectiveWeight, 2),
                'evidence' => (string) ($function['evidence'] ?? ''),
                'source' => (string) ($function['source'] ?? ''),
            ];
        }
        usort($functionContributions, static fn(array $a, array $b): int => ((float) ($b['contribution_points'] ?? 0)) <=> ((float) ($a['contribution_points'] ?? 0)));

        return [
            'status' => 'available',
            'user_id' => $targetUserId,
            'name' => (string) ($employee['name'] ?? ''),
            'score' => array_key_exists('score', $employee) && $employee['score'] !== null ? (float) $employee['score'] : null,
            'score_status' => (string) ($employee['score_status'] ?? 'insufficient_evidence'),
            'score_band' => (string) ($profile['score_band'] ?? $employee['band'] ?? 'insufficient_evidence'),
            'risk_reason' => (string) ($profile['risk_reason'] ?? ''),
            'calculation_version' => (string) ($dashboard['calculation_version'] ?? ''),
            'thresholds' => [
                'at_risk' => $thresholds['at_risk'] ?? null,
                'needs_coaching' => $thresholds['needs_coaching'] ?? null,
                'high_performer' => $thresholds['high_performer'] ?? null,
            ],
            'evidence' => [
                'coverage' => (float) ($employee['evidence_coverage'] ?? 0),
                'event_count' => (int) ($employee['evidence_event_count'] ?? 0),
                'families' => array_values((array) ($employee['evidence_families'] ?? [])),
                'eligibility_reason' => (string) ($employee['score_eligibility_reason'] ?? ''),
            ],
            'source_metrics' => (array) ($profile['source_metrics'] ?? []),
            'metric_contributions' => $metricContributions,
            'function_contributions' => $functionContributions,
            'formula' => $functionContributions !== []
                ? 'Weighted average of measurable function scores; primary functions receive more weight and low-confidence functions are down-weighted.'
                : 'Role-weighted average of available normalized metrics.',
            'manager_action' => (string) ($profile['manager_action'] ?? ''),
            'confidence' => (string) ($profile['confidence'] ?? 'low'),
        ];
    }

    private function scopedDepartment(int $workspaceId, string $value, int $userId): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $row = Database::queryOne(
            'SELECT id FROM departments WHERE workspace_id = ? AND (LOWER(slug) = ? OR LOWER(name) = ?) LIMIT 1',
            [$workspaceId, strtolower($value), strtolower($value)]
        );
        if (!$row) {
            (new OrganizationIntelligenceMonitoringService())->recordSignal($workspaceId, 'scope', 'oi_cross_workspace_rejection', $userId, true);
            throw new \InvalidArgumentException('Department is outside the active workspace scope.');
        }
        return $value;
    }
}
