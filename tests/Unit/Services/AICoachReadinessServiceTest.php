<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;
use CRM\Services\AICoachOperatingMaturityService;
use CRM\Services\AICoachReadinessService;
use CRM\Services\AICoachWorkspaceSetupService;
use CRM\Services\StartupJourneyService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceOnboardingService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class AICoachReadinessServiceTest extends DatabaseTestCase
{
    public function testWorkspaceEnabledConfigDefaultsTrueAndCanBlockReadiness(): void
    {
        $seed = $this->seedWorkspaceUser('coach-workspace-toggle');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $this->installAiCoach($workspaceId, $userId);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
        $this->completeWorkspaceBriefing($workspaceId, $userId, 'Service firms', 'Founder-led agencies');
        $this->completeClarityJourney($workspaceId, $userId);
        $this->saveCoachContext($userId, 'Service firms', 'Founder-led agencies');

        $setup = new AICoachWorkspaceSetupService();
        $this->assertTrue($setup->isWorkspaceEnabled($workspaceId));

        $setup->setWorkspaceEnabled($workspaceId, $userId, false);
        $blocked = (new AICoachReadinessService())->getReadiness($workspaceId, $userId);
        $fields = array_map(static fn(array $item): string => (string) ($item['field'] ?? ''), (array) ($blocked['missing_requirements'] ?? []));

        $this->assertFalse((bool) ($blocked['ai_coach_enabled'] ?? true));
        $this->assertFalse((bool) ($blocked['recommendations_ready'] ?? true));
        $this->assertContains('ai_coach_enabled', $fields);

        $setup->setWorkspaceEnabled($workspaceId, $userId, true);
        $ready = (new AICoachReadinessService())->getReadiness($workspaceId, $userId);
        $this->assertTrue((bool) ($ready['recommendations_ready'] ?? false));
    }

    public function testReadinessRequiresSharedContextAndClarityJourney(): void
    {
        $seed = $this->seedWorkspaceUser('coach-readiness-required');
        $this->installAiCoach((int) $seed['workspace_id'], (int) $seed['user_id']);
        WorkspaceContext::activateRuntimeWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], 'owner');

        $service = new AICoachReadinessService();
        $notReady = $service->getReadiness((int) $seed['workspace_id'], (int) $seed['user_id']);
        $fields = array_map(static fn(array $item): string => (string) ($item['field'] ?? ''), (array) ($notReady['missing_requirements'] ?? []));

        $this->assertFalse((bool) ($notReady['recommendations_ready'] ?? true));
        $this->assertContains('company_context_ready', $fields);
        $this->assertContains('company_name', $fields);
        $this->assertContains('product_name', $fields);

        $this->completeWorkspaceBriefing((int) $seed['workspace_id'], (int) $seed['user_id'], 'Service firms', 'Founder-led agencies');

        $companyReady = $service->getReadiness((int) $seed['workspace_id'], (int) $seed['user_id']);
        $this->assertTrue((bool) ($companyReady['company_context_ready'] ?? false));
        $this->assertFalse((bool) ($companyReady['personal_brief_ready'] ?? true));
        $this->assertFalse((bool) ($companyReady['clarity_journey_ready'] ?? true));
        $this->assertFalse((bool) ($companyReady['recommendations_ready'] ?? true));

        $this->saveCoachContext((int) $seed['user_id'], 'Service firms', 'Founder-led agencies');
        $manualWithoutJourney = $service->getReadiness((int) $seed['workspace_id'], (int) $seed['user_id']);
        $this->assertTrue((bool) ($manualWithoutJourney['personal_brief_ready'] ?? false));
        $this->assertFalse((bool) ($manualWithoutJourney['clarity_journey_ready'] ?? true));
        $this->assertFalse((bool) ($manualWithoutJourney['recommendations_ready'] ?? true));

        $this->completeClarityJourney((int) $seed['workspace_id'], (int) $seed['user_id']);

        $ready = $service->getReadiness((int) $seed['workspace_id'], (int) $seed['user_id']);
        $this->assertTrue((bool) ($ready['recommendations_ready'] ?? false));
        $this->assertTrue((bool) ($ready['personal_brief_ready'] ?? false));
        $this->assertTrue((bool) ($ready['coach_context_ready'] ?? false));
        $this->assertTrue((bool) ($ready['personal_strategy_optional'] ?? false));
        $this->assertTrue((bool) ($ready['clarity_journey_ready'] ?? false));
        $this->assertNotNull($ready['active_strategy_snapshot'] ?? null);
        $this->assertSame([], (array) ($ready['missing_requirements'] ?? []));
        $this->assertSame('completed', (string) ($ready['onboarding_payload']['workspace_briefing']['status'] ?? ''));
    }

    public function testCompletedClarityJourneyUnlocksRecommendationsWithoutOptionalPersonalStrategy(): void
    {
        $seed = $this->seedWorkspaceUser('coach-journey-only');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $this->installAiCoach($workspaceId, $userId);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
        $this->completeWorkspaceBriefing($workspaceId, $userId, 'Service firms', 'Founder-led agencies');
        $this->completeEmptyClarityJourney($workspaceId, $userId);

        $readiness = (new AICoachReadinessService())->getReadiness($workspaceId, $userId);

        $this->assertTrue((bool) ($readiness['clarity_journey_ready'] ?? false));
        $this->assertTrue((bool) ($readiness['coach_context_ready'] ?? false));
        $this->assertTrue((bool) ($readiness['personal_strategy_optional'] ?? false));
        $this->assertFalse((bool) ($readiness['personal_strategy_refinement_ready'] ?? true));
        $this->assertFalse((bool) ($readiness['personal_brief_ready'] ?? true));
        $this->assertTrue((bool) ($readiness['recommendations_ready'] ?? false));
        $this->assertSame([], (array) ($readiness['missing_requirements'] ?? ['unexpected']));
        $this->assertNotSame([], (array) ($readiness['optional_personal_strategy_missing'] ?? []));
        $this->assertSame('completed', (string) ($readiness['onboarding_payload']['status'] ?? ''));
    }

    public function testCompletedClarityJourneyCanSupplyPersonalCoachContext(): void
    {
        $seed = $this->seedWorkspaceUser('coach-journey-inherited');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $this->installAiCoach($workspaceId, $userId);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
        $this->completeWorkspaceBriefing($workspaceId, $userId, 'Founder-led service firms', 'Founder-led agencies');
        $this->completeClarityJourney($workspaceId, $userId);

        $readiness = (new AICoachReadinessService())->getReadiness($workspaceId, $userId);

        $this->assertTrue((bool) ($readiness['recommendations_ready'] ?? false));
        $this->assertTrue((bool) ($readiness['personal_brief_ready'] ?? false));
        $this->assertTrue((bool) ($readiness['clarity_journey_ready'] ?? false));
        $this->assertTrue((bool) ($readiness['inherited_context_ready'] ?? false));
        $this->assertContains((string) ($readiness['personal_brief_source'] ?? ''), ['clarity_journey', 'mixed']);
        $this->assertContains('clarity_journey', (array) ($readiness['context_sources'] ?? []));
        $this->assertSame([], (array) ($readiness['remaining_personal_requirements'] ?? ['unexpected']));
        $this->assertStringContainsString('Founder-led service firms', (string) ($readiness['onboarding_payload']['strategy']['target_market_focus'] ?? ''));
        foreach ([
            'clarity_journey_ready',
            'inherited_context_ready',
            'personal_brief_source',
            'context_sources',
            'remaining_personal_requirements',
            'recommendations_ready',
            'operating_maturity',
        ] as $contractField) {
            $this->assertArrayHasKey($contractField, $readiness);
        }
        $this->assertSame(AICoachOperatingMaturityService::JOURNEY_COMPLETE_FOUNDER_LOOP_NEXT, (string) ($readiness['operating_maturity'] ?? ''));
        $this->assertSame(
            AICoachOperatingMaturityService::JOURNEY_COMPLETE_FOUNDER_LOOP_NEXT,
            (string) ($readiness['operating_maturity_context']['stage'] ?? '')
        );
        $this->assertSame(
            AICoachOperatingMaturityService::JOURNEY_COMPLETE_FOUNDER_LOOP_NEXT,
            (string) ($readiness['onboarding_payload']['operating_maturity'] ?? '')
        );
    }

    public function testStrategyAndIdeaContextDoNotLeakAcrossWorkspaces(): void
    {
        $seed = $this->seedWorkspaceUser('coach-isolation-one');
        $workspaceOne = (int) $seed['workspace_id'];
        $workspaceTwo = (new WorkspaceService())->createWorkspace('Coach Isolation Two ' . random_int(100, 999), 'coach-isolation-two', (int) $seed['user_id']);
        (new WorkspaceMembershipService())->addOrUpdateMembership($workspaceTwo, (int) $seed['user_id'], 'owner', true, (int) $seed['user_id']);
        $this->installAiCoach($workspaceOne, (int) $seed['user_id']);
        $this->installAiCoach($workspaceTwo, (int) $seed['user_id']);

        WorkspaceContext::activateRuntimeWorkspace($workspaceOne, (int) $seed['user_id'], 'owner');
        $this->completeWorkspaceBriefing($workspaceOne, (int) $seed['user_id'], 'Workspace one firms', 'Workspace one agencies');
        $this->completeClarityJourney($workspaceOne, (int) $seed['user_id'], 'Workspace one firms');
        $this->saveCoachContext((int) $seed['user_id'], 'Workspace one firms', 'Workspace one agencies');

        WorkspaceContext::activateRuntimeWorkspace($workspaceTwo, (int) $seed['user_id'], 'owner');
        $workspaceTwoReadiness = (new AICoachReadinessService())->getReadiness($workspaceTwo, (int) $seed['user_id']);

        $this->assertFalse((bool) ($workspaceTwoReadiness['strategy_ready'] ?? true));
        $this->assertSame('', (string) ($workspaceTwoReadiness['onboarding_payload']['strategy']['target_market_focus'] ?? ''));
        $this->assertFalse((bool) ($workspaceTwoReadiness['recommendations_ready'] ?? true));

        $this->completeWorkspaceBriefing($workspaceTwo, (int) $seed['user_id'], 'Workspace two firms', 'Workspace two agencies');
        $this->completeClarityJourney($workspaceTwo, (int) $seed['user_id'], 'Workspace two firms');
        $this->saveCoachContext((int) $seed['user_id'], 'Workspace two firms', 'Workspace two agencies');

        WorkspaceContext::activateRuntimeWorkspace($workspaceOne, (int) $seed['user_id'], 'owner');
        $workspaceOneReadiness = (new AICoachReadinessService())->getReadiness($workspaceOne, (int) $seed['user_id']);
        $this->assertSame('Workspace one firms', (string) ($workspaceOneReadiness['onboarding_payload']['strategy']['target_market_focus'] ?? ''));
    }

    /**
     * @return array{workspace_id:int,user_id:int}
     */
    private function seedWorkspaceUser(string $prefix): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $userId = (int) Auth::createUser(
            $prefix . '.' . $suffix . '@example.test',
            'P@ssword123!',
            'admin',
            'Coach',
            'Owner'
        );
        $workspaceId = (new WorkspaceService())->createWorkspace('Coach Readiness ' . $suffix, $prefix, $userId);
        (new WorkspaceMembershipService())->addOrUpdateMembership($workspaceId, $userId, 'owner', true, $userId);

        return ['workspace_id' => $workspaceId, 'user_id' => $userId];
    }

    private function installAiCoach(int $workspaceId, int $userId): void
    {
        (new WorkspaceSkillCatalogService())->syncDefinitions();
        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at
             ) VALUES (?, ?, 'installed', '{}', ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL",
            [$workspaceId, WorkspaceSkillCatalogService::SKILL_AI_COACH, $userId, $userId]
        );
    }

    private function saveCoachContext(int $userId, string $targetMarket, string $ideaTargetMarket): void
    {
        (new UserStrategyProfile())->save($userId, [
            'target_market_focus' => $targetMarket,
            'ideal_customer_profile' => 'Operations leaders',
            'offer_angle' => 'Practical AI execution',
            'market_view' => $targetMarket . ' are under-served by generic CRM automation.',
            'strategy_hypothesis' => 'Hands-on audits will outperform generic automation demos.',
            'sales_motion' => 'Consultative selling',
        ]);
        (new IdeaValidationContext())->save($userId, [
            'value_proposition' => 'Reliable CRM follow-through',
            'target_market' => $ideaTargetMarket,
            'pain_points' => 'Dropped leads and unclear next steps',
            'competitors' => 'Spreadsheets and generic CRM tools',
            'differentiator' => 'Workspace-specific recommendations',
        ]);
        (new UserStrategySnapshot())->syncForUser((int) (WorkspaceContext::currentWorkspaceId() ?? 0), $userId);
    }

    private function completeWorkspaceBriefing(int $workspaceId, int $userId, string $targetMarket, string $productTarget): void
    {
        $service = new WorkspaceOnboardingService();
        $service->saveStep($workspaceId, $userId, 1, [
            'company_name' => 'Coach Context Co',
            'company_industry' => 'Professional services',
            'company_description' => 'Coach Context Co helps teams use CRM context for better recommendations.',
            'success_outcome' => 'Better operational judgment from AI Coach.',
        ]);
        $service->saveStep($workspaceId, $userId, 2, [
            'product_name' => 'Recommendation System',
            'product_description' => 'Workspace-specific recommendation support.',
            'target_audience' => $productTarget,
            'target_market_focus' => $targetMarket,
            'ideal_customer_profile' => 'Operations leaders',
            'offer_angle' => 'Practical AI execution',
            'sales_motion' => 'Consultative selling',
        ]);
        $service->saveStep($workspaceId, $userId, 3, [
            'draft_tone_preset' => 'consultative',
            'relationship_style' => 'trusted_advisor',
            'draft_cta_style' => 'clear',
            'draft_formality_level' => 'balanced',
            'draft_reading_level' => 'professional',
        ]);
        $service->saveStep($workspaceId, $userId, 4, [
            'technical_level' => 'guide_me',
            'ai_best_practices_enabled' => '1',
        ]);
        $service->saveStep($workspaceId, $userId, 5, []);
    }

    private function completeClarityJourney(int $workspaceId, int $userId, string $targetMarket = 'Founder-led service firms'): void
    {
        $service = new StartupJourneyService();
        $responses = [
            'customer_discovery' => [
                'target_customer' => $targetMarket,
                'interview_count' => '10 customer interviews completed.',
                'observed_problem' => 'Founders lose warm deals because follow-up ownership is unclear.',
                'evidence' => 'Interview notes showed missed next steps and stale opportunities.',
                'riskiest_assumption' => 'Founders will pay for guided execution before full automation.',
            ],
            'jobs_to_be_done' => [
                'job_statement' => 'Move a qualified lead from conversation to a priced next step.',
                'triggers' => 'A referral, demo request, or overdue proposal makes the job urgent.',
                'current_alternatives' => 'Spreadsheets and generic CRM tools.',
                'desired_outcomes' => 'Every qualified lead has a next step and owner.',
                'success_criteria' => 'The founder can see what to do this week.',
            ],
            'value_proposition' => [
                'customer_jobs' => 'Keep sales follow-up moving.',
                'pains' => 'Dropped leads and unclear handoffs.',
                'gains' => 'A weekly operating rhythm for first deals.',
                'products_services' => 'AI Coach and Founder Loop execution support.',
                'pain_relievers' => 'Turns CRM evidence into next-step tasks.',
                'gain_creators' => 'Creates a learning loop from weekly commitments.',
            ],
            'lean_canvas' => [
                'problem' => 'First deals stall when business context is not converted into action.',
                'customer_segments' => $targetMarket,
                'unique_value_proposition' => 'Turn Clarity Journey into first-deal execution.',
                'solution' => 'Clarity Journey, Founder Loop, and AI Coach recommendations.',
                'channels' => 'Partner referrals and founder outbound.',
                'revenue_streams' => 'Monthly subscriptions and paid pilots.',
                'cost_structure' => 'AI usage, onboarding, and support.',
                'key_metrics' => 'Qualified conversations, open deals, and paid pilots.',
                'unfair_advantage' => 'Unified Journey, Founder Loop, CRM, and finance context.',
            ],
            'mvp' => [
                'mvp_hypothesis' => 'Weekly recommendations from saved context will move first deals faster.',
                'smallest_test' => 'Run a four-week pilot with manually reviewed Coach recommendations.',
                'required_features' => 'Journey context, tasks, deals, and weekly review.',
                'success_metric' => 'Three teams create qualified opportunities within 30 days.',
                'experiment_budget' => 'Four weeks and 1000 USD.',
            ],
            'go_to_market' => [
                'beachhead_segment' => $targetMarket,
                'message' => 'Turn your business foundation into the next first-deal action.',
                'channels' => 'Direct outreach and partner referrals.',
                'sales_motion' => 'Consultative founder-led sales.',
                'launch_plan' => 'Recruit ten pilots and convert three to paid plans.',
                'conversion_goal' => 'Close three paid pilots in 45 days.',
            ],
            'aarrr' => [
                'acquisition' => 'Partner referrals and direct founder outreach.',
                'activation' => 'Complete Journey and create Founder Loop commitments.',
                'retention' => 'Weekly Coach recommendations keep deals moving.',
                'referral' => 'Founders share the operating rhythm with peers.',
                'revenue' => 'Paid pilots convert to monthly plans.',
            ],
            'okrs' => [
                'objective' => 'Prove Clarity Journey can drive first-deal execution.',
                'key_result_1' => 'Complete ten Journeys.',
                'key_result_2' => 'Create 30 Founder Loop commitments.',
                'key_result_3' => 'Close three paid pilots.',
                'review_cadence' => 'Weekly Friday review.',
            ],
        ];

        foreach ($responses as $stage => $stageResponses) {
            $service->saveStage($workspaceId, $userId, $stage, $stageResponses, '', true);
        }
    }

    private function completeEmptyClarityJourney(int $workspaceId, int $userId): void
    {
        $service = new StartupJourneyService();
        foreach (array_keys($service->stageDefinitions()) as $stage) {
            $service->saveStage($workspaceId, $userId, (string) $stage, [], '', true);
        }
    }
}
