<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Services\AICoachAssumptionConflictService;
use CRM\Services\StartupJourneyService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceService;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class AICoachAssumptionConflictServiceTest extends DatabaseTestCase
{
    public function testDetectsGtmChannelConflictFromCrmLeadSources(): void
    {
        $seed = $this->seedWorkspaceUser('coach-conflict-channel');
        $this->completeJourney($seed['workspace_id'], $seed['user_id'], [
            'channels' => 'LinkedIn outbound',
        ]);
        $this->insertContact($seed['workspace_id'], $seed['user_id'], 'Referral', 'One', 'Referral Co', 'referral');
        $this->insertContact($seed['workspace_id'], $seed['user_id'], 'Referral', 'Two', 'Referral Co', 'referral');

        $conflicts = (new AICoachAssumptionConflictService())->detect($seed['workspace_id'], $seed['user_id']);

        $this->assertContains('gtm_channel', array_column($conflicts, 'type'), json_encode($conflicts));
        $channelConflict = $this->firstConflictOfType($conflicts, 'gtm_channel');
        $this->assertSame('medium', $channelConflict['severity'] ?? null);
        $this->assertStringContainsString('LinkedIn outbound', (string) ($channelConflict['journey_assumption'] ?? ''));
        $this->assertStringContainsString('referral', (string) ($channelConflict['operating_evidence'] ?? ''));
    }

    public function testDetectsPricingConflictAgainstPaidInvoices(): void
    {
        $seed = $this->seedWorkspaceUser('coach-conflict-pricing');
        $this->completeJourney($seed['workspace_id'], $seed['user_id']);
        $contactId = $this->insertContact($seed['workspace_id'], $seed['user_id'], 'Paid', 'Buyer', 'Agency Buyer', 'form');
        Database::execute(
            "INSERT INTO invoices (
                workspace_id, document_type, status, invoice_number, contact_id, created_by, currency,
                issue_date, due_date, payment_terms_days, title, subtotal, grand_total, amount_paid, balance_due, paid_at
             ) VALUES (?, 'invoice', 'paid', ?, ?, ?, 'USD', '2026-05-20', '2026-05-27', 7, 'Paid pilot', 250, 250, 250, 0, '2026-05-21 10:00:00')",
            [$seed['workspace_id'], 'CONFLICT-PAID-' . $seed['workspace_id'], $contactId, $seed['user_id']]
        );

        $conflicts = (new AICoachAssumptionConflictService())->detect($seed['workspace_id'], $seed['user_id'], [
            'finance_context' => [
                'target_deal_value' => 1000,
            ],
        ]);

        $this->assertContains('pricing', array_column($conflicts, 'type'), json_encode($conflicts));
        $pricingConflict = $this->firstConflictOfType($conflicts, 'pricing');
        $this->assertSame('high', $pricingConflict['severity'] ?? null);
        $this->assertStringContainsString('1,000.00', (string) ($pricingConflict['journey_assumption'] ?? ''));
        $this->assertStringContainsString('250.00', (string) ($pricingConflict['operating_evidence'] ?? ''));
    }

    public function testDetectsOkrExecutionConflictFromFounderLoopTasksWithoutMovement(): void
    {
        $seed = $this->seedWorkspaceUser('coach-conflict-okr');
        $this->completeJourney($seed['workspace_id'], $seed['user_id'], [
            'objective' => 'Close three paid pilots from the first cohort.',
            'key_result_3' => 'Close three paid pilots.',
        ]);

        $conflicts = (new AICoachAssumptionConflictService())->detect($seed['workspace_id'], $seed['user_id'], [
            'founder_operating_loop_context' => [
                'first_customer_signal' => [
                    'open_founder_tasks' => 2,
                    'completed_founder_tasks' => 0,
                    'leads_created' => 0,
                    'open_deals' => 0,
                    'deals_opened' => 0,
                    'deals_won' => 0,
                    'paid_customer_count' => 0,
                ],
            ],
        ]);

        $this->assertContains('okr_execution', array_column($conflicts, 'type'), json_encode($conflicts));
        $okrConflict = $this->firstConflictOfType($conflicts, 'okr_execution');
        $this->assertSame('medium', $okrConflict['severity'] ?? null);
        $this->assertStringContainsString('no lead, deal, or paid-customer movement', (string) ($okrConflict['operating_evidence'] ?? ''));
    }

    public function testDetectsFinanceEvidenceConflictsAgainstJourneyAssumptions(): void
    {
        $seed = $this->seedWorkspaceUser('coach-conflict-finance');
        $this->completeJourney($seed['workspace_id'], $seed['user_id'], [
            'channels' => 'LinkedIn paid ads and outbound',
            'objective' => 'Launch ten paid pilots before the runway runs out.',
        ]);

        $conflicts = (new AICoachAssumptionConflictService())->detect($seed['workspace_id'], $seed['user_id'], [
            'finance_context' => [
                'target_deal_value' => 1000,
                'avg_paid_invoice' => 250,
                'paid_invoice_count' => 1,
                'paid_customer_count' => 1,
                'invoice_sales_income' => 0,
                'manual_sales_income' => 500,
                'manual_other_income' => 0,
                'pipeline_revenue' => 5000,
                'money_out' => 2500,
                'runway_months' => 1.2,
                'cac' => 450,
                'target_cac' => 100,
                'expense_by_category' => [
                    ['category_name' => 'Marketing Ads', 'category_type' => 'marketing', 'total_amount' => 1800, 'expense_count' => 3],
                    ['category_name' => 'Support', 'category_type' => 'operations', 'total_amount' => 700, 'expense_count' => 1],
                ],
            ],
        ]);
        $types = array_column($conflicts, 'type');

        foreach (['pricing', 'revenue_model', 'cost_structure', 'experiment_budget', 'runway', 'cac'] as $type) {
            $this->assertContains($type, $types, json_encode($conflicts));
        }
        $budgetConflict = $this->firstConflictOfType($conflicts, 'experiment_budget');
        $this->assertSame('high', $budgetConflict['severity'] ?? null);
        $this->assertStringContainsString('above the implied experiment budget', (string) ($budgetConflict['operating_evidence'] ?? ''));

        $cacConflict = $this->firstConflictOfType($conflicts, 'cac');
        $this->assertStringContainsString('target CAC', (string) ($cacConflict['operating_evidence'] ?? ''));
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @return array<string,mixed>
     */
    private function firstConflictOfType(array $conflicts, string $type): array
    {
        foreach ($conflicts as $conflict) {
            if ((string) ($conflict['type'] ?? '') === $type) {
                return $conflict;
            }
        }

        return [];
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
            'Conflict',
            'Owner'
        );
        $workspaceId = (new WorkspaceService())->createWorkspace('Coach Conflict ' . $suffix, $prefix . '-' . $suffix, $userId);
        (new WorkspaceMembershipService())->addOrUpdateMembership($workspaceId, $userId, 'owner', true, $userId);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
        Session::set('user_id', $userId);

        return ['workspace_id' => $workspaceId, 'user_id' => $userId];
    }

    /**
     * @param array<string,string> $overrides
     */
    private function completeJourney(int $workspaceId, int $userId, array $overrides = []): void
    {
        $responses = [
            'customer_discovery' => [
                'target_customer' => $overrides['target_customer'] ?? 'Agency founders',
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
                'customer_segments' => $overrides['customer_segments'] ?? 'Agency founders',
                'unique_value_proposition' => 'Turn Clarity Journey into first-deal execution.',
                'solution' => 'Clarity Journey, Founder Loop, and AI Coach recommendations.',
                'channels' => $overrides['channels'] ?? 'LinkedIn outbound',
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
                'beachhead_segment' => $overrides['beachhead_segment'] ?? 'Agency founders',
                'message' => 'Turn your business foundation into the next first-deal action.',
                'channels' => $overrides['channels'] ?? 'LinkedIn outbound',
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
                'objective' => $overrides['objective'] ?? 'Prove Clarity Journey can drive first-deal execution.',
                'key_result_1' => 'Complete ten Journeys.',
                'key_result_2' => 'Create 30 Founder Loop commitments.',
                'key_result_3' => $overrides['key_result_3'] ?? 'Close three paid pilots.',
                'review_cadence' => 'Weekly Friday review.',
            ],
        ];

        $service = new StartupJourneyService();
        foreach ($responses as $stageKey => $stageResponses) {
            $service->saveStage($workspaceId, $userId, $stageKey, $stageResponses, '', true);
        }
    }

    private function insertContact(int $workspaceId, int $userId, string $firstName, string $lastName, string $company, string $leadSource): int
    {
        Database::execute(
            "INSERT INTO contacts (uuid, workspace_id, first_name, last_name, email, company, lead_source, created_by, created_at)
             VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, '2026-05-20 09:00:00')",
            [$workspaceId, $firstName, $lastName, strtolower($firstName) . '.' . strtolower($lastName) . '@example.test', $company, $leadSource, $userId]
        );

        return (int) Database::lastInsertId();
    }
}
