<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Database;

class AIRoleProfileService
{
    public function buildProfile(int $userId, array $operatingContext = []): array
    {
        $resolvedRole = $this->resolveSourceRole($userId);
        return $this->buildProfileFromSourceRole(
            (string) ($resolvedRole['source_role'] ?? 'user'),
            $operatingContext,
            [
                'source_role_origin' => (string) ($resolvedRole['origin'] ?? 'default_fallback'),
                'resolution_trace' => [
                    'auth_role' => $resolvedRole['auth_role'] ?? null,
                    'db_role' => $resolvedRole['db_role'] ?? null,
                    'used_role' => (string) ($resolvedRole['source_role'] ?? 'user'),
                    'fallback_applied' => !empty($resolvedRole['fallback_applied']),
                ],
            ]
        );
    }

    public function buildProfileFromSourceRole(string $sourceRole, array $operatingContext = [], array $metadata = []): array
    {
        $sourceRole = strtolower(trim($sourceRole));
        $roleProfile = $this->mapRoleProfile($sourceRole);
        $blueprint = $this->getRoleBlueprint($roleProfile);

        return [
            'role_profile' => $roleProfile,
            'role_family' => $roleProfile,
            'source_role' => $sourceRole !== '' ? $sourceRole : 'user',
            'source_role_origin' => (string) ($metadata['source_role_origin'] ?? 'explicit_input'),
            'resolution_trace' => (array) ($metadata['resolution_trace'] ?? [
                'auth_role' => null,
                'db_role' => null,
                'used_role' => $sourceRole !== '' ? $sourceRole : 'user',
                'fallback_applied' => false,
            ]),
            'priority_focus' => $this->getPriorityFocus($roleProfile, $operatingContext),
            'prompt_bias' => $this->getPromptBias($roleProfile),
            'execution_horizon' => $blueprint['execution_horizon'],
            'preferred_action_types' => $blueprint['preferred_action_types'],
            'ownership_focus' => $blueprint['ownership_focus'],
            'detail_tolerance' => $blueprint['detail_tolerance'],
            'emphasis_areas' => $blueprint['emphasis_areas'],
            'diagnostic_tags' => $blueprint['diagnostic_tags'],
            'decision_style' => $blueprint['decision_style'],
            'threshold_posture' => $blueprint['threshold_posture'],
            'summary' => $this->buildSummary($roleProfile, $blueprint),
        ];
    }

    public function getRoleRankingSignals(array $profile): array
    {
        $roleProfile = (string) ($profile['role_profile'] ?? 'sales_rep');

        return match ($roleProfile) {
            'founder' => [
                'keyword_weights' => [
                    'revenue' => 5, 'pricing' => 5, 'offer' => 4, 'pipeline' => 4, 'goal' => 4,
                    'deal' => 3, 'company' => 3, 'invoice' => 2, 'strategy' => 3,
                ],
                'bucket_weights' => ['priorities' => 6, 'foundation_gaps' => 5, 'quick_wins' => 2, 'missing_features' => 1],
            ],
            'ops_admin' => [
                'keyword_weights' => [
                    'workflow' => 5, 'automation' => 5, 'process' => 4, 'task' => 4, 'system' => 4,
                    'handoff' => 3, 'template' => 3, 'reliability' => 3, 'sync' => 3,
                ],
                'bucket_weights' => ['priorities' => 5, 'quick_wins' => 4, 'foundation_gaps' => 3, 'missing_features' => 2],
            ],
            'support_operator' => [
                'keyword_weights' => [
                    'customer' => 5, 'reply' => 4, 'response' => 4, 'follow-up' => 4,
                    'continuity' => 4, 'handoff' => 3, 'inbox' => 3, 'support' => 4,
                ],
                'bucket_weights' => ['priorities' => 5, 'quick_wins' => 3, 'foundation_gaps' => 2, 'missing_features' => 1],
            ],
            default => [
                'keyword_weights' => [
                    'lead' => 5, 'outreach' => 4, 'follow-up' => 4, 'deal' => 4, 'pipeline' => 4,
                    'proposal' => 3, 'contact' => 3, 'negotiation' => 3,
                ],
                'bucket_weights' => ['priorities' => 5, 'quick_wins' => 3, 'foundation_gaps' => 2, 'missing_features' => 1],
            ],
        };
    }

    private function resolveSourceRole(int $userId): array
    {
        $authUser = Auth::user() ?? [];
        $authRole = (string) ($authUser['role'] ?? '');
        if ($authRole !== '' && (int) ($authUser['id'] ?? 0) === $userId) {
            return [
                'source_role' => strtolower($authRole),
                'origin' => 'auth_session',
                'auth_role' => strtolower($authRole),
                'db_role' => null,
                'fallback_applied' => false,
            ];
        }

        try {
            $row = Database::queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
            $dbRole = strtolower((string) ($row['role'] ?? 'user'));
            return [
                'source_role' => $dbRole,
                'origin' => 'user_record',
                'auth_role' => null,
                'db_role' => $dbRole,
                'fallback_applied' => false,
            ];
        } catch (\Throwable $e) {
            return [
                'source_role' => 'user',
                'origin' => 'default_fallback',
                'auth_role' => null,
                'db_role' => null,
                'fallback_applied' => true,
            ];
        }
    }

    private function mapRoleProfile(string $sourceRole): string
    {
        return match ($sourceRole) {
            'admin', 'owner' => 'founder',
            'manager', 'marketing', 'ops', 'operations' => 'ops_admin',
            'support', 'viewer' => 'support_operator',
            'sales' => 'sales_rep',
            default => 'sales_rep',
        };
    }

    private function getPriorityFocus(string $roleProfile, array $operatingContext): array
    {
        $readinessGaps = (array) ($operatingContext['feature_state']['readiness_gaps'] ?? []);
        $base = $this->getRoleBlueprint($roleProfile)['emphasis_areas'];

        return array_values(array_unique(array_filter(array_merge($base, $readinessGaps))));
    }

    private function getPromptBias(string $roleProfile): array
    {
        return match ($roleProfile) {
            'founder' => [
                'prioritize' => ['strategy', 'readiness', 'revenue blockers'],
                'deprioritize' => ['narrow admin details'],
                'framing' => 'Use strategic but concrete language tied to revenue movement and founder ownership.',
            ],
            'ops_admin' => [
                'prioritize' => ['automation', 'process clarity', 'system reliability'],
                'deprioritize' => ['high-level positioning advice'],
                'framing' => 'Bias toward operational reliability, control points, and execution hygiene.',
            ],
            'support_operator' => [
                'prioritize' => ['customer follow-through', 'response handling', 'consistency'],
                'deprioritize' => ['broad sales strategy'],
                'framing' => 'Bias toward continuity, risk reduction, and customer-facing next actions.',
            ],
            default => [
                'prioritize' => ['pipeline movement', 'follow-ups', 'contact activity'],
                'deprioritize' => ['broad strategic theory'],
                'framing' => 'Bias toward deal movement, outreach momentum, and conversion progression.',
            ],
        };
    }

    private function buildSummary(string $roleProfile, array $blueprint): string
    {
        return match ($roleProfile) {
            'founder' => 'Founder profile: emphasize revenue direction, readiness gaps, and top-level business priorities across a ' . $blueprint['execution_horizon'] . ' horizon.',
            'ops_admin' => 'Ops/admin profile: emphasize automation quality, process hygiene, and operational follow-through with ' . $blueprint['detail_tolerance'] . ' detail.',
            'support_operator' => 'Support/operator profile: emphasize continuity, response quality, and customer follow-through with strong handoff discipline.',
            default => 'Sales profile: emphasize outreach discipline, pipeline movement, and deal follow-up with a ' . $blueprint['decision_style'] . ' style.',
        };
    }

    private function getRoleBlueprint(string $roleProfile): array
    {
        return match ($roleProfile) {
            'founder' => [
                'execution_horizon' => 'weekly_to_quarterly',
                'preferred_action_types' => ['goal_definition', 'offer_refinement', 'pricing', 'pipeline_focus'],
                'ownership_focus' => 'business_direction_and_revenue_readiness',
                'detail_tolerance' => 'medium',
                'emphasis_areas' => ['revenue clarity', 'goal setting', 'offer readiness', 'pipeline focus'],
                'diagnostic_tags' => ['founder_slice', 'strategic_operator', 'readiness_first'],
                'decision_style' => 'high_leverage_directional',
                'threshold_posture' => 'balanced',
            ],
            'ops_admin' => [
                'execution_horizon' => 'daily_to_weekly',
                'preferred_action_types' => ['workflow_cleanup', 'system_configuration', 'task_discipline', 'handoff_reduction'],
                'ownership_focus' => 'process_reliability_and_operational_visibility',
                'detail_tolerance' => 'high',
                'emphasis_areas' => ['workflow hygiene', 'automation reliability', 'task discipline', 'process visibility'],
                'diagnostic_tags' => ['ops_slice', 'reliability_guard', 'execution_hygiene'],
                'decision_style' => 'operationally_conservative',
                'threshold_posture' => 'conservative',
            ],
            'support_operator' => [
                'execution_horizon' => 'same_day',
                'preferred_action_types' => ['response_followthrough', 'handoff_cleanup', 'continuity_check', 'customer_reply'],
                'ownership_focus' => 'customer_continuity_and_follow_through',
                'detail_tolerance' => 'high',
                'emphasis_areas' => ['customer continuity', 'response quality', 'follow-through', 'handoff clarity'],
                'diagnostic_tags' => ['support_slice', 'continuity_guard', 'customer_risk_reduction'],
                'decision_style' => 'service_consistent',
                'threshold_posture' => 'conservative',
            ],
            default => [
                'execution_horizon' => 'daily_to_weekly',
                'preferred_action_types' => ['follow_up', 'outreach', 'deal_progression', 'pipeline_hygiene'],
                'ownership_focus' => 'pipeline_movement_and_conversion_execution',
                'detail_tolerance' => 'medium',
                'emphasis_areas' => ['pipeline execution', 'follow-up discipline', 'outreach consistency', 'deal progression'],
                'diagnostic_tags' => ['sales_slice', 'momentum_focus', 'conversion_progression'],
                'decision_style' => 'momentum_oriented',
                'threshold_posture' => 'aggressive',
            ],
        };
    }
}
