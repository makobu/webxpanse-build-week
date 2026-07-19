<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Services\AICoachAssumptionConflictService;
use CRM\Services\AICoachReadinessService;
use CRM\Services\AIService;
use CRM\Services\FounderOperatingLoopService;
use CRM\Services\StartupJourneyReportService;
use CRM\Services\StartupJourneyService;
use CRM\Tests\DatabaseTestCase;

class StartupJourneyReportServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) Auth::createUser(
            'journey-report-owner-' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
            'P@ssword123!',
            'owner',
            'Journey',
            'Reporter'
        );
    }

    public function testBuildsReportWithAiSwotStageHealthAndConflicts(): void
    {
        $this->completeJourney();
        $service = $this->serviceWithAi($this->validAiJson(), [
            [
                'type' => 'pricing',
                'severity' => 'high',
                'journey_assumption' => 'Target deal value is 1000.',
                'operating_evidence' => 'Average paid invoice is 250.',
                'recommended_action' => 'Review pricing evidence before scaling.',
            ],
        ]);

        $report = $service->build(1, $this->userId, true);

        $this->assertSame('available', (string) ($report['swot']['status'] ?? ''));
        $this->assertSame('AI says the Journey is ready for focused execution.', (string) ($report['executive_summary'] ?? ''));
        $this->assertNotEmpty($report['stage_health']);
        $this->assertNotEmpty($report['assumption_conflicts']);
        $this->assertSame('pricing', (string) ($report['assumption_conflicts'][0]['type'] ?? ''));
        $this->assertContains('contradicted', (array) ($report['journey_health']['labels'] ?? []));
        $this->assertNotEmpty($report['next_actions']);
    }

    public function testInvalidAiJsonMarksSwotUnavailableWithoutInventingFallback(): void
    {
        $this->completeJourney();
        $report = $this->serviceWithAi('this is not json')->build(1, $this->userId, true);

        $this->assertSame('unavailable', (string) ($report['swot']['status'] ?? ''));
        $this->assertSame([], (array) ($report['swot']['strengths'] ?? ['unexpected']));
        $this->assertSame('invalid_json', (string) ($report['report_meta']['ai_status'] ?? ''));
    }

    public function testSavesAndRetrievesLatestReportArtifact(): void
    {
        $this->completeJourney();
        $service = $this->serviceWithAi($this->validAiJson());
        $report = $service->build(1, $this->userId, false);

        $artifactId = $service->saveArtifact(1, $this->userId, $report);
        $latest = $service->latest(1, $this->userId);

        $this->assertGreaterThan(0, $artifactId);
        $this->assertIsArray($latest);
        $this->assertSame($artifactId, (int) ($latest['report_meta']['artifact_id'] ?? 0));
        $this->assertSame('journey_report', (string) ($latest['report_meta']['artifact_type'] ?? ''));
    }

    private function serviceWithAi(string $aiResponse, array $conflicts = []): StartupJourneyReportService
    {
        $ai = new class($aiResponse) extends AIService {
            public function __construct(private string $response)
            {
            }

            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                return [
                    'surface' => $surface,
                    'prompt_key' => $promptKey,
                    'rendered_prompt' => (string) ($inputs['legacy_prompt'] ?? ''),
                ];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return $this->response;
            }
        };

        $conflictService = new class($conflicts) extends AICoachAssumptionConflictService {
            public function __construct(private array $conflicts)
            {
            }

            public function detect(int $workspaceId, int $userId, array $operatingContext = []): array
            {
                return $this->conflicts;
            }
        };

        $loopService = new class extends FounderOperatingLoopService {
            public function __construct()
            {
            }

            public function summary(int $workspaceId, int $userId, ?string $weekStart = null): array
            {
                return [
                    'current_step_key' => 'first_customers',
                    'current_step' => ['label' => 'First customers'],
                    'next_action' => 'Create one named paid-customer follow-up.',
                    'first_customer_signal' => [
                        'leads_created' => 2,
                        'open_deals' => 1,
                        'deals_won' => 0,
                        'paid_customer_count' => 0,
                        'gap' => 'Need one paid signal.',
                    ],
                    'pricing' => ['warnings' => ['No paid invoice data yet.']],
                    'commitments' => [['title' => 'Follow up']],
                    'weekly_plan' => ['focus' => 'First paid signal'],
                ];
            }
        };

        $readinessService = new class extends AICoachReadinessService {
            public function getReadiness(int $workspaceId, int $userId): array
            {
                return [
                    'recommendations_ready' => true,
                    'clarity_journey_ready' => true,
                    'operating_maturity' => 'journey_complete_founder_loop_next',
                    'missing_requirements' => [],
                ];
            }
        };

        return new StartupJourneyReportService(
            new StartupJourneyService(),
            $ai,
            $conflictService,
            $loopService,
            $readinessService
        );
    }

    private function validAiJson(): string
    {
        return json_encode([
            'executive_summary' => 'AI says the Journey is ready for focused execution.',
            'swot' => [
                'strengths' => [[
                    'title' => 'Customer evidence',
                    'evidence' => 'The Journey includes interview and problem evidence.',
                    'confidence' => 'high',
                    'source_refs' => ['customer_discovery.evidence'],
                    'next_action' => 'Keep testing the riskiest assumption.',
                ]],
                'weaknesses' => [[
                    'title' => 'Pricing proof',
                    'evidence' => 'Paid invoice evidence is still thin.',
                    'confidence' => 'medium',
                    'source_refs' => ['founder_loop.pricing'],
                    'next_action' => 'Validate pricing with one customer.',
                ]],
                'opportunities' => [[
                    'title' => 'Founder Loop handoff',
                    'evidence' => 'The next action is already named.',
                    'confidence' => 'medium',
                    'source_refs' => ['founder_loop.next_action'],
                    'next_action' => 'Create the follow-up.',
                ]],
                'threats' => [[
                    'title' => 'Execution drift',
                    'evidence' => 'Open commitments need CRM movement.',
                    'confidence' => 'medium',
                    'source_refs' => ['founder_loop.commitments'],
                    'next_action' => 'Record the outcome.',
                ]],
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function completeJourney(): void
    {
        $responses = [
            'customer_discovery' => [
                'target_customer' => 'Founder-led agencies in Nairobi with five to twenty employees.',
                'interview_count' => '12 customer interviews completed.',
                'observed_problem' => 'Warm leads go cold because follow-up ownership is unclear.',
                'evidence' => '8 of 12 founders described missed follow-up costing at least one deal.',
                'riskiest_assumption' => 'Founders will pay for guided execution before full automation.',
            ],
            'jobs_to_be_done' => [
                'job_statement' => 'Move qualified leads from conversation to priced next step.',
                'triggers' => 'A referral or demo request makes follow-up urgent.',
                'current_alternatives' => 'Spreadsheets, WhatsApp reminders, and generic CRMs.',
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
                'problem' => 'First deals stall when context is not converted into action.',
                'customer_segments' => 'Agency founders.',
                'unique_value_proposition' => 'Turn Clarity Journey into first-deal execution.',
                'solution' => 'Clarity Journey, Founder Loop, and AI Coach recommendations.',
                'channels' => 'LinkedIn outbound and referrals.',
                'revenue_streams' => 'Monthly subscriptions and paid pilots.',
                'cost_structure' => 'AI usage, onboarding, and support.',
                'key_metrics' => 'Qualified conversations, open deals, and paid pilots.',
                'unfair_advantage' => 'Unified Journey, CRM, and finance context.',
            ],
            'mvp' => [
                'mvp_hypothesis' => 'Weekly recommendations from saved context will move first deals faster.',
                'smallest_test' => 'Run a four-week pilot with manually reviewed Coach recommendations.',
                'required_features' => 'Journey context, tasks, deals, and weekly review.',
                'success_metric' => 'Three teams create qualified opportunities within 30 days.',
                'experiment_budget' => 'Four weeks and 1000 USD.',
            ],
            'go_to_market' => [
                'beachhead_segment' => 'Agency founders.',
                'message' => 'Turn your business foundation into the next first-deal action.',
                'channels' => 'LinkedIn outbound and referrals.',
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

        $service = new StartupJourneyService();
        foreach ($responses as $stageKey => $stageResponses) {
            $service->saveStage(1, $this->userId, $stageKey, $stageResponses, '', true);
        }
    }
}
