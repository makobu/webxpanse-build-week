<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIAdviceDomainRouterService;
use CRM\Services\WorkspaceSkillCatalogService;
use PHPUnit\Framework\TestCase;

class AIAdviceDomainRouterServiceTest extends TestCase
{
    public function testProductHelpDoesNotRequireSkill(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate('Where do I install workspace skills?', []);

        $this->assertSame('product_help', $decision['skill_route_decision']);
        $this->assertSame([], $decision['missing_skill_domains']);
    }

    public function testProductConfigurationQuestionWithBusinessKeywordStaysProductHelp(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate('Where do I configure pricing settings?', []);

        $this->assertSame('product_help', $decision['skill_route_decision']);
        $this->assertSame([], $decision['missing_skill_domains']);
    }

    public function testProductConfigurationQuestionForAiCoachStaysProductHelp(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate('Where do I configure AI Coach and Lean Canvas setup?', []);

        $this->assertSame('product_help', $decision['skill_route_decision']);
        $this->assertSame([], $decision['missing_skill_domains']);
    }

    public function testGenericGrowthAdviceBlocksWithoutReadySkill(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate('How do I grow faster?', []);

        $this->assertSame('blocked_skill_required', $decision['skill_route_decision']);
        $this->assertContains('growth', $decision['missing_skill_domains']);
        $this->assertSame(WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, $decision['recommended_skill_key']);
    }

    public function testEvidenceExplanationIsAllowedWithoutStrategySkill(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate(
            'Why is my churn risk score so high?',
            [],
            ['intent' => 'explanation']
        );

        $this->assertSame('allowed', $decision['skill_route_decision']);
        $this->assertTrue($decision['explanation_request']);
        $this->assertSame([], $decision['missing_skill_domains']);
    }

    public function testPrescriptiveMetricAdviceRemainsSkillGated(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate(
            'How should I improve churn and retention?',
            [],
            ['intent' => 'general']
        );

        $this->assertSame('blocked_skill_required', $decision['skill_route_decision']);
        $this->assertContains('metrics', $decision['missing_skill_domains']);
    }

    public function testCrmOperationalRequestIsAllowedWithoutSkill(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate('Create a task to call ACME tomorrow', []);

        $this->assertSame('allowed', $decision['skill_route_decision']);
        $this->assertSame([], $decision['missing_skill_domains']);
    }

    public function testMixedCrmAndStrategyRequestRequiresMatchingSkill(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate('Create a task to validate my startup idea and recommend the best experiment', []);

        $this->assertSame('blocked_skill_required', $decision['skill_route_decision']);
        $this->assertContains('validation', $decision['missing_skill_domains']);
    }

    public function testReadyLeanCanvasAllowsStartupAdvice(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate('How should I validate this startup idea?', [
            'installed_skill_contracts' => [
                [
                    'key' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                    'advice_domains' => ['startup', 'business_model', 'validation'],
                    'boundary_policy' => 'strict',
                    'readiness' => ['ready' => true],
                ],
            ],
        ]);

        $this->assertSame('allowed', $decision['skill_route_decision']);
        $this->assertSame([WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS], $decision['matched_skill_keys']);
    }

    public function testReadyLeanCanvasAllowsPricingAndMetricAdvice(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate('Which pricing metric should I watch first?', [
            'installed_skill_contracts' => [
                [
                    'key' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                    'advice_domains' => ['business_model', 'pricing', 'metrics'],
                    'boundary_policy' => 'strict',
                    'readiness' => ['ready' => true],
                ],
            ],
        ]);

        $this->assertSame('allowed', $decision['skill_route_decision']);
        $this->assertSame([WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS], $decision['matched_skill_keys']);
    }

    public function testAiCoachAloneDoesNotAuthorizeGrowthAdvice(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate('What should I do to grow faster?', [
            'installed_skill_contracts' => [
                [
                    'key' => WorkspaceSkillCatalogService::SKILL_AI_COACH,
                    'advice_domains' => ['coaching', 'growth'],
                    'boundary_policy' => 'orchestrator_only',
                    'readiness' => ['ready' => true],
                ],
            ],
        ]);

        $this->assertSame('blocked_skill_required', $decision['skill_route_decision']);
        $this->assertContains('growth', $decision['missing_skill_domains']);
        $this->assertSame(WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, $decision['recommended_skill_key']);
    }

    public function testInstalledButIncompleteSkillBlocksWithSetupRecommendation(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate('Create campaign ideas for this segment', [
            'installed_skill_contracts' => [
                [
                    'key' => WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER,
                    'advice_domains' => ['marketing', 'campaigns', 'positioning'],
                    'boundary_policy' => 'strict',
                    'readiness' => ['ready' => false],
                ],
            ],
        ]);

        $this->assertSame('blocked_skill_required', $decision['skill_route_decision']);
        $this->assertContains('campaigns', $decision['missing_skill_domains']);
        $this->assertSame(WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, $decision['recommended_skill_key']);
    }

    public function testReadyProfessionalMarketerAllowsCampaignAdvice(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate('Improve this campaign positioning for our segment', [
            'installed_skill_contracts' => [
                [
                    'key' => WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER,
                    'advice_domains' => ['marketing', 'campaigns', 'positioning'],
                    'routing_examples' => ['Improve this positioning'],
                    'boundary_policy' => 'strict',
                    'readiness' => ['ready' => true],
                ],
            ],
        ]);

        $this->assertSame('allowed', $decision['skill_route_decision']);
        $this->assertSame([WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER], $decision['matched_skill_keys']);
        $this->assertGreaterThanOrEqual(0.8, $decision['confidence']);
    }

    public function testReadyCustomSkillAllowsMatchingDomain(): void
    {
        $decision = (new AIAdviceDomainRouterService())->evaluate('Help with my partnerships strategy', [
            'installed_skill_contracts' => [
                [
                    'key' => 'custom_10_partnerships',
                    'advice_domains' => ['partnerships'],
                    'routing_examples' => ['Help with my partnerships strategy'],
                    'boundary_policy' => 'strict',
                    'readiness' => ['ready' => true],
                ],
            ],
        ]);

        $this->assertSame('allowed', $decision['skill_route_decision']);
        $this->assertSame(['custom_10_partnerships'], $decision['matched_skill_keys']);
    }
}
