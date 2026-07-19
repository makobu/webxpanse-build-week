<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\UserStrategyProfile;
use CRM\Services\StartupJourneyService;
use CRM\Tests\DatabaseTestCase;

class StartupJourneyServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'owner', NOW())",
            [uniqid('journey-owner-', true), 'journey-owner@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testStartupJourneyTablesExist(): void
    {
        foreach ([
            'startup_journeys',
            'startup_journey_stage_responses',
            'startup_journey_stage_events',
            'startup_journey_artifacts',
        ] as $table) {
            $this->assertTrue(Database::tableExists($table), $table . ' should exist');
        }
    }

    public function testLegacyLeanCanvasIsImportedAsLeanCanvasStage(): void
    {
        (new UserStrategyProfile())->save($this->userId, [
            'lean_problem' => 'Founders lose opportunities after discovery calls.',
            'lean_customer_segments' => 'Founder-led agencies',
            'lean_unique_value_proposition' => 'AI follow-up operating layer',
            'lean_solution' => 'Guided CRM actions',
            'lean_channels' => 'Warm outbound',
            'lean_revenue_streams' => 'Subscription',
            'lean_cost_structure' => 'Hosting and support',
            'lean_key_metrics' => 'Follow-ups completed',
            'lean_unfair_advantage' => 'Integrated founder context',
        ]);

        $journey = (new StartupJourneyService())->getJourney(1, $this->userId);
        $leanStage = (array) ($journey['stages']['lean_canvas'] ?? []);

        $this->assertSame('completed', (string) ($leanStage['status'] ?? ''));
        $this->assertSame('Founders lose opportunities after discovery calls.', (string) ($leanStage['responses']['problem'] ?? ''));
        $this->assertSame(8, (int) ($journey['progress']['total'] ?? 0));
    }

    public function testSavingLeanCanvasStageSyncsLegacyProfile(): void
    {
        $service = new StartupJourneyService();
        $service->saveStage(1, $this->userId, 'lean_canvas', [
            'problem' => 'Pipeline leakage',
            'customer_segments' => 'Agencies',
            'unique_value_proposition' => 'Revenue operations clarity',
            'solution' => 'AI-assisted follow-up',
            'channels' => 'LinkedIn and referrals',
            'revenue_streams' => 'Monthly plans',
            'cost_structure' => 'Product and support',
            'key_metrics' => 'Deals moved forward',
            'unfair_advantage' => 'Workspace context',
        ], '', true);

        $profile = (new UserStrategyProfile())->get($this->userId) ?: [];
        $this->assertSame('Pipeline leakage', (string) ($profile['lean_problem'] ?? ''));

        $readiness = $service->readiness(1, $this->userId);
        $this->assertFalse((bool) $readiness['ready']);
        $this->assertSame('needs_setup', (string) $readiness['status']);
    }

    public function testContextForAIDerivesFinancialAssumptionsFromJourneyStages(): void
    {
        $service = new StartupJourneyService();
        $service->saveStage(1, $this->userId, 'lean_canvas', [
            'problem' => 'Pipeline leakage',
            'customer_segments' => 'Agencies',
            'unique_value_proposition' => 'Revenue operations clarity',
            'solution' => 'AI-assisted follow-up',
            'channels' => 'LinkedIn and referrals',
            'revenue_streams' => 'Monthly retainers and paid onboarding pilots.',
            'cost_structure' => 'AI usage, support, and founder-led onboarding.',
            'key_metrics' => 'Deals moved forward',
            'unfair_advantage' => 'Workspace context',
        ], '', true);
        $service->saveStage(1, $this->userId, 'mvp', [
            'mvp_hypothesis' => 'Founders will pay for a weekly execution loop.',
            'smallest_test' => 'Concierge pilot with five founders.',
            'required_features' => 'Journey context and CRM tasks.',
            'success_metric' => 'Three paid pilots in four weeks.',
            'experiment_budget' => 'Spend no more than 500 USD in the first month.',
        ], '', true);
        $service->saveStage(1, $this->userId, 'go_to_market', [
            'beachhead_segment' => 'Founder-led agencies',
            'message' => 'Turn strategy into first-deal execution.',
            'channels' => 'LinkedIn referrals',
            'sales_motion' => 'Founder-led consultative sales.',
            'launch_plan' => 'Recruit five pilots.',
            'conversion_goal' => 'Close three paid pilots in 45 days.',
        ], '', true);
        $service->saveStage(1, $this->userId, 'aarrr', [
            'acquisition' => 'Warm referrals.',
            'activation' => 'First weekly commitment.',
            'retention' => 'Weekly deal movement.',
            'referral' => 'Founder referrals.',
            'revenue' => 'Paid pilots become monthly retainers.',
        ], '', true);
        $service->saveStage(1, $this->userId, 'okrs', [
            'objective' => 'Prove paid founder-loop activation.',
            'key_result_1' => 'Close three paid pilots.',
            'key_result_2' => 'Keep CAC below 200 USD.',
            'key_result_3' => 'Maintain two months runway.',
            'review_cadence' => 'Weekly',
        ], '', true);

        $context = $service->getContextForAI(1, $this->userId);
        $assumptions = (array) ($context['financial_assumptions'] ?? []);

        $this->assertSame('clarity_journey', (string) ($assumptions['source'] ?? ''));
        $this->assertSame('Monthly retainers and paid onboarding pilots.', (string) ($assumptions['revenue_streams'] ?? ''));
        $this->assertSame('AI usage, support, and founder-led onboarding.', (string) ($assumptions['cost_structure'] ?? ''));
        $this->assertSame('Spend no more than 500 USD in the first month.', (string) ($assumptions['experiment_budget'] ?? ''));
        $this->assertSame('Close three paid pilots in 45 days.', (string) ($assumptions['conversion_goal'] ?? ''));
        $this->assertSame('Paid pilots become monthly retainers.', (string) ($assumptions['aarrr_revenue'] ?? ''));
        $this->assertContains('Keep CAC below 200 USD.', (array) ($assumptions['okr_key_results'] ?? []));
    }

    public function testFieldQualityAndStageReadinessAreCalculated(): void
    {
        $service = new StartupJourneyService();
        $journey = $service->saveStage(1, $this->userId, 'customer_discovery', [
            'target_customer' => 'Founder-led agencies in Nairobi with five to twenty employees and active inbound leads.',
            'interview_count' => '8 interviews completed',
            'observed_problem' => 'Customers said warm leads go cold because follow-up is split across WhatsApp, email, and memory.',
            'evidence' => '6 of 8 founders described a missed follow-up costing at least one deal.',
            'riskiest_assumption' => 'They will pay before deeper WhatsApp automation exists.',
        ], '', false);

        $stage = (array) ($journey['stages']['customer_discovery'] ?? []);
        $readiness = (array) ($stage['readiness'] ?? []);

        $this->assertSame('ready_to_complete', (string) ($readiness['status'] ?? ''));
        $this->assertSame('Strong', (string) ($readiness['field_quality']['evidence']['label'] ?? ''));
        $this->assertSame('low', (string) ($readiness['completion_risk'] ?? ''));
    }

    public function testStrongAnswersDoNotRequireLinkedEvidence(): void
    {
        $service = new StartupJourneyService();
        $journey = $service->saveStage(1, $this->userId, 'mvp', [
            'mvp_hypothesis' => '8 agency founders said they would pay for guided follow-up before full automation.',
            'smallest_test' => 'Run a concierge test with 5 founders and manually prepare their weekly follow-up plan.',
            'required_features' => 'Lead list, follow-up date, next action, and AI draft suggestion for each warm lead.',
            'success_metric' => '3 of 10 demo users convert to paid pilots within 14 days.',
            'experiment_budget' => 'Spend no more than $300 and two weeks before deciding what was learned.',
        ], '', false);

        $stage = (array) ($journey['stages']['mvp'] ?? []);
        $this->assertSame('ready_to_complete', (string) ($stage['readiness']['status'] ?? ''));
        $this->assertSame('Strong', (string) ($stage['readiness']['field_quality']['mvp_hypothesis']['label'] ?? ''));
        $this->assertSame('low', (string) ($stage['readiness']['completion_risk'] ?? ''));
    }

    public function testAutosaveStyleSaveKeepsReadyStageDraftUntilManualCompletion(): void
    {
        $service = new StartupJourneyService();
        $responses = [
            'mvp_hypothesis' => '8 agency founders said they would pay for guided follow-up before full automation.',
            'smallest_test' => 'Run a concierge test with 5 founders and manually prepare their weekly follow-up plan.',
            'required_features' => 'Lead list, follow-up date, next action, and AI draft suggestion for each warm lead.',
            'success_metric' => '3 of 10 demo users convert to paid pilots within 14 days.',
            'experiment_budget' => 'Spend no more than $300 and two weeks before deciding what was learned.',
        ];

        $journey = $service->saveStageWithAutoCompletion(1, $this->userId, 'mvp', $responses);

        $stage = (array) ($journey['stages']['mvp'] ?? []);
        $this->assertSame('draft', (string) ($stage['status'] ?? ''));
        $this->assertSame('', (string) ($stage['completed_at'] ?? ''));
        $this->assertSame('ready_to_complete', (string) ($stage['readiness']['status'] ?? ''));

        $journey = $service->saveStage(1, $this->userId, 'mvp', $responses, '', true, [
            'save_source' => 'manual',
            'completion_allowed' => true,
        ]);

        $stage = (array) ($journey['stages']['mvp'] ?? []);
        $this->assertSame('completed', (string) ($stage['status'] ?? ''));
        $this->assertNotSame('', (string) ($stage['completed_at'] ?? ''));
    }

    public function testThinAnswersAreNotReadyForManualCompletion(): void
    {
        $readiness = (new StartupJourneyService())->stageReadinessForResponses('mvp', [
            'mvp_hypothesis' => 'Maybe agencies will pay.',
            'smallest_test' => '',
            'required_features' => 'Lead list',
            'success_metric' => '',
            'experiment_budget' => '',
        ]);

        $this->assertNotSame('ready_to_complete', (string) ($readiness['status'] ?? ''));
        $this->assertSame('high', (string) ($readiness['completion_risk'] ?? ''));
    }

    public function testAiDraftAssistMetadataIsRecordedOnStageSave(): void
    {
        $service = new StartupJourneyService();
        $service->saveStage(1, $this->userId, 'customer_discovery', [
            'target_customer' => 'Founder-led agencies in Nairobi with active warm leads.',
            'interview_count' => '8 interviews completed',
            'observed_problem' => 'Warm leads go cold when owners forget follow-up after discovery calls.',
            'evidence' => '6 of 8 founders described a missed follow-up costing at least one deal.',
            'riskiest_assumption' => 'They will pay before deeper WhatsApp automation exists.',
        ], '', false, [
            'save_source' => 'ai_draft_assist',
            'ai_assist_metadata' => [
                'field_key' => 'evidence',
                'confidence' => 'medium',
                'source_type' => 'ai',
            ],
        ]);

        $event = Database::queryOne(
            "SELECT event_type, metadata_json
             FROM startup_journey_stage_events
             WHERE user_id = ? AND stage_key = 'customer_discovery'
             ORDER BY id DESC
             LIMIT 1",
            [$this->userId]
        ) ?: [];
        $metadata = json_decode((string) ($event['metadata_json'] ?? '{}'), true) ?: [];

        $this->assertSame('stage_saved', (string) ($event['event_type'] ?? ''));
        $this->assertSame('ai_draft_assist', (string) ($metadata['save_source'] ?? ''));
        $this->assertSame('evidence', (string) ($metadata['ai_assist_metadata']['field_key'] ?? ''));
        $this->assertSame('medium', (string) ($metadata['ai_assist_metadata']['confidence'] ?? ''));
        $this->assertSame('ai', (string) ($metadata['ai_assist_metadata']['source_type'] ?? ''));
    }
}
