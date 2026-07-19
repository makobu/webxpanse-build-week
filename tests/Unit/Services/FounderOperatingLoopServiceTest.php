<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\FinanceExpenseService;
use CRM\Services\FounderFinanceService;
use CRM\Services\FounderOperatingLoopService;
use CRM\Services\StartupJourneyService;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class FounderOperatingLoopServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('loop-owner-', true), 'loop-owner@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())
             ON DUPLICATE KEY UPDATE membership_status = VALUES(membership_status), role_slug = VALUES(role_slug), is_owner = VALUES(is_owner)",
            [$this->userId]
        );
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
        if (!empty($role['id']) && Database::tableExists('user_roles')) {
            Database::execute(
                "INSERT INTO user_roles (user_id, role_id, assigned_by)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)",
                [$this->userId, (int) $role['id'], $this->userId]
            );
        }
        Session::set('user_id', $this->userId);
    }

    public function testFounderLoopTablesExist(): void
    {
        foreach ([
            'founder_first_customer_sprints',
            'founder_weekly_reviews',
            'founder_weekly_review_commitments',
        ] as $table) {
            $this->assertTrue(Database::tableExists($table), $table . ' should exist');
        }
    }

    public function testWeeklyReviewStoresFinanceSnapshot(): void
    {
        $expenseService = new FinanceExpenseService();
        $categoryId = $expenseService->saveCategory(1, 'Marketing', 'marketing', $this->userId);
        $expenseService->saveExpense(1, [
            'category_id' => $categoryId,
            'description' => 'Founder sprint ads',
            'amount' => 120,
            'currency' => 'USD',
            'expense_date' => '2026-05-19',
            'status' => 'paid',
        ], $this->userId);

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_by, created_at)
             VALUES (1, 'Paid', 'Buyer', 'paid-loop@example.test', ?, '2026-05-19 09:00:00')",
            [$this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO invoices (
                workspace_id, document_type, status, invoice_number, contact_id, created_by, currency,
                issue_date, due_date, payment_terms_days, title, subtotal, grand_total, amount_paid, balance_due, paid_at
             ) VALUES (1, 'invoice', 'paid', 'LOOP-PAID-1', ?, ?, 'USD', '2026-05-19', '2026-05-26', 7, 'Paid loop invoice', 600, 600, 600, 0, '2026-05-20 10:00:00')",
            [$contactId, $this->userId]
        );

        (new FounderFinanceService())->saveFounderBudget(1, $this->userId, [
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'currency' => 'USD',
            'target_cash_reserve' => 1200,
            'target_deal_value' => 300,
            'monthly_marketing_budget' => 250,
            'monthly_fixed_costs' => 120,
            'target_cac' => 100,
            'budget_lines' => [['label' => 'Marketing', 'category_id' => $categoryId, 'planned_amount' => 250]],
        ]);

        $service = new FounderOperatingLoopService();
        $reviewId = $service->saveWeeklyReview(1, $this->userId, [
            'week_start' => '2026-05-18',
            'wins' => 'First paid invoice',
            'blockers' => 'Need repeatable outreach',
            'customer_conversations' => 3,
            'pricing_concern' => 'CAC needs watching',
            'next_week_focus' => 'Book five demos',
            'commitment_title' => ['Send first customer outreach'],
            'commitment_description' => ['Use the founder sprint pitch'],
            'commitment_due_date' => ['2026-05-25'],
            'commitment_status' => ['pending'],
        ], true);

        $this->assertGreaterThan(0, $reviewId);

        $review = Database::queryOne("SELECT * FROM founder_weekly_reviews WHERE id = ?", [$reviewId]);
        $this->assertSame('completed', (string) $review['review_status']);
        $this->assertSame(600.0, (float) $review['paid_revenue']);
        $this->assertSame(120.0, (float) $review['expenses']);
        $snapshot = json_decode((string) $review['finance_snapshot_json'], true);
        $this->assertSame(600.0, (float) $snapshot['money_in']);
        $this->assertSame(120.0, (float) $snapshot['money_out']);

        $commitmentCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM founder_weekly_review_commitments WHERE review_id = ?",
            [$reviewId]
        )['c'] ?? 0);
        $this->assertSame(1, $commitmentCount);
    }

    public function testSummaryAndAiContextExposeLoopState(): void
    {
        $this->completeStartupJourney();
        $service = new FounderOperatingLoopService();

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_by, created_at)
             VALUES (1, 'Warm', 'Lead', 'warm-loop@example.test', ?, '2026-05-19 09:00:00')",
            [$this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, created_by, stage, value, probability, currency, created_at)
             VALUES (1, 'Warm loop deal', ?, ?, 'qualification', 1200, 30, 'USD', '2026-05-19 10:00:00')",
            [$contactId, $this->userId]
        );

        $summary = $service->summary(1, $this->userId, '2026-05-18');
        $this->assertArrayHasKey('work_crm_pipeline', $summary['steps']);
        $this->assertSame('Marketing agency founders', $summary['weekly_plan']['target_customer_segment']);
        $this->assertSame('LinkedIn', $summary['weekly_plan']['outreach_channel']);
        $this->assertStringContainsString('AI-guided follow-up system', $summary['weekly_plan']['offer_pitch']);
        $this->assertSame(1, (int) $summary['first_customer_signal']['leads_created']);
        $this->assertSame(1, (int) $summary['first_customer_signal']['open_deals']);
        $this->assertCount(3, $summary['recommended_commitments']);
        $this->assertNotEmpty($summary['current_step_key']);

        $taskResult = $service->createFirstCustomerTasks(1, $this->userId);
        $this->assertCount(3, $taskResult['created']);
        $duplicateResult = $service->createFirstCustomerTasks(1, $this->userId);
        $this->assertCount(0, $duplicateResult['created']);
        $this->assertGreaterThanOrEqual(3, count($duplicateResult['skipped']));

        $context = $service->contextForAI(1, $this->userId);
        $this->assertArrayHasKey('current_loop_step', $context);
        $this->assertSame('Marketing agency founders', $context['weekly_plan']['target_customer_segment']);
        $this->assertArrayHasKey('first_customer_signal', $context);
        $this->assertCount(3, $context['recommended_commitments']);
        $this->assertArrayHasKey('finance_snapshot', $context);
        $this->assertArrayHasKey('pricing_evidence', $context);
        $this->assertArrayHasKey('financial_next_actions', $context);
        $this->assertContains('pricing_evidence', array_column((array) $context['financial_next_actions'], 'type'));
        $this->assertArrayHasKey('active_week', $context);
        $this->assertFalse((bool) ($context['active_week']['has_saved_review'] ?? true));
        $this->assertArrayHasKey('current_commitment', $context);
        $this->assertSame('recommended_commitment', (string) ($context['current_commitment']['source'] ?? ''));
        $this->assertArrayHasKey('blocked_commitment', $context);
        $this->assertArrayHasKey('last_review_outcome', $context);
        $this->assertArrayHasKey('next_recommended_loop_step', $context);
        $this->assertNotEmpty($context['next_recommended_loop_step']['next_action'] ?? '');
    }

    public function testCompletedWeeklyReviewLinksGeneratedCommitmentsToTasks(): void
    {
        $this->completeStartupJourney();
        $service = new FounderOperatingLoopService();
        $summary = $service->summary(1, $this->userId, '2026-05-18');
        $commitments = (array) ($summary['recommended_commitments'] ?? []);

        $reviewId = $service->saveWeeklyReview(1, $this->userId, [
            'week_start' => '2026-05-18',
            'wins' => 'Created first-customer motion',
            'blockers' => 'Need replies',
            'customer_conversations' => 2,
            'pricing_concern' => '',
            'next_week_focus' => 'Follow up pipeline',
            'commitment_title' => array_column($commitments, 'title'),
            'commitment_description' => array_column($commitments, 'description'),
            'commitment_due_date' => array_column($commitments, 'due_date'),
            'commitment_status' => array_fill(0, count($commitments), 'pending'),
        ], true);

        $linked = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM founder_weekly_review_commitments WHERE review_id = ? AND task_id IS NOT NULL",
            [$reviewId]
        )['c'] ?? 0);
        $this->assertSame(3, $linked);

        $taskCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM tasks
             WHERE workspace_id = 1
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source_surface')) = 'founder_operating_loop'"
        )['c'] ?? 0);
        $this->assertSame(3, $taskCount);

        $service->saveWeeklyReview(1, $this->userId, [
            'week_start' => '2026-05-18',
            'wins' => 'Created first-customer motion',
            'blockers' => 'Need replies',
            'customer_conversations' => 2,
            'pricing_concern' => '',
            'next_week_focus' => 'Follow up pipeline',
            'commitment_title' => array_column($commitments, 'title'),
            'commitment_description' => array_column($commitments, 'description'),
            'commitment_due_date' => array_column($commitments, 'due_date'),
            'commitment_status' => array_fill(0, count($commitments), 'pending'),
        ], true);

        $taskCountAfterDuplicateSave = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM tasks
             WHERE workspace_id = 1
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source_surface')) = 'founder_operating_loop'"
        )['c'] ?? 0);
        $this->assertSame(3, $taskCountAfterDuplicateSave);
    }

    private function completeStartupJourney(): void
    {
        $journeyService = new StartupJourneyService();
        foreach ($journeyService->stageDefinitions() as $stageKey => $definition) {
            $responses = [];
            foreach ((array) ($definition['fields'] ?? []) as $fieldKey => $label) {
                $responses[$fieldKey] = match ((string) $fieldKey) {
                    'target_customer', 'customer_segments', 'beachhead_segment' => 'Marketing agency founders',
                    'unique_value_proposition', 'message' => 'AI-guided follow-up system for CRM-heavy agency owners',
                    'channels' => 'LinkedIn',
                    default => (string) $label . ' answer with customer signal 5 interviews and paid proof.',
                };
            }
            $journeyService->saveStage(1, $this->userId, (string) $stageKey, $responses, 'Evidence captured.', true);
        }
    }
}
