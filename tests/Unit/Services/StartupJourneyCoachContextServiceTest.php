<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\UserStrategyProfile;
use CRM\Services\StartupJourneyCoachContextService;
use CRM\Services\StartupJourneyService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceService;
use CRM\Tests\DatabaseTestCase;

class StartupJourneyCoachContextServiceTest extends DatabaseTestCase
{
    public function testCompletedJourneyDerivesAiCoachContextAndSnapshot(): void
    {
        $seed = $this->seedWorkspaceUser('journey-coach-derived');
        WorkspaceContext::activateRuntimeWorkspace($seed['workspace_id'], $seed['user_id'], 'owner');

        $this->completeJourney($seed['workspace_id'], $seed['user_id']);

        $context = (new StartupJourneyCoachContextService())->readinessForCoach($seed['workspace_id'], $seed['user_id']);
        $profile = (new UserStrategyProfile())->get($seed['user_id']) ?: [];
        $idea = (new IdeaValidationContext())->get($seed['user_id']) ?: [];

        $this->assertTrue((bool) ($context['clarity_journey_ready'] ?? false));
        $this->assertTrue((bool) ($context['inherited_context_ready'] ?? false));
        $this->assertSame('clarity_journey', (string) ($context['personal_brief_source'] ?? ''));
        $this->assertSame([], (array) ($context['remaining_personal_requirements'] ?? ['unexpected']));
        $this->assertNotNull($context['active_strategy_snapshot'] ?? null);
        $this->assertStringContainsString('Revenue operations leaders', (string) ($profile['target_market_focus'] ?? ''));
        $this->assertStringContainsString('Launch plan:', (string) ($profile['deal_movement_strategy'] ?? ''));
        $this->assertStringContainsString('MVP hypothesis:', (string) ($profile['strategy_hypothesis'] ?? ''));
        $this->assertStringContainsString('Spreadsheets', (string) ($idea['competitors'] ?? ''));
        $this->assertStringContainsString('Unfair advantage:', (string) ($idea['differentiator'] ?? ''));
    }

    public function testJourneySyncFillsOnlyMissingCoachBriefFields(): void
    {
        $seed = $this->seedWorkspaceUser('journey-coach-preserve');
        WorkspaceContext::activateRuntimeWorkspace($seed['workspace_id'], $seed['user_id'], 'owner');

        (new UserStrategyProfile())->save($seed['user_id'], [
            'target_market_focus' => 'Manual focus: enterprise agencies',
            'offer_angle' => 'Manual offer angle',
        ]);
        (new IdeaValidationContext())->save($seed['user_id'], [
            'competitors' => 'Manual competitor set',
        ]);

        $this->completeJourney($seed['workspace_id'], $seed['user_id']);

        $context = (new StartupJourneyCoachContextService())->readinessForCoach($seed['workspace_id'], $seed['user_id']);
        $profile = (new UserStrategyProfile())->get($seed['user_id']) ?: [];
        $idea = (new IdeaValidationContext())->get($seed['user_id']) ?: [];

        $this->assertSame('Manual focus: enterprise agencies', (string) ($profile['target_market_focus'] ?? ''));
        $this->assertSame('Manual offer angle', (string) ($profile['offer_angle'] ?? ''));
        $this->assertSame('Manual competitor set', (string) ($idea['competitors'] ?? ''));
        $this->assertStringContainsString('Job to be done:', (string) ($profile['ideal_customer_profile'] ?? ''));
        $this->assertStringContainsString('Observed problem:', (string) ($idea['pain_points'] ?? ''));
        $this->assertSame('mixed', (string) ($context['personal_brief_source'] ?? ''));
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
            'Journey',
            'Owner'
        );
        $workspaceId = (new WorkspaceService())->createWorkspace('Journey Coach ' . $suffix, $prefix, $userId);
        (new WorkspaceMembershipService())->addOrUpdateMembership($workspaceId, $userId, 'owner', true, $userId);

        return ['workspace_id' => $workspaceId, 'user_id' => $userId];
    }

    private function completeJourney(int $workspaceId, int $userId): void
    {
        $service = new StartupJourneyService();
        foreach ($this->journeyResponses() as $stage => $responses) {
            $service->saveStage($workspaceId, $userId, $stage, $responses, '', true);
        }
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function journeyResponses(): array
    {
        return [
            'customer_discovery' => [
                'target_customer' => 'Revenue operations leaders at founder-led B2B service firms.',
                'interview_count' => '12 customer interviews completed.',
                'observed_problem' => 'Teams lose warm deals because follow-up ownership is unclear after discovery calls.',
                'evidence' => 'Seven interviewees showed stale pipeline reviews and missed task handoffs.',
                'riskiest_assumption' => 'Founders will pay before the workflow is fully automated.',
            ],
            'jobs_to_be_done' => [
                'job_statement' => 'When a qualified lead appears, the team wants to move it to a priced next step without dropping context.',
                'triggers' => 'A demo request, referral, or overdue proposal makes the job urgent.',
                'current_alternatives' => 'Spreadsheets, generic CRMs, and weekly manual pipeline calls.',
                'desired_outcomes' => 'Every qualified prospect has a next step, owner, and value estimate.',
                'success_criteria' => 'The buyer sees faster follow-up and fewer repeated questions.',
            ],
            'value_proposition' => [
                'customer_jobs' => 'Keep sales follow-up moving after every meaningful customer conversation.',
                'pains' => 'Dropped leads, unclear task ownership, and inconsistent pricing follow-up.',
                'gains' => 'A simple operating rhythm that turns founder judgment into repeatable pipeline action.',
                'products_services' => 'AI-assisted CRM coaching and founder-loop execution support.',
                'pain_relievers' => 'Flags stale deals and turns recommendations into concrete next-step tasks.',
                'gain_creators' => 'Creates a weekly learning loop from pipeline, finance, and customer evidence.',
            ],
            'lean_canvas' => [
                'problem' => 'Founder-led firms lose first deals because CRM data is not converted into weekly action.',
                'customer_segments' => 'Revenue operations leaders and founders at B2B service firms.',
                'unique_value_proposition' => 'A CRM operating coach that turns business context into first-deal execution.',
                'solution' => 'Clarity Journey, Founder Loop, and AI Coach recommendations tied to CRM activity.',
                'channels' => 'Founder communities, partner agencies, and direct outbound.',
                'revenue_streams' => 'Monthly subscriptions and implementation support.',
                'cost_structure' => 'AI usage, support, onboarding, and product development.',
                'key_metrics' => 'Qualified conversations, open deals, first paid customers, and weekly commitments completed.',
                'unfair_advantage' => 'A unified context layer across Journey, Founder Loop, CRM, and finance.',
            ],
            'mvp' => [
                'mvp_hypothesis' => 'If founders see the next three deal actions each week, first-deal conversion will improve.',
                'smallest_test' => 'Run five Founder Loop weeks with manually reviewed AI Coach recommendations.',
                'required_features' => 'Journey context, CRM task creation, deal signals, and weekly review.',
                'success_metric' => 'Three of five teams create new qualified opportunities within 30 days.',
                'experiment_budget' => 'Four weeks and 1500 USD in founder time and AI costs.',
            ],
            'go_to_market' => [
                'beachhead_segment' => 'Founder-led B2B agencies with warm leads and no sales operations owner.',
                'message' => 'Turn your saved business context into the next first-deal action.',
                'channels' => 'Direct founder outreach, partner referrals, and CRM setup webinars.',
                'sales_motion' => 'Consultative founder-led sales with a diagnostic call and paid pilot.',
                'launch_plan' => 'Recruit ten pilot teams, run weekly reviews, and convert the best three into paid plans.',
                'conversion_goal' => 'Close three paid pilots in 45 days.',
            ],
            'aarrr' => [
                'acquisition' => 'Founders arrive through partner referrals and direct outbound.',
                'activation' => 'They complete Clarity Journey and create the first Founder Loop commitments.',
                'retention' => 'Weekly recommendations keep deals, finance, and tasks moving.',
                'referral' => 'Successful founders share the first-deal operating rhythm with peers.',
                'revenue' => 'Paid pilots convert into monthly CRM operating subscriptions.',
            ],
            'okrs' => [
                'objective' => 'Prove Clarity Journey can become first-deal execution for founder-led firms.',
                'key_result_1' => 'Complete ten Clarity Journeys with pilot users.',
                'key_result_2' => 'Create 30 Founder Loop commitments from saved Journey context.',
                'key_result_3' => 'Close three paid pilots from the first cohort.',
                'review_cadence' => 'Review evidence every Friday and update the Journey assumptions weekly.',
            ],
        ];
    }
}
