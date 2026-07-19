<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\SkillTaskTemplateService;
use CRM\Services\WorkspaceSkillCatalogService;
use PHPUnit\Framework\TestCase;

class SkillTaskTemplateServiceTest extends TestCase
{
    public function testAiCoachAloneIsNotReadyAdviceSkill(): void
    {
        $service = new SkillTaskTemplateService();

        $this->assertFalse($service->hasReadyAdviceSkill([
            'installed_skill_contracts' => [
                [
                    'key' => WorkspaceSkillCatalogService::SKILL_AI_COACH,
                    'module_type' => 'skill',
                    'boundary_policy' => 'orchestrator_only',
                    'readiness' => ['ready' => true],
                ],
            ],
        ]));
    }

    public function testAppliesMatchingReadySkillTaskTemplates(): void
    {
        $service = new SkillTaskTemplateService();
        $recommendations = [
            'priorities' => [
                [
                    'title' => 'Improve campaign positioning',
                    'reason' => 'The current campaign lacks a clear audience angle.',
                    'suggested_subtasks' => ['Ask AI for ideas'],
                ],
            ],
        ];

        $shaped = $service->applyTaskTemplates($recommendations, [
            'installed_skill_contracts' => [
                [
                    'key' => WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER,
                    'module_type' => 'skill',
                    'boundary_policy' => 'strict',
                    'advice_domains' => ['campaigns', 'positioning'],
                    'readiness' => ['ready' => true],
                    'task_templates' => [
                        ['title' => 'Draft one campaign angle', 'description' => 'Tie it to the saved audience.'],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            ['Draft one campaign angle: Tie it to the saved audience.'],
            $shaped['priorities'][0]['suggested_subtasks']
        );
        $this->assertSame(
            [WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER],
            $shaped['priorities'][0]['skill_task_template_source']
        );
    }

    public function testReadyLeanCanvasProvidesBusinessModelTaskTemplates(): void
    {
        $service = new SkillTaskTemplateService();
        $recommendations = [
            'foundation_gaps' => [
                [
                    'title' => 'Validate the business model pricing assumption',
                    'reason' => 'The pricing and customer segment assumptions need proof.',
                ],
            ],
        ];

        $shaped = $service->applyTaskTemplates($recommendations, [
            'installed_skill_contracts' => [
                [
                    'key' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                    'module_type' => 'skill',
                    'boundary_policy' => 'strict',
                    'advice_domains' => ['business_model', 'pricing', 'validation'],
                    'readiness' => ['ready' => true],
                    'task_templates' => [
                        ['title' => 'Run one validation experiment', 'description' => 'Tie it to a Lean Canvas assumption.'],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            ['Run one validation experiment: Tie it to a Lean Canvas assumption.'],
            $shaped['foundation_gaps'][0]['suggested_subtasks']
        );
        $this->assertSame(
            [WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS],
            $shaped['foundation_gaps'][0]['skill_task_template_source']
        );
    }

    public function testUnmatchedBusinessRecommendationLosesGenericTaskSteps(): void
    {
        $service = new SkillTaskTemplateService();
        $recommendations = [
            'quick_wins' => [
                [
                    'title' => 'Revise pricing tiers',
                    'reason' => 'Pricing is unclear.',
                    'suggested_subtasks' => ['Ask AI to produce generic pricing steps'],
                ],
            ],
        ];

        $shaped = $service->applyTaskTemplates($recommendations, [
            'installed_skill_contracts' => [
                [
                    'key' => WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER,
                    'module_type' => 'skill',
                    'boundary_policy' => 'strict',
                    'advice_domains' => ['campaigns', 'positioning'],
                    'readiness' => ['ready' => true],
                    'task_templates' => [
                        ['title' => 'Draft one campaign angle', 'description' => 'Tie it to the saved audience.'],
                    ],
                ],
            ],
        ]);

        $this->assertSame([], $shaped['quick_wins'][0]['suggested_subtasks']);
        $this->assertSame([], $shaped['quick_wins'][0]['skill_task_template_source']);
    }

    public function testMarketplaceAndCrmTasksRemainCreatableButGenericBusinessTasksAreFiltered(): void
    {
        $service = new SkillTaskTemplateService();
        $items = [
            [
                'title' => 'Install Lean Canvas',
                'source_recommendation_type' => 'marketplace_module',
                'marketplace_skill_key' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
            ],
            [
                'title' => 'Call ACME about the open deal',
                'target_id' => 42,
            ],
            [
                'title' => 'Improve campaign positioning',
                'skill_task_template_source' => [WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER],
            ],
            [
                'title' => 'Invent a generic growth plan',
            ],
        ];

        $filtered = $service->filterTaskCreationCandidates($items);

        $this->assertSame([
            'Install Lean Canvas',
            'Call ACME about the open deal',
            'Improve campaign positioning',
        ], array_column($filtered, 'title'));
    }
}
