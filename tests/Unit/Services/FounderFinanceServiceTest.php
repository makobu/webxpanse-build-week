<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\FinanceExpenseService;
use CRM\Services\FinanceCashAccountService;
use CRM\Services\FinanceIncomeService;
use CRM\Services\FinanceLedgerService;
use CRM\Services\FinanceOpeningSetupService;
use CRM\Services\FinanceOwnerEquityService;
use CRM\Services\FinanceStatementService;
use CRM\Services\FounderFinanceService;
use CRM\Services\WorkspaceFinanceGateService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class FounderFinanceServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'owner', NOW())",
            [uniqid('finance-owner-', true), 'finance-owner@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())
             ON DUPLICATE KEY UPDATE role_slug = 'owner', membership_status = 'active', is_owner = 1",
            [$this->userId]
        );
    }

    public function testFinanceTablesExist(): void
    {
        foreach ([
            'finance_expense_categories',
            'finance_vendors',
            'finance_expenses',
            'finance_recurring_expenses',
            'finance_budgets',
            'finance_budget_lines',
            'finance_snapshots',
            'finance_statement_snapshots',
            'finance_cash_accounts',
            'finance_income_categories',
            'finance_income_entries',
            'finance_accounts',
            'finance_transactions',
            'finance_journal_entries',
            'finance_owner_equity_profiles',
            'finance_opening_setups',
            'finance_opening_bank_balances',
            'finance_opening_assets',
            'finance_opening_receivables',
            'finance_opening_liabilities',
        ] as $table) {
            $this->assertTrue(Database::tableExists($table), $table . ' should exist');
        }

        $this->assertTrue(Database::columnExists('finance_expense_categories', 'statement_group'));
        $this->assertTrue(Database::columnExists('finance_expense_categories', 'cash_flow_group'));
        $this->assertTrue(Database::columnExists('finance_expense_categories', 'is_deductible'));
        $this->assertTrue(Database::columnExists('finance_expense_categories', 'is_cogs'));
        $this->assertTrue(Database::columnExists('finance_expenses', 'vendor_id'));
        $this->assertTrue(Database::columnExists('finance_expenses', 'cash_account_id'));
        $this->assertTrue(Database::columnExists('finance_income_entries', 'income_category_id'));
        $this->assertTrue(Database::columnExists('finance_expense_categories', 'profit_treatment'));
        $this->assertTrue(Database::columnExists('finance_recurring_expenses', 'vendor_id'));
        $this->assertTrue(Database::columnExists('finance_transactions', 'cash_account_id'));
        $this->assertTrue(Database::columnExists('finance_accounts', 'normal_balance'));
        $this->assertTrue(Database::columnExists('finance_transactions', 'transaction_type'));
        $this->assertTrue(Database::columnExists('finance_transactions', 'owner_user_id'));
        $this->assertTrue(Database::columnExists('finance_journal_entries', 'entry_type'));
        $this->assertTrue(Database::columnExists('finance_opening_setups', 'bank_reviewed'));
        $this->assertTrue(Database::columnExists('finance_opening_bank_balances', 'cash_account_id'));
        $this->assertTrue(Database::columnExists('finance_opening_liabilities', 'liability_type'));
        $this->assertNotEmpty(Database::queryOne(
            "SELECT id FROM workspace_skill_definitions WHERE skill_key = ? LIMIT 1",
            [WorkspaceSkillCatalogService::PLUGIN_FINANCE]
        ));

        (new FinanceIncomeService())->listCategories(1);
        (new FinanceExpenseService())->listCategories(1);

        $accountCount = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM finance_accounts WHERE workspace_id = 1")['c'] ?? 0);
        $this->assertGreaterThanOrEqual(14, $accountCount);
        $cashAccount = Database::queryOne("SELECT * FROM finance_cash_accounts WHERE workspace_id = 1 AND name = 'Main Bank' LIMIT 1");
        $this->assertNotEmpty($cashAccount);
        $this->assertSame(1, (int) ($cashAccount['is_default'] ?? 0));
        $incomeLabel = Database::queryOne("SELECT * FROM finance_income_categories WHERE workspace_id = 1 AND name = 'Sales' LIMIT 1");
        $this->assertNotEmpty($incomeLabel);
        $profitShare = Database::queryOne("SELECT * FROM finance_expense_categories WHERE workspace_id = 1 AND name = 'Profit share' LIMIT 1");
        $this->assertNotEmpty($profitShare);
        $this->assertSame('post_net_profit_share', (string) ($profitShare['profit_treatment'] ?? ''));
    }

    public function testFinanceMarketplaceGateRequiresInstallOpeningSetupAndOwnerAllocation(): void
    {
        $gate = new WorkspaceFinanceGateService();

        $notInstalled = $gate->status(1, ['id' => $this->userId, 'role' => 'owner']);
        $this->assertFalse((bool) $notInstalled['ready']);
        $this->assertSame('not_installed', $notInstalled['status']);

        $this->installFinancePlugin();
        $missingSetup = $gate->status(1, ['id' => $this->userId, 'role' => 'owner']);
        $this->assertFalse((bool) $missingSetup['ready']);
        $this->assertSame('setup_required', $missingSetup['status']);

        (new FinanceLedgerService())->saveGuidedTransaction(1, [
            'transaction_type' => 'opening_balance',
            'transaction_date' => '2026-05-01',
            'amount' => 0,
            'currency' => 'USD',
            'opening_cash' => '0.00',
            'opening_receivables' => '0.00',
            'opening_payables' => '0.00',
            'opening_loan_balance' => '0.00',
            'opening_assets' => '0.00',
            'opening_equity' => '0.00',
            'opening_setup_complete' => 1,
        ], $this->userId);
        (new FinanceOwnerEquityService())->saveProfiles(1, [[
            'user_id' => $this->userId,
            'ownership_percent' => 100,
            'opening_owner_capital' => 500,
            'opening_owner_draws' => 0,
            'currency' => 'USD',
        ]], $this->userId);

        $ready = $gate->status(1, ['id' => $this->userId, 'role' => 'owner']);
        $this->assertTrue((bool) $ready['ready']);
        $this->assertSame('ready', $ready['status']);
    }

    public function testTabbedOpeningSetupBuildsInitialBalanceSheetAndBankBalances(): void
    {
        $this->installFinancePlugin();
        $setup = new FinanceOpeningSetupService();
        $gate = new WorkspaceFinanceGateService();

        $setup->saveTab(1, 'start', [
            'opening_date' => '2026-05-01',
            'currency' => 'USD',
            'notes' => 'Opening intake',
        ], $this->userId);
        $setup->saveTab(1, 'bank', [
            'currency' => 'USD',
            'bank_accounts' => [
                ['name' => 'Main Bank', 'account_type' => 'bank', 'opening_balance' => 1000, 'is_default' => 1],
                ['name' => 'Cash Box', 'account_type' => 'cash', 'opening_balance' => 500],
            ],
        ], $this->userId);
        $setup->saveTab(1, 'assets', [
            'assets' => [
                ['name' => 'Laptop', 'asset_type' => 'equipment', 'value' => 700],
            ],
        ], $this->userId);
        $setup->saveTab(1, 'receivables', [
            'receivables' => [
                ['from_name' => 'Customer A', 'amount' => 300, 'due_date' => '2026-05-15'],
            ],
        ], $this->userId);
        $setup->saveTab(1, 'liabilities', [
            'liabilities' => [
                ['name' => 'Bank loan', 'liability_type' => 'loan', 'amount' => 400, 'interest_rate' => 10],
                ['name' => 'Supplier A', 'liability_type' => 'supplier_bill', 'amount' => 100],
            ],
        ], $this->userId);
        $setup->saveTab(1, 'owners', [
            'owner_equity' => [
                $this->userId => [
                    'user_id' => $this->userId,
                    'ownership_percent' => 100,
                    'opening_owner_capital' => 1200,
                    'opening_owner_draws' => 50,
                    'notes' => 'Founder',
                ],
            ],
        ], $this->userId);

        $beforeReview = $gate->status(1, ['id' => $this->userId, 'role' => 'owner']);
        $this->assertFalse((bool) $beforeReview['ready']);

        $setup->saveTab(1, 'review', [], $this->userId);
        $afterReview = $gate->status(1, ['id' => $this->userId, 'role' => 'owner']);
        $this->assertTrue((bool) $afterReview['ready']);

        $formData = $setup->setupFormData(1);
        $summary = (array) $formData['summary'];
        $this->assertSame(1500.0, (float) $summary['bank_total']);
        $this->assertSame(700.0, (float) $summary['assets_total']);
        $this->assertSame(300.0, (float) $summary['receivables_total']);
        $this->assertSame(500.0, (float) $summary['liabilities_total']);
        $this->assertSame(2000.0, (float) $summary['business_value']);
        $this->assertSame(2000.0, (float) ($formData['owners'][0]['owner_value'] ?? 0));

        $opening = Database::queryOne(
            "SELECT id FROM finance_transactions WHERE workspace_id = 1 AND transaction_type = 'opening_balance' LIMIT 1"
        );
        $this->assertNotEmpty($opening);
        $entryTotals = Database::queryOne(
            "SELECT
                SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE 0 END) AS debits,
                SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END) AS credits
             FROM finance_journal_entries
             WHERE workspace_id = 1 AND transaction_id = ?",
            [(int) $opening['id']]
        );
        $this->assertSame((float) $entryTotals['debits'], (float) $entryTotals['credits']);

        $cashBox = Database::queryOne(
            "SELECT id FROM finance_cash_accounts WHERE workspace_id = 1 AND name = 'Cash Box' LIMIT 1"
        );
        $this->assertNotEmpty($cashBox);
        $bankStatement = (new FinanceStatementService())->generateBankStatement(1, '2026-05-01', '2026-05-31', (int) $cashBox['id']);
        $this->assertSame(500.0, (float) $bankStatement['summary']['ending_balance']);
        $this->assertCount(1, $bankStatement['rows']);
    }

    public function testTabbedOpeningSetupAllowsNoneForOptionalRows(): void
    {
        $this->installFinancePlugin();
        $setup = new FinanceOpeningSetupService();
        $gate = new WorkspaceFinanceGateService();

        $setup->saveTab(1, 'start', [
            'opening_date' => '2026-05-01',
            'currency' => 'USD',
        ], $this->userId);
        $setup->saveTab(1, 'bank', [
            'currency' => 'USD',
            'bank_accounts' => [
                ['name' => 'Main Bank', 'account_type' => 'bank', 'opening_balance' => 100000, 'is_default' => 1],
            ],
        ], $this->userId);
        $setup->saveTab(1, 'assets', [
            'assets_none' => '1',
            'assets' => [
                ['name' => 'Ignore me', 'asset_type' => 'equipment', 'value' => 100],
            ],
        ], $this->userId);
        $setup->saveTab(1, 'receivables', [
            'receivables_none' => '1',
            'receivables' => [
                ['from_name' => 'Ignore me', 'amount' => 100],
            ],
        ], $this->userId);
        $setup->saveTab(1, 'liabilities', [
            'liabilities_none' => '1',
            'liabilities' => [
                ['name' => 'Ignore me', 'liability_type' => 'loan', 'amount' => 100],
            ],
        ], $this->userId);
        $setup->saveTab(1, 'owners', [
            'owner_equity' => [
                $this->userId => [
                    'user_id' => $this->userId,
                    'ownership_percent' => 100,
                    'opening_owner_capital' => 100000,
                ],
            ],
        ], $this->userId);

        $formData = $setup->setupFormData(1);
        $this->assertSame([], (array) ($formData['summary']['missing_tabs'] ?? []));
        $this->assertSame(0, (int) ($formData['summary']['row_counts']['assets'] ?? -1));
        $this->assertSame(0, (int) ($formData['summary']['row_counts']['receivables'] ?? -1));
        $this->assertSame(0, (int) ($formData['summary']['row_counts']['liabilities'] ?? -1));

        $setup->saveTab(1, 'review', [], $this->userId);
        $afterReview = $gate->status(1, ['id' => $this->userId, 'role' => 'owner']);
        $this->assertTrue((bool) $afterReview['ready']);

        $summary = (array) $setup->setupFormData(1)['summary'];
        $this->assertSame(100000.0, (float) $summary['bank_total']);
        $this->assertSame(0.0, (float) $summary['assets_total']);
        $this->assertSame(0.0, (float) $summary['receivables_total']);
        $this->assertSame(0.0, (float) $summary['liabilities_total']);
        $this->assertSame(100000.0, (float) $summary['business_value']);
    }

    public function testReviewCanConfirmDefaultStartAndEmptyOptionalTabs(): void
    {
        $this->installFinancePlugin();
        $setup = new FinanceOpeningSetupService();
        $gate = new WorkspaceFinanceGateService();

        $setup->saveTab(1, 'bank', [
            'currency' => 'USD',
            'bank_accounts' => [
                ['name' => 'Main Bank', 'account_type' => 'bank', 'opening_balance' => 100000, 'is_default' => 1],
            ],
        ], $this->userId);
        $setup->saveTab(1, 'owners', [
            'owner_equity' => [
                $this->userId => [
                    'user_id' => $this->userId,
                    'ownership_percent' => 100,
                    'opening_owner_capital' => 100000,
                ],
            ],
        ], $this->userId);

        $beforeReview = $setup->setupFormData(1);
        $this->assertSame(['start', 'assets', 'receivables', 'liabilities'], (array) ($beforeReview['summary']['missing_tabs'] ?? []));

        $setup->saveTab(1, 'review', [
            'start_confirmed' => '1',
            'assets_none' => '1',
            'receivables_none' => '1',
            'liabilities_none' => '1',
        ], $this->userId);

        $afterReview = $gate->status(1, ['id' => $this->userId, 'role' => 'owner']);
        $this->assertTrue((bool) $afterReview['ready']);
        $this->assertSame([], (array) ($afterReview['opening']['missing_tabs'] ?? []));
    }

    public function testOwnerRoiIsVisibleOnlyForWorkspaceOwnerAccounts(): void
    {
        $this->installFinancePlugin();
        $nonOwnerId = $this->createFinanceUser('finance-accountant@example.test', 'accountant', false);
        $ledger = new FinanceLedgerService();
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'opening_balance',
            'transaction_date' => '2026-05-01',
            'amount' => 1000,
            'currency' => 'USD',
            'opening_cash' => 1000,
            'opening_equity' => 1000,
            'opening_setup_complete' => 1,
        ], $this->userId);
        (new FinanceOwnerEquityService())->saveProfiles(1, [[
            'user_id' => $this->userId,
            'ownership_percent' => 100,
            'opening_owner_capital' => 1000,
            'opening_owner_draws' => 100,
            'currency' => 'USD',
        ]], $this->userId);
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'founder_capital',
            'transaction_date' => '2026-05-02',
            'amount' => 500,
            'currency' => 'USD',
            'owner_user_id' => $this->userId,
        ], $this->userId);
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'owner_draw',
            'transaction_date' => '2026-05-03',
            'amount' => 50,
            'currency' => 'USD',
            'owner_user_id' => $this->userId,
        ], $this->userId);

        $ownerDashboard = (new FounderFinanceService())->dashboard(1, $this->userId, '2026-05-01', '2026-05-31');
        $this->assertArrayHasKey('owner_roi', $ownerDashboard);
        $this->assertSame(1500.0, (float) $ownerDashboard['owner_roi']['capital_contributed']);
        $this->assertSame(150.0, (float) $ownerDashboard['owner_roi']['draws']);

        $nonOwnerDashboard = (new FounderFinanceService())->dashboard(1, $nonOwnerId, '2026-05-01', '2026-05-31');
        $this->assertArrayNotHasKey('owner_roi', $nonOwnerDashboard);

        $this->expectException(\InvalidArgumentException::class);
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'founder_capital',
            'transaction_date' => '2026-05-04',
            'amount' => 25,
            'currency' => 'USD',
            'owner_user_id' => $nonOwnerId,
        ], $this->userId);
    }

    public function testFinanceLedgerRecordsCapitalFundingLoansAssetsAndDraws(): void
    {
        $ledger = new FinanceLedgerService();
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'opening_balance',
            'transaction_date' => '2026-05-01',
            'amount' => 1000,
            'currency' => 'USD',
            'counterparty' => 'Opening books',
        ], $this->userId);
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'founder_capital',
            'transaction_date' => '2026-05-02',
            'amount' => 5000,
            'currency' => 'USD',
            'counterparty' => 'Founder',
        ], $this->userId);
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'equity_funding',
            'transaction_date' => '2026-05-03',
            'amount' => 7000,
            'currency' => 'USD',
            'counterparty' => 'Investor',
        ], $this->userId);
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'loan_received',
            'transaction_date' => '2026-05-04',
            'amount' => 3000,
            'currency' => 'USD',
            'counterparty' => 'Bank',
        ], $this->userId);
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'asset_purchase',
            'transaction_date' => '2026-05-05',
            'amount' => 1200,
            'currency' => 'USD',
            'counterparty' => 'Laptop vendor',
        ], $this->userId);
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'loan_repayment',
            'transaction_date' => '2026-05-06',
            'amount' => 500,
            'currency' => 'USD',
            'counterparty' => 'Bank',
        ], $this->userId);
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'owner_draw',
            'transaction_date' => '2026-05-07',
            'amount' => 400,
            'currency' => 'USD',
            'counterparty' => 'Founder',
        ], $this->userId);

        $entries = Database::query(
            "SELECT transaction_id,
                SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE 0 END) AS debits,
                SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END) AS credits
             FROM finance_journal_entries
             WHERE workspace_id = 1
             GROUP BY transaction_id"
        );
        $this->assertCount(7, $entries);
        foreach ($entries as $entry) {
            $this->assertSame((float) $entry['debits'], (float) $entry['credits']);
        }

        $statements = (new FinanceStatementService())->generate(1, $this->userId, '2026-05-01', '2026-05-31');

        $this->assertSame(0.0, (float) $statements['profit_and_loss']['summary']['revenue']);
        $this->assertSame(0.0, (float) $statements['profit_and_loss']['summary']['net_income']);
        $this->assertSame(14100.0, (float) $statements['cash_flow']['summary']['financing_cash_flow']);
        $this->assertSame(15000.0, (float) $statements['cash_flow']['summary']['cash_in']);
        $this->assertSame(2100.0, (float) $statements['cash_flow']['summary']['cash_out']);
        $this->assertSame(12900.0, (float) $statements['cash_flow']['summary']['net_cash_movement']);
        $this->assertSame(13900.0, (float) $statements['cash_flow']['summary']['ending_cash']);
        $this->assertSame(2500.0, (float) $statements['working_balance_sheet']['accounts']['loan_payable']);
        $this->assertSame(1200.0, (float) $statements['working_balance_sheet']['accounts']['fixed_assets']);
        $this->assertSame(12600.0, (float) $statements['working_balance_sheet']['equity']['total_equity']);
        $this->assertSame(0.0, (float) $statements['working_balance_sheet']['summary']['balance_difference']);
    }

    public function testLoanRepaymentInterestHitsPnlWithoutCountingFundingAsRevenue(): void
    {
        $ledger = new FinanceLedgerService();
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'opening_balance',
            'transaction_date' => '2026-05-01',
            'amount' => 1000,
            'currency' => 'USD',
        ], $this->userId);
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'loan_received',
            'transaction_date' => '2026-05-02',
            'amount' => 1000,
            'currency' => 'USD',
        ], $this->userId);
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'loan_repayment',
            'transaction_date' => '2026-05-03',
            'amount' => 250,
            'interest_amount' => 50,
            'currency' => 'USD',
        ], $this->userId);

        $statements = (new FinanceStatementService())->generate(1, $this->userId, '2026-05-01', '2026-05-31');

        $this->assertSame(0.0, (float) $statements['profit_and_loss']['summary']['revenue']);
        $this->assertSame(50.0, (float) $statements['profit_and_loss']['summary']['non_operating_expense']);
        $this->assertSame(-50.0, (float) $statements['profit_and_loss']['summary']['net_income']);
        $this->assertSame(800.0, (float) $statements['working_balance_sheet']['accounts']['loan_payable']);
    }

    public function testFinanceSetupStatusFlagsMissingOpeningLoanReviewAndMixedCurrencies(): void
    {
        $expenseService = new FinanceExpenseService();
        $expenseService->saveExpense(1, [
            'description' => 'USD software',
            'amount' => 100,
            'currency' => 'USD',
            'expense_date' => '2026-05-04',
            'status' => 'paid',
        ], $this->userId);
        $expenseService->saveExpense(1, [
            'description' => 'EUR tool',
            'amount' => 50,
            'currency' => 'EUR',
            'expense_date' => '2026-05-05',
            'status' => 'paid',
        ], $this->userId);

        $ledger = new FinanceLedgerService();
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'loan_repayment',
            'transaction_date' => '2026-05-06',
            'amount' => 250,
            'currency' => 'USD',
        ], $this->userId);

        $statements = (new FinanceStatementService())->generate(1, $this->userId, '2026-05-01', '2026-05-31');

        $this->assertSame('missing', $statements['setup_status']['opening_balances']['state']);
        $this->assertSame('review', $statements['setup_status']['loans']['state']);
        $this->assertSame('review', $statements['setup_status']['mixed_currency']['state']);
        $this->assertContains('loan_repayments_without_loan_balance', $statements['report_quality']['warnings']);
        $this->assertContains('mixed_currency_without_fx', $statements['report_quality']['warnings']);
    }

    public function testOpeningDetailsCompleteSetupStatusAndFeedBalanceSheet(): void
    {
        $ledger = new FinanceLedgerService();
        $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'opening_balance',
            'transaction_date' => '2026-05-01',
            'amount' => 1000,
            'currency' => 'USD',
            'opening_cash' => 1000,
            'opening_receivables' => 300,
            'opening_payables' => 100,
            'opening_loan_balance' => 200,
            'opening_assets' => 500,
            'opening_equity' => 1500,
        ], $this->userId);

        $statements = (new FinanceStatementService())->generate(1, $this->userId, '2026-05-01', '2026-05-31');

        $this->assertSame('done', $statements['setup_status']['opening_balances']['state']);
        $this->assertSame(1000.0, (float) $statements['cash_flow']['summary']['opening_cash']);
        $this->assertSame(500.0, (float) $statements['working_balance_sheet']['accounts']['fixed_assets']);
        $this->assertSame(200.0, (float) $statements['working_balance_sheet']['accounts']['loan_payable']);
        $this->assertSame(1500.0, (float) $statements['working_balance_sheet']['equity']['retained_earnings']);
    }

    public function testFinanceLedgerEntriesCanBeDeletedAndReplaced(): void
    {
        $ledger = new FinanceLedgerService();
        $transactionId = $ledger->saveGuidedTransaction(1, [
            'transaction_type' => 'founder_capital',
            'transaction_date' => '2026-05-02',
            'amount' => 500,
            'currency' => 'USD',
            'counterparty' => 'Founder',
        ], $this->userId);

        $ledger->replaceGuidedTransaction(1, $transactionId, [
            'transaction_type' => 'equity_funding',
            'transaction_date' => '2026-05-03',
            'amount' => 750,
            'currency' => 'USD',
            'counterparty' => 'Investor',
        ], $this->userId);

        $transaction = $ledger->getTransaction(1, $transactionId);
        $this->assertNotNull($transaction);
        $this->assertSame('equity_funding', $transaction['transaction_type']);
        $this->assertSame(750.0, (float) $transaction['amount']);

        $entryTotals = Database::queryOne(
            "SELECT
                SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE 0 END) AS debits,
                SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END) AS credits
             FROM finance_journal_entries
             WHERE workspace_id = 1 AND transaction_id = ?",
            [$transactionId]
        );
        $this->assertSame((float) $entryTotals['debits'], (float) $entryTotals['credits']);

        $ledger->deleteGuidedTransaction(1, $transactionId);
        $this->assertNull($ledger->getTransaction(1, $transactionId));
        $remainingEntries = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM finance_journal_entries WHERE workspace_id = 1 AND transaction_id = ?",
            [$transactionId]
        )['c'] ?? 0);
        $this->assertSame(0, $remainingEntries);
    }

    public function testFinanceStatementDrilldownsExposeSourceRows(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_by, created_at) VALUES (1, 'D', 'Buyer', 'drilldown@example.test', ?, NOW())",
            [$this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO invoices (
                workspace_id, document_type, status, invoice_number, contact_id, created_by, currency,
                issue_date, due_date, payment_terms_days, title, subtotal, grand_total, amount_paid, balance_due, paid_at
             ) VALUES
             (1, 'invoice', 'paid', 'FIN-DRILL-PAID', ?, ?, 'USD', '2026-05-02', '2026-05-09', 7, 'Paid drilldown', 1000, 1000, 1000, 0, '2026-05-03 10:00:00'),
             (1, 'invoice', 'sent', 'FIN-DRILL-SENT', ?, ?, 'USD', '2026-05-04', '2026-05-11', 7, 'Sent drilldown', 300, 300, 0, 300, NULL)",
            [$contactId, $this->userId, $contactId, $this->userId]
        );

        $expenseService = new FinanceExpenseService();
        $categoryId = $expenseService->saveCategory(1, 'Financing Fees', 'operations', $this->userId, [
            'statement_group' => 'operating_expense',
            'cash_flow_group' => 'financing',
        ]);
        $expenseService->saveExpense(1, [
            'category_id' => $categoryId,
            'description' => 'Funding fee',
            'amount' => 80,
            'currency' => 'USD',
            'expense_date' => '2026-05-05',
            'status' => 'paid',
        ], $this->userId);

        (new FinanceLedgerService())->saveGuidedTransaction(1, [
            'transaction_type' => 'equity_funding',
            'transaction_date' => '2026-05-06',
            'amount' => 2000,
            'currency' => 'USD',
        ], $this->userId);

        $statements = (new FinanceStatementService())->generate(1, $this->userId, '2026-05-01', '2026-05-31');

        $this->assertNotEmpty($statements['profit_and_loss']['drilldowns']['revenue']);
        $this->assertNotEmpty($statements['cash_flow']['drilldowns']['financing']['ledger_entries']);
        $this->assertNotEmpty($statements['cash_flow']['drilldowns']['financing']['expenses']);
        $this->assertNotEmpty($statements['working_balance_sheet']['drilldowns']['equity']['ledger_entries']);
        $this->assertNotEmpty($statements['working_balance_sheet']['drilldowns']['receivables']['invoices']);
    }

    public function testFinanceExpensesResolveVendorsAndSummarizeSpendByVendor(): void
    {
        $expenseService = new FinanceExpenseService();

        $expenseService->saveExpense(1, [
            'vendor' => ' Acme Supplies ',
            'description' => 'Paper stock',
            'amount' => 100,
            'currency' => 'USD',
            'expense_date' => '2026-05-05',
            'status' => 'paid',
        ], $this->userId);
        $expenseService->saveExpense(1, [
            'vendor' => 'Acme Supplies',
            'description' => 'June restock',
            'amount' => 200,
            'currency' => 'USD',
            'expense_date' => '2026-06-05',
            'status' => 'paid',
        ], $this->userId);
        $expenseService->saveExpense(1, [
            'vendor' => 'Acme Supplies',
            'description' => 'Planned restock',
            'amount' => 50,
            'currency' => 'USD',
            'expense_date' => '2026-05-08',
            'status' => 'planned',
        ], $this->userId);
        $expenseService->saveRecurringExpense(1, [
            'vendor' => 'Acme Supplies',
            'description' => 'Monthly stock plan',
            'amount' => 75,
            'currency' => 'USD',
            'frequency' => 'monthly',
            'next_due_date' => '2026-05-20',
            'is_active' => 1,
        ], $this->userId);

        $vendors = Database::query("SELECT * FROM finance_vendors WHERE workspace_id = 1 AND name = 'Acme Supplies'");
        $this->assertCount(1, $vendors);

        $expenseRows = Database::query("SELECT vendor_id FROM finance_expenses WHERE workspace_id = 1 AND vendor = 'Acme Supplies'");
        $this->assertNotEmpty($expenseRows);
        foreach ($expenseRows as $row) {
            $this->assertSame((int) $vendors[0]['id'], (int) $row['vendor_id']);
        }

        $recurring = Database::queryOne("SELECT vendor_id FROM finance_recurring_expenses WHERE workspace_id = 1 AND vendor = 'Acme Supplies' LIMIT 1");
        $this->assertSame((int) $vendors[0]['id'], (int) ($recurring['vendor_id'] ?? 0));

        $summary = $expenseService->expenseByVendor(1, '2026-05-01', '2026-05-31');
        $this->assertCount(1, $summary);
        $this->assertSame('Acme Supplies', $summary[0]['vendor_name']);
        $this->assertSame(100.0, (float) $summary[0]['paid_total']);
        $this->assertSame(2, (int) $summary[0]['expense_count']);
        $this->assertSame('2026-05-08', $summary[0]['latest_expense_date']);

        $dashboard = (new FounderFinanceService())->dashboard(1, $this->userId, '2026-05-01', '2026-05-31');
        $this->assertSame('Acme Supplies', $dashboard['expense_by_vendor'][0]['vendor_name']);
        $this->assertSame('Acme Supplies', $dashboard['expenses'][0]['vendor_name']);
    }

    public function testLegacyVendorTextStillDisplaysAndTracksWithoutVendorId(): void
    {
        Database::execute(
            "INSERT INTO finance_expenses (
                workspace_id, vendor, description, amount, currency, expense_date, payment_method, status, created_by
             ) VALUES (1, 'Legacy Vendor', 'Legacy import', 42, 'USD', '2026-05-10', 'other', 'paid', ?)",
            [$this->userId]
        );

        $expenseService = new FinanceExpenseService();
        $expenses = $expenseService->listExpenses(1, ['date_from' => '2026-05-01', 'date_to' => '2026-05-31']);
        $summary = $expenseService->expenseByVendor(1, '2026-05-01', '2026-05-31');

        $this->assertSame('Legacy Vendor', $expenses[0]['vendor_name']);
        $this->assertSame('Legacy Vendor', $summary[0]['vendor_name']);
        $this->assertSame(42.0, (float) $summary[0]['paid_total']);
    }

    public function testDashboardFallsBackToCrmDefaultCurrencyWhenNoFinanceBudgetExists(): void
    {
        $this->setDefaultCurrency('KES', 'Kenyan Shilling', 'KES');

        $dashboard = (new FounderFinanceService())->dashboard(1, $this->userId, '2026-05-01', '2026-05-31');

        $this->assertSame('KES', $dashboard['summary']['currency']);
        $this->assertSame('KES', $dashboard['statements']['currency']);
    }

    public function testSavedFinanceBudgetCurrencyWinsOverCrmDefaultCurrency(): void
    {
        $this->setDefaultCurrency('KES', 'Kenyan Shilling', 'KES');

        (new FounderFinanceService())->saveFounderBudget(1, $this->userId, [
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'currency' => 'EUR',
            'target_cash_reserve' => 1000,
        ]);

        $dashboard = (new FounderFinanceService())->dashboard(1, $this->userId, '2026-05-01', '2026-05-31');

        $this->assertSame('EUR', $dashboard['summary']['currency']);
        $this->assertSame('EUR', $dashboard['statements']['currency']);
    }

    public function testDashboardUsesPaidInvoicesAndExpensesForFounderFinance(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_by, created_at) VALUES (1, 'A', 'Buyer', 'buyer@example.test', ?, NOW())",
            [$this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO invoices (
                workspace_id, document_type, status, invoice_number, contact_id, created_by, currency,
                issue_date, due_date, payment_terms_days, title, subtotal, grand_total, amount_paid, balance_due, paid_at
             ) VALUES
             (1, 'invoice', 'paid', 'FIN-PAID-1', ?, ?, 'USD', '2026-05-02', '2026-05-09', 7, 'Paid', 1000, 1000, 1000, 0, '2026-05-03 10:00:00'),
             (1, 'invoice', 'sent', 'FIN-SENT-1', ?, ?, 'USD', '2026-05-04', '2026-05-11', 7, 'Sent', 3000, 3000, 0, 3000, NULL)",
            [$contactId, $this->userId, $contactId, $this->userId]
        );

        $expenseService = new FinanceExpenseService();
        $categoryId = $expenseService->saveCategory(1, 'Marketing', 'marketing', $this->userId);
        $expenseService->saveExpense(1, [
            'category_id' => $categoryId,
            'description' => 'Launch ads',
            'amount' => 250,
            'currency' => 'USD',
            'expense_date' => '2026-05-05',
            'status' => 'paid',
        ], $this->userId);

        $finance = new FounderFinanceService();
        $finance->saveFounderBudget(1, $this->userId, [
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'currency' => 'USD',
            'target_cash_reserve' => 1000,
            'target_deal_value' => 500,
            'monthly_marketing_budget' => 300,
            'monthly_fixed_costs' => 250,
            'target_cac' => 200,
            'budget_lines' => [['label' => 'Marketing', 'category_id' => $categoryId, 'planned_amount' => 300]],
        ]);

        $dashboard = $finance->dashboard(1, $this->userId, '2026-05-01', '2026-05-31');
        $summary = (array) $dashboard['summary'];

        $this->assertSame(1000.0, (float) $summary['money_in']);
        $this->assertSame(250.0, (float) $summary['money_out']);
        $this->assertSame(750.0, (float) $summary['net_cash_movement']);
        $this->assertSame(4000.0, (float) $summary['pipeline_revenue']);
        $this->assertSame(250.0, (float) $summary['cac']);
        $this->assertSame(1, (int) $summary['break_even_deals']);
        $this->assertContains('cac_above_target', $summary['guidance_flags']);

        $aiFinance = (array) $finance->aiContext(1, $this->userId);
        $this->assertSame(500.0, (float) $aiFinance['target_deal_value']);
        $this->assertSame(1000.0, (float) $aiFinance['avg_paid_invoice']);
        $this->assertSame(1, (int) $aiFinance['paid_invoice_count']);
        $this->assertSame(1, (int) $aiFinance['paid_customer_count']);
        $this->assertSame(4000.0, (float) $aiFinance['pipeline_revenue']);
        $this->assertSame(1000.0, (float) $aiFinance['avg_revenue_per_customer']);
        $this->assertSame(300.0, (float) $aiFinance['monthly_marketing_budget']);
        $this->assertSame(250.0, (float) $aiFinance['monthly_fixed_costs']);
        $this->assertSame(200.0, (float) $aiFinance['target_cac']);
        $this->assertSame(250.0, (float) $aiFinance['cac']);
        $this->assertSame(0.3, (float) $aiFinance['payback_months']);
        $this->assertSame(75.0, (float) $aiFinance['gross_margin_percent']);
        $this->assertSame(50.0, (float) $aiFinance['budget_variance_total']);
        $this->assertSame(50.0, (float) ($aiFinance['budget_variance']['total_variance'] ?? 0));
        $this->assertSame('Marketing', (string) ($aiFinance['expense_by_category'][0]['category_name'] ?? ''));
        $this->assertSame(0, (int) $aiFinance['manual_income_count']);
    }

    public function testManualIncomeAndBankStatementKeepCapitalSeparateFromRevenue(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_by, created_at) VALUES (1, 'B', 'Buyer', 'bank-buyer@example.test', ?, NOW())",
            [$this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO invoices (
                workspace_id, document_type, status, invoice_number, contact_id, created_by, currency,
                issue_date, due_date, payment_terms_days, title, subtotal, grand_total, amount_paid, balance_due, paid_at
             ) VALUES
             (1, 'invoice', 'paid', 'FIN-BANK-PAID', ?, ?, 'USD', '2026-05-02', '2026-05-09', 7, 'Paid sale', 1000, 1000, 1000, 0, '2026-05-03 10:00:00')",
            [$contactId, $this->userId]
        );

        $cashAccountId = (new FinanceCashAccountService())->defaultAccountId(1);
        $incomeService = new FinanceIncomeService();
        $incomeCategories = $incomeService->listCategories(1);
        $serviceIncomeId = (int) (array_values(array_filter($incomeCategories, static fn(array $row): bool => (string) ($row['name'] ?? '') === 'Service income'))[0]['id'] ?? 0);
        $this->assertGreaterThan(0, $serviceIncomeId);
        $incomeService->saveEntry(1, [
            'cash_account_id' => $cashAccountId,
            'income_category_id' => $serviceIncomeId,
            'income_type' => 'sale',
            'source' => 'Walk-in',
            'description' => 'Cash sale',
            'amount' => 250,
            'currency' => 'USD',
            'income_date' => '2026-05-04',
        ], $this->userId);
        $incomeService->saveEntry(1, [
            'cash_account_id' => $cashAccountId,
            'income_type' => 'other_income',
            'source' => 'Partner',
            'description' => 'Referral bonus',
            'amount' => 50,
            'currency' => 'USD',
            'income_date' => '2026-05-05',
        ], $this->userId);

        (new FinanceExpenseService())->saveExpense(1, [
            'cash_account_id' => $cashAccountId,
            'description' => 'Bank fee',
            'amount' => 30,
            'currency' => 'USD',
            'expense_date' => '2026-05-06',
            'status' => 'paid',
        ], $this->userId);
        (new FinanceLedgerService())->saveGuidedTransaction(1, [
            'transaction_type' => 'founder_capital',
            'transaction_date' => '2026-05-07',
            'cash_account_id' => $cashAccountId,
            'amount' => 500,
            'currency' => 'USD',
            'counterparty' => 'Founder',
        ], $this->userId);

        $statements = (new FinanceStatementService())->generate(1, $this->userId, '2026-05-01', '2026-05-31');

        $this->assertSame(1300.0, (float) $statements['profit_and_loss']['summary']['revenue']);
        $this->assertSame(1250.0, (float) $statements['profit_and_loss']['summary']['sales_revenue']);
        $this->assertSame(50.0, (float) $statements['profit_and_loss']['summary']['manual_other_income']);
        $this->assertSame(1270.0, (float) $statements['profit_and_loss']['summary']['net_income']);
        $this->assertSame(500.0, (float) $statements['cash_flow']['summary']['financing_cash_flow']);
        $this->assertSame(1770.0, (float) $statements['cash_flow']['summary']['net_cash_movement']);
        $this->assertSame(1770.0, (float) $statements['bank_statement']['summary']['ending_balance']);
        $this->assertCount(5, $statements['bank_statement']['rows']);
        $incomeRows = $incomeService->listEntries(1, ['date_from' => '2026-05-01', 'date_to' => '2026-05-31']);
        $this->assertSame('Service income', (string) ($incomeRows[1]['income_category_name'] ?? ''));
        $labelRows = (array) ($statements['profit_and_loss']['drilldowns']['income_labels'] ?? []);
        $this->assertContains('Service income', array_column($labelRows, 'label_name'));
    }

    public function testFinanceStatementsUseCurrentDataWithoutCountingUnpaidInvoicesAsCash(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_by, created_at) VALUES (1, 'P', 'Buyer', 'pnl-buyer@example.test', ?, NOW())",
            [$this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO invoices (
                workspace_id, document_type, status, invoice_number, contact_id, created_by, currency,
                issue_date, due_date, payment_terms_days, title, subtotal, grand_total, amount_paid, balance_due, paid_at
             ) VALUES
             (1, 'invoice', 'paid', 'FIN-STMT-PAID-1', ?, ?, 'USD', '2026-05-02', '2026-05-09', 7, 'Paid', 2000, 2000, 2000, 0, '2026-05-03 10:00:00'),
             (1, 'invoice', 'sent', 'FIN-STMT-SENT-1', ?, ?, 'USD', '2026-05-04', '2026-05-11', 7, 'Sent', 5000, 5000, 0, 5000, NULL)",
            [$contactId, $this->userId, $contactId, $this->userId]
        );

        $expenseService = new FinanceExpenseService();
        $deliveryId = $expenseService->saveCategory(1, 'Delivery Costs', 'inventory', $this->userId, [
            'statement_group' => 'cost_of_sales',
            'cash_flow_group' => 'operating',
            'is_cogs' => 1,
        ]);
        $opsId = $expenseService->saveCategory(1, 'Ops Costs', 'operations', $this->userId, [
            'statement_group' => 'operating_expense',
            'cash_flow_group' => 'operating',
        ]);
        $expenseService->saveExpense(1, [
            'category_id' => $deliveryId,
            'description' => 'Fulfillment',
            'amount' => 400,
            'currency' => 'USD',
            'expense_date' => '2026-05-05',
            'status' => 'paid',
        ], $this->userId);
        $expenseService->saveExpense(1, [
            'category_id' => $opsId,
            'description' => 'Planned SaaS',
            'amount' => 100,
            'currency' => 'USD',
            'expense_date' => '2026-05-06',
            'status' => 'planned',
        ], $this->userId);

        (new FounderFinanceService())->saveFounderBudget(1, $this->userId, [
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'currency' => 'USD',
            'target_cash_reserve' => 1500,
            'budget_lines' => [['label' => 'Delivery Costs', 'category_id' => $deliveryId, 'planned_amount' => 700]],
        ]);

        $statements = (new FinanceStatementService())->generate(1, $this->userId, '2026-05-01', '2026-05-31');

        $this->assertSame(2000.0, (float) $statements['cash_flow']['summary']['cash_in']);
        $this->assertSame(400.0, (float) $statements['profit_and_loss']['summary']['cost_of_sales']);
        $this->assertSame(1600.0, (float) $statements['profit_and_loss']['summary']['net_income']);
        $this->assertSame(5000.0, (float) $statements['working_balance_sheet']['receivables']);
        $this->assertSame(100.0, (float) $statements['working_balance_sheet']['planned_outflows']);
    }

    public function testOwnerSalaryAndProfitShareUseExpenseLabelsWithPostNetReporting(): void
    {
        $this->assertArrayNotHasKey('owner_draw', (new FinanceLedgerService())->transactionTypes());
        $cashAccountId = (new FinanceCashAccountService())->defaultAccountId(1);
        (new FinanceIncomeService())->saveEntry(1, [
            'cash_account_id' => $cashAccountId,
            'income_type' => 'sale',
            'source' => 'Counter',
            'description' => 'Daily sales',
            'amount' => 1000,
            'currency' => 'USD',
            'income_date' => '2026-05-10',
        ], $this->userId);

        $expenseService = new FinanceExpenseService();
        $categories = $expenseService->listCategories(1);
        $categoryId = static function (string $name) use ($categories): int {
            foreach ($categories as $category) {
                if ((string) ($category['name'] ?? '') === $name) {
                    return (int) ($category['id'] ?? 0);
                }
            }
            return 0;
        };
        $categoryTreatment = static function (string $name) use ($categories): string {
            foreach ($categories as $category) {
                if ((string) ($category['name'] ?? '') === $name) {
                    return (string) ($category['profit_treatment'] ?? '');
                }
            }
            return '';
        };
        $ownerSalaryId = $categoryId('Owner salary');
        $profitShareId = $categoryId('Profit share');
        $this->assertGreaterThan(0, $ownerSalaryId);
        $this->assertGreaterThan(0, $profitShareId);

        $expenseService->saveExpense(1, [
            'cash_account_id' => $cashAccountId,
            'category_id' => $ownerSalaryId,
            'vendor' => 'Owner',
            'description' => 'Owner salary',
            'amount' => 100,
            'currency' => 'USD',
            'expense_date' => '2026-05-11',
            'status' => 'paid',
        ], $this->userId);
        $expenseService->saveExpense(1, [
            'cash_account_id' => $cashAccountId,
            'category_id' => $profitShareId,
            'vendor' => 'Owner',
            'description' => 'Profit share',
            'amount' => 200,
            'currency' => 'USD',
            'expense_date' => '2026-05-12',
            'status' => 'paid',
        ], $this->userId);

        $statements = (new FinanceStatementService())->generate(1, $this->userId, '2026-05-01', '2026-05-31');

        $this->assertSame(100.0, (float) $statements['profit_and_loss']['summary']['operating_expenses']);
        $this->assertSame(900.0, (float) $statements['profit_and_loss']['summary']['net_income']);
        $this->assertSame(200.0, (float) $statements['profit_and_loss']['summary']['profit_share_payouts']);
        $this->assertSame(700.0, (float) $statements['profit_and_loss']['summary']['net_after_profit_share']);
        $this->assertSame(900.0, (float) $statements['cash_flow']['summary']['operating_cash_flow']);
        $this->assertSame(-200.0, (float) $statements['cash_flow']['summary']['financing_cash_flow']);
        $this->assertSame(700.0, (float) $statements['cash_flow']['summary']['net_cash_movement']);
        $this->assertSame('post_net_profit_share', $categoryTreatment('Profit share'));
    }

    public function testAccountantRoleHasFinanceAccessWithoutUserAdminPowers(): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'accountant' LIMIT 1");
        $this->assertNotEmpty($role);

        $permissions = Database::query(
            "SELECT p.permission_key
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ? AND rp.can_access = 1",
            [(int) $role['id']]
        );
        $keys = array_column($permissions, 'permission_key');

        $this->assertContains('finance.view', $keys);
        $this->assertContains('finance.manage', $keys);
        $this->assertContains('finance.reports.view', $keys);
        $this->assertContains('finance.accounting.manage', $keys);
        $this->assertContains('ai.finance.guidance', $keys);
        $this->assertContains('invoices.view', $keys);
        $this->assertNotContains('admin.users.manage', $keys);
        $this->assertNotContains('platform.settings.manage', $keys);
    }

    private function installFinancePlugin(): void
    {
        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at
             ) VALUES (1, ?, 'installed', '{}', ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL, updated_at = NOW()",
            [WorkspaceSkillCatalogService::PLUGIN_FINANCE, $this->userId, $this->userId]
        );
    }

    private function createFinanceUser(string $email, string $roleSlug, bool $isOwner): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, NOW())",
            [uniqid('finance-user-', true), $email, password_hash('secret', PASSWORD_DEFAULT), $isOwner ? 'owner' : 'viewer']
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, ?, 'active', ?, NOW())
             ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), membership_status = 'active', is_owner = VALUES(is_owner)",
            [$userId, $roleSlug, $isOwner ? 1 : 0]
        );

        return $userId;
    }

    private function setDefaultCurrency(string $code, string $name, string $symbol): void
    {
        Database::execute("UPDATE currencies SET is_default = 0");
        Database::execute(
            "INSERT INTO currencies (code, name, symbol, symbol_position, decimal_places, is_active, is_default)
             VALUES (?, ?, ?, 'before', 2, 1, 1)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                symbol = VALUES(symbol),
                is_active = 1,
                is_default = 1",
            [$code, $name, $symbol]
        );
    }
}
