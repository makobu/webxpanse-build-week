<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Modules\AICoach;
use CRM\Services\AICoachOperatingMaturityService;
use CRM\Tests\DatabaseTestCase;
use ReflectionMethod;

class AICoachOperatingMaturityRecommendationTest extends DatabaseTestCase
{
    public function testOperatingMaturityInjectsDeterministicPriorityRecommendation(): void
    {
        $cases = [
            AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY => [
                'title' => 'Complete the Go To Market Clarity Journey stage',
                'context' => [
                    'startup_journey_context' => [
                        'current_stage_key' => 'go_to_market',
                    ],
                ],
                'conflicts' => [],
            ],
            AICoachOperatingMaturityService::JOURNEY_COMPLETE_FOUNDER_LOOP_NEXT => [
                'title' => 'Start Founder Loop first-deal commitments',
                'context' => [],
                'conflicts' => [],
            ],
            AICoachOperatingMaturityService::FOUNDER_LOOP_ACTIVE => [
                'title' => 'Work Founder Loop commitment: Follow up Beta Agency',
                'context' => [
                    'founder_operating_loop_context' => [
                        'current_commitment' => [
                            'title' => 'Follow up Beta Agency',
                        ],
                    ],
                ],
                'conflicts' => [],
            ],
            AICoachOperatingMaturityService::OPERATING_SYSTEM_ACTIVE => [
                'title' => 'Resolve the strongest Journey/evidence conflict',
                'context' => [],
                'conflicts' => [
                    [
                        'severity' => 'high',
                        'operating_evidence' => 'Paid invoice evidence is below the target deal value.',
                        'suggested_next_action' => 'Review pricing evidence in Founder Loop.',
                    ],
                ],
            ],
        ];

        $method = new ReflectionMethod(AICoach::class, 'applyOperatingMaturityRecommendations');
        $method->setAccessible(true);
        $coach = new AICoach();

        foreach ($cases as $stage => $case) {
            $recommendations = $method->invoke(
                $coach,
                [
                    'why_this_matters' => '',
                    'priorities' => [],
                    'quick_wins' => [],
                    'missing_features' => [],
                    'foundation_gaps' => [],
                ],
                (array) ($case['context'] ?? []),
                ['stage' => $stage],
                (array) ($case['conflicts'] ?? [])
            );

            $this->assertSame($case['title'], (string) ($recommendations['priorities'][0]['title'] ?? ''), $stage);
        }
    }

    public function testOperatingMaturityDoesNotMaskMarketplaceSetupGate(): void
    {
        $method = new ReflectionMethod(AICoach::class, 'applyOperatingMaturityRecommendations');
        $method->setAccessible(true);
        $coach = new AICoach();

        $recommendations = $method->invoke(
            $coach,
            [
                'priorities' => [],
                'quick_wins' => [],
                'missing_features' => [
                    [
                        'title' => 'Complete one advice skill for AI Coach',
                        'source_recommendation_type' => 'coach_setup_required',
                    ],
                ],
                'foundation_gaps' => [],
            ],
            [],
            ['stage' => AICoachOperatingMaturityService::JOURNEY_COMPLETE_FOUNDER_LOOP_NEXT],
            []
        );

        $this->assertSame([], (array) ($recommendations['priorities'] ?? ['unexpected']));
        $this->assertSame('coach_setup_required', (string) ($recommendations['missing_features'][0]['source_recommendation_type'] ?? ''));
    }

    public function testFinancialEvidenceSummaryCarriesFinanceSignalsForCoachUI(): void
    {
        $method = new ReflectionMethod(AICoach::class, 'financialEvidenceSummary');
        $method->setAccessible(true);
        $coach = new AICoach();

        $summary = $method->invoke($coach, [
            'finance_context' => [
                'period' => ['date_from' => '2026-05-01', 'date_to' => '2026-05-31'],
                'currency' => 'USD',
                'money_in' => 1200,
                'money_out' => 700,
                'runway_months' => 2.4,
                'target_deal_value' => 1000,
                'avg_paid_invoice' => 400,
                'paid_invoice_count' => 2,
                'paid_customer_count' => 1,
                'guidance_flags' => ['runway_below_three_months'],
            ],
            'founder_operating_loop_context' => [
                'pricing_warnings' => ['CAC is above the target CAC.'],
                'financial_next_actions' => [
                    ['type' => 'pricing_evidence', 'label' => 'Review pricing evidence'],
                ],
            ],
        ], [
            [
                'type' => 'pricing',
                'severity' => 'high',
                'operating_evidence' => 'Average paid invoice is below the target deal value.',
            ],
        ]);

        $this->assertSame(1200.0, (float) ($summary['revenue_this_month'] ?? 0));
        $this->assertSame(700.0, (float) ($summary['expenses_this_month'] ?? 0));
        $this->assertSame(2.4, (float) ($summary['runway_months'] ?? 0));
        $this->assertSame(1000.0, (float) ($summary['target_deal_value'] ?? 0));
        $this->assertSame(400.0, (float) ($summary['avg_paid_invoice'] ?? 0));
        $this->assertSame('pricing', (string) ($summary['top_conflict']['type'] ?? ''));
        $this->assertSame('pricing_evidence', (string) ($summary['financial_next_actions'][0]['type'] ?? ''));
    }
}
