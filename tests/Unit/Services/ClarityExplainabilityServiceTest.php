<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\ClarityExplanationContextService;
use CRM\Services\ClarityQuestionIntentService;
use CRM\Services\HRAnalyticsService;
use CRM\Services\OrganizationIntelligenceContextService;
use PHPUnit\Framework\TestCase;

class ClarityExplainabilityServiceTest extends TestCase
{
    public function testWhyRiskScoreQuestionGetsExplanationIntentAndMediumReasoning(): void
    {
        $intent = (new ClarityQuestionIntentService())->classify(
            'Why is my risk score so high?',
            'hr_analytics.php'
        );

        $this->assertSame('explanation', $intent['intent']);
        $this->assertSame('medium', $intent['reasoning_effort']);
        $this->assertTrue($intent['references_self']);
        $this->assertTrue($intent['references_score']);
    }

    public function testGeneralQuestionStaysLowReasoning(): void
    {
        $intent = (new ClarityQuestionIntentService())->classify('Where are my tasks?', 'tasks.php');

        $this->assertSame('general', $intent['intent']);
        $this->assertSame('low', $intent['reasoning_effort']);
        $this->assertFalse($intent['requires_reasoning']);
    }

    public function testExplanationContextCarriesServerOwnedFactorsAndEvidenceRules(): void
    {
        $intent = (new ClarityQuestionIntentService())->classify('Explain my score', 'hr_analytics.php');
        $context = (new ClarityExplanationContextService())->build(
            'Explain my score',
            'hr_analytics.php',
            $intent,
            ['page_data_signals' => [['label' => 'People score', 'value' => 42]]],
            [
                'score_explanation' => [
                    'status' => 'available',
                    'score' => 42,
                    'metric_contributions' => [['metric' => 'task_completion', 'value' => 35]],
                ],
                'data_quality' => ['confidence' => 'medium'],
            ]
        );

        $this->assertSame('server_owned_clarity_explanation', $context['source']);
        $this->assertSame(42, $context['evidence']['score_explanation']['score']);
        $this->assertSame('medium', $context['evidence']['data_quality']['confidence']);
        $this->assertStringContainsString('direct evidence-supported cause', $context['internal_analysis_rules'][0]);
        $this->assertStringContainsString('natural language', $context['public_output_rule'][0]);
        $this->assertStringContainsString('raw field names', $context['public_output_rule'][1]);
    }

    public function testOrganizationScoreExplanationIncludesWeightedContributions(): void
    {
        $method = new \ReflectionMethod(OrganizationIntelligenceContextService::class, 'scoreExplanation');
        $method->setAccessible(true);
        $explanation = $method->invoke(new OrganizationIntelligenceContextService(), [
            'calculation_version' => 'oi-v2',
            'settings' => [
                'thresholds' => ['at_risk' => 45, 'needs_coaching' => 60, 'high_performer' => 85],
                'scoring_weights' => ['general' => ['task_completion' => 0.7, 'activity' => 0.3]],
            ],
            'employees' => [[
                'id' => 7,
                'name' => 'Amina',
                'role' => 'general',
                'score' => 41.5,
                'score_status' => 'eligible',
                'band' => 'at_risk',
                'metrics' => ['task_completion' => 30, 'activity' => 68.3],
                'evidence_coverage' => 0.8,
                'evidence_event_count' => 12,
                'evidence_families' => ['tasks', 'activities'],
                'function_profiles' => [],
            ]],
            'user_function_profiles' => [
                7 => ['risk_reason' => 'Low completion is the main support signal.', 'confidence' => 'medium'],
            ],
        ], 7);

        $this->assertSame('available', $explanation['status']);
        $this->assertSame(41.5, $explanation['score']);
        $this->assertSame('task_completion', $explanation['metric_contributions'][0]['metric']);
        $this->assertSame(21.0, $explanation['metric_contributions'][0]['contribution_points']);
        $this->assertSame('Role-weighted average of available normalized metrics.', $explanation['formula']);
    }

    public function testOrganizationRiskScoreExplanationDoesNotRequirePersonSelection(): void
    {
        $method = new \ReflectionMethod(OrganizationIntelligenceContextService::class, 'scoreExplanation');
        $method->setAccessible(true);
        $explanation = $method->invoke(new OrganizationIntelligenceContextService(), [
            'calculation_version' => 'oi-v2',
            'organization_health' => [
                'confidence' => 'moderate',
                'components' => ['risk_score' => 71.0],
                'risk_score_explanation' => [
                    'meaning' => 'Higher is healthier.',
                    'starting_score' => 100.0,
                    'people_risk_penalty' => 29.0,
                    'at_risk_share_penalty' => 0.0,
                    'department_spread_penalty' => 0.0,
                    'risk_item_count' => 4,
                    'high_risk_item_count' => 2,
                    'formula' => '100 minus current organization risk penalties.',
                ],
            ],
        ], null);

        $this->assertSame('available', $explanation['status']);
        $this->assertSame('organization', $explanation['scope']);
        $this->assertSame('risk_score', $explanation['metric']);
        $this->assertSame(71.0, $explanation['score']);
        $this->assertSame(29.0, $explanation['contributors']['people_risk_penalty']);
        $this->assertStringContainsString('Higher is healthier', $explanation['meaning']);
        $this->assertStringContainsString('does not mean', $explanation['answer_lead']);
    }

    public function testPeopleRiskReasonUsesNestedTaskCompletionMetric(): void
    {
        $method = new \ReflectionMethod(HRAnalyticsService::class, 'peopleProfileRiskReason');
        $method->setAccessible(true);
        $reason = (string) $method->invoke(new HRAnalyticsService(), [
            'band' => 'at_risk',
            'score_status' => 'eligible',
            'overdue_open_tasks' => 2,
            'metrics' => ['task_completion' => 37.5],
        ]);

        $this->assertStringContainsString('37.5% completion', $reason);
    }
}
