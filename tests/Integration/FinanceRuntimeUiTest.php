<?php

namespace CRM\Tests\Integration;

use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\FinanceCashAccountService;
use CRM\Services\FinanceExpenseService;
use CRM\Services\FinanceIncomeService;
use CRM\Services\FinanceLedgerService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceFinanceGateService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class FinanceRuntimeUiTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testFinanceRuntimeTabsRenderBeginnerUiForReadyWorkspace(): void
    {
        $seed = $this->seedReadyFinanceWorkspace();

        $gate = (new WorkspaceFinanceGateService())->status((int) $seed['workspace_id']);
        $this->assertTrue((bool) ($gate['ready'] ?? false), json_encode($gate, JSON_PRETTY_PRINT));

        $today = $this->financeTab($seed, 'today');
        $this->assertResponseOk($today);
        $todayBody = (string) ($today['body'] ?? '');
        $this->assertStringContainsString('data-finance-open="finance-modal-in"', $todayBody);
        $this->assertStringContainsString('data-finance-open="finance-modal-out"', $todayBody);
        $this->assertStringContainsString('finance-btn-loud finance-btn-in', $todayBody);
        $this->assertStringContainsString('finance-btn-loud finance-btn-out', $todayBody);
        $this->assertStringContainsString('Latest In', $todayBody);
        $this->assertStringContainsString('Latest Out', $todayBody);
        $this->assertStringContainsString('Bank</h2>', $todayBody);
        $this->assertStringContainsString('finance-kpi-strip', $todayBody);
        $this->assertStringContainsString('finance-card-in', $todayBody);
        $this->assertStringContainsString('finance-card-out', $todayBody);
        $this->assertStringContainsString('finance-card-bank', $todayBody);
        $this->assertStringContainsString('finance-modal-card-in', $todayBody);
        $this->assertStringContainsString('finance-modal-card-out', $todayBody);
        $this->assertStringContainsString('finance-modal-card-funding', $todayBody);
        $this->assertStringContainsString('@media(max-width:760px)', $todayBody);
        $this->assertStringContainsString('finance-table td::before', $todayBody);
        $this->assertStringNotContainsString('In. Out. Capital. Bank.', $todayBody);

        $in = $this->financeTab($seed, 'in');
        $this->assertResponseOk($in);
        $inBody = (string) ($in['body'] ?? '');
        $this->assertStringContainsString('Add money in', $inBody);
        $this->assertStringContainsString('data-finance-open="finance-modal-in"', $inBody);
        $this->assertStringContainsString('Invoices', $inBody);
        $this->assertStringContainsString('Other entries', $inBody);
        $this->assertStringContainsString('name="income_category_id" required', $inBody);
        $this->assertStringContainsString('<summary>More</summary>', $inBody);
        $this->assertStringContainsString('Client A', $inBody);

        $out = $this->financeTab($seed, 'out');
        $this->assertResponseOk($out);
        $outBody = (string) ($out['body'] ?? '');
        $this->assertStringContainsString('Add money out', $outBody);
        $this->assertStringContainsString('data-finance-open="finance-modal-out"', $outBody);
        $this->assertStringContainsString('name="category_id" required', $outBody);
        $this->assertStringContainsString('Owner salary', $outBody);
        $this->assertStringContainsString('Profit share', $outBody);
        $this->assertStringContainsString('finance-pill warn">Share</span>', $outBody);
        $this->assertStringContainsString('<summary>More</summary>', $outBody);

        $capital = $this->financeTab($seed, 'capital');
        $this->assertResponseOk($capital);
        $capitalBody = (string) ($capital['body'] ?? '');
        $this->assertStringContainsString('Funding', $capitalBody);
        $this->assertStringContainsString('Add funding', $capitalBody);
        $this->assertStringContainsString('data-finance-open="finance-modal-funding"', $capitalBody);
        $this->assertStringContainsString('Owner money in', $capitalBody);
        $this->assertStringContainsString('Opening correction', $capitalBody);
        $this->assertStringNotContainsString('Owner draw/dividend', $capitalBody);

        $bank = $this->financeTab($seed, 'bank');
        $this->assertResponseOk($bank);
        $bankBody = (string) ($bank['body'] ?? '');
        foreach (['Open', 'In', 'Out', 'Close', 'Date', 'Ref', 'Balance'] as $label) {
            $this->assertStringContainsString($label, $bankBody);
        }

        $reports = $this->financeTab($seed, 'reports');
        $this->assertResponseOk($reports);
        $reportsBody = (string) ($reports['body'] ?? '');
        foreach (['Cashflow', 'Profit', 'Bank', 'Balance', 'Export PDF', 'Net after profit share', 'Statement of Cash Flows', 'Income Statement', 'Bank Statement', 'Balance Sheet'] as $label) {
            $this->assertStringContainsString($label, $reportsBody);
        }
        $this->assertStringContainsString('role="tablist" aria-label="Finance reports"', $reportsBody);
        foreach (['cashflow', 'profit', 'bank', 'balance'] as $reportType) {
            $this->assertStringContainsString('data-finance-report-tab="' . $reportType . '"', $reportsBody);
            $this->assertStringContainsString('data-finance-report-panel="' . $reportType . '"', $reportsBody);
            $this->assertStringContainsString('finance_report_pdf.php?type=' . $reportType, $reportsBody);
        }
        $this->assertMatchesRegularExpression('/id="finance-report-tab-cashflow"[\s\S]*?aria-selected="true"[\s\S]*?data-finance-report-tab="cashflow"/', $reportsBody);
        $this->assertMatchesRegularExpression('/id="finance-report-tab-profit"[\s\S]*?aria-selected="false"[\s\S]*?data-finance-report-tab="profit"/', $reportsBody);
        $this->assertMatchesRegularExpression('/id="finance-report-panel-cashflow"[\s\S]*?data-finance-report-panel="cashflow"\s*>/', $reportsBody);
        $this->assertMatchesRegularExpression('/id="finance-report-panel-profit"[\s\S]*?data-finance-report-panel="profit"[\s\S]*?hidden\s*>/', $reportsBody);
        $this->assertStringContainsString('finance-report-document', $reportsBody);
        $this->assertStringContainsString('finance-report-t-account', $reportsBody);

        $setup = $this->financeTab($seed, 'setup');
        $this->assertResponseOk($setup);
        $setupBody = (string) ($setup['body'] ?? '');
        foreach (['Initial', 'Edit setup', 'Accounts', 'Income labels', 'Expense labels', 'Recurring'] as $label) {
            $this->assertStringContainsString($label, $setupBody);
        }
        $this->assertStringContainsString('workspace_skills.php?module=finance#setup', $setupBody);
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function seedReadyFinanceWorkspace(): array
    {
        $seed = $this->seedWorkspace('finance-runtime-ui');
        $this->grantRolePermissions('owner', [
            'workspace.skills.view',
            'workspace.skills.manage',
            'finance.view',
            'finance.manage',
        ]);
        Authorization::assignUserRoleBySlug((int) $seed['user_id'], 'owner', (int) $seed['user_id']);
        $this->activateSession($seed, 'owner');
        $this->completeOnboarding((int) $seed['workspace_id']);

        (new WorkspaceSkillCatalogService())->syncDefinitions();
        (new WorkspaceFinanceGateService(new WorkspaceSkillInstallService()))->saveInitialSetup((int) $seed['workspace_id'], [
            'currency' => 'USD',
            'opening_date' => date('Y-m-d'),
            'opening_cash' => '1000.00',
            'opening_receivables' => '250.00',
            'opening_payables' => '125.00',
            'opening_loan_balance' => '0.00',
            'opening_assets' => '500.00',
            'opening_equity' => '1625.00',
            'owner_equity' => [
                (int) $seed['user_id'] => [
                    'user_id' => (int) $seed['user_id'],
                    'ownership_percent' => '100',
                    'opening_owner_capital' => '1000.00',
                    'opening_owner_draws' => '0.00',
                ],
            ],
        ], (int) $seed['user_id']);

        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $cashAccounts = new FinanceCashAccountService();
        $cashAccountId = $cashAccounts->ensureDefaultAccount($workspaceId, $userId);

        $income = new FinanceIncomeService(null, $cashAccounts);
        $incomeCategories = $income->listCategories($workspaceId);
        $serviceIncomeId = $this->categoryIdByName($incomeCategories, 'Service income');
        $income->saveEntry($workspaceId, [
            'cash_account_id' => $cashAccountId,
            'income_category_id' => $serviceIncomeId,
            'income_type' => 'sale',
            'source' => 'Client A',
            'description' => 'Client A payment',
            'amount' => '2400.00',
            'currency' => 'USD',
            'income_date' => date('Y-m-d'),
        ], $userId);

        $expenses = new FinanceExpenseService(null, $cashAccounts);
        $expenseCategories = $expenses->listCategories($workspaceId);
        $operationsId = $this->categoryIdByName($expenseCategories, 'Operations');
        $profitShareId = $this->categoryIdByName($expenseCategories, 'Profit share');
        $expenses->saveExpense($workspaceId, [
            'cash_account_id' => $cashAccountId,
            'category_id' => $operationsId,
            'vendor' => 'Supplier A',
            'description' => 'Supplies',
            'amount' => '150.00',
            'currency' => 'USD',
            'expense_date' => date('Y-m-d'),
            'status' => 'paid',
        ], $userId);
        $expenses->saveExpense($workspaceId, [
            'cash_account_id' => $cashAccountId,
            'category_id' => $profitShareId,
            'vendor' => 'Owner',
            'description' => 'Profit share',
            'amount' => '75.00',
            'currency' => 'USD',
            'expense_date' => date('Y-m-d'),
            'status' => 'paid',
        ], $userId);

        (new FinanceLedgerService())->saveGuidedTransaction($workspaceId, [
            'transaction_type' => 'founder_capital',
            'transaction_date' => date('Y-m-d'),
            'cash_account_id' => $cashAccountId,
            'owner_user_id' => $userId,
            'counterparty' => 'Owner',
            'amount' => '300.00',
            'currency' => 'USD',
            'memo' => 'Extra owner money',
        ], $userId);

        return $seed;
    }

    private function completeOnboarding(int $workspaceId): void
    {
        Database::execute(
            "UPDATE workspace_onboarding_state
             SET status = 'completed',
                 completed_at = COALESCE(completed_at, NOW())
             WHERE workspace_id = ?",
            [$workspaceId]
        );
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function seedWorkspace(string $slugPrefix): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Finance Runtime ' . $suffix,
            'workspace_slug' => $slugPrefix . '-' . $suffix,
            'first_name' => 'Finance',
            'last_name' => 'Owner',
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $workspace = Database::queryOne("SELECT uuid, slug, name FROM workspaces WHERE id = ?", [$workspaceId]) ?? [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'membership_id' => (int) ($membership['id'] ?? 0),
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
        ];
    }

    /**
     * @param list<string> $permissionKeys
     */
    private function grantRolePermissions(string $roleSlug, array $permissionKeys): void
    {
        foreach ($permissionKeys as $permissionKey) {
            Database::execute(
                "INSERT INTO role_permissions (role_id, permission_id, can_access)
                 SELECT r.id, p.id, 1
                 FROM roles r
                 JOIN permissions p ON p.permission_key = ?
                 WHERE r.slug = ?
                 ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)",
                [$permissionKey, $roleSlug]
            );
        }
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function activateSession(array $seed, string $role): void
    {
        Session::set('user_id', (int) $seed['user_id']);
        Session::set('user_email', (string) $seed['email']);
        Session::set('__remember_restore_attempted', true);
        Session::set('active_workspace_id', (int) $seed['workspace_id']);
        Session::set('active_workspace_uuid', (string) $seed['workspace_uuid']);
        Session::set('active_workspace_slug', (string) $seed['workspace_slug']);
        Session::set('active_workspace_name', (string) $seed['workspace_name']);
        Session::set('active_workspace_role', $role);
        Session::set('active_workspace_membership_id', (int) $seed['membership_id']);
        WorkspaceContext::activateRuntimeWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], $role);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     * @return array<string,mixed>
     */
    private function webSession(array $seed, string $role): array
    {
        return [
            'user_id' => (int) $seed['user_id'],
            'user_uuid' => 'finance-runtime-user',
            'user_email' => (string) $seed['email'],
            'user_role' => 'admin',
            'active_workspace_id' => (int) $seed['workspace_id'],
            'active_workspace_uuid' => (string) $seed['workspace_uuid'],
            'active_workspace_slug' => (string) $seed['workspace_slug'],
            'active_workspace_name' => (string) $seed['workspace_name'],
            'active_workspace_role' => $role,
            'active_workspace_membership_id' => (int) $seed['membership_id'],
            'csrf_token' => 'csrf-finance-runtime',
            '__remember_restore_attempted' => true,
        ];
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     * @return array<string,mixed>
     */
    private function financeTab(array $seed, string $tab): array
    {
        return $this->runWebEndpoint('public/finance.php', $this->webSession($seed, 'owner'), [
            'method' => 'GET',
            'query' => [
                'tab' => $tab,
                'date_from' => date('Y-m-01'),
                'date_to' => date('Y-m-t'),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $response
     */
    private function assertResponseOk(array $response): void
    {
        $this->assertSame(200, (int) ($response['status'] ?? 0), json_encode([
            'headers' => $response['headers'] ?? [],
            'stderr' => $response['stderr'] ?? '',
            'body' => substr((string) ($response['body'] ?? ''), 0, 500),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @param list<array<string,mixed>> $categories
     */
    private function categoryIdByName(array $categories, string $name): int
    {
        foreach ($categories as $category) {
            if ((string) ($category['name'] ?? '') === $name) {
                return (int) ($category['id'] ?? 0);
            }
        }

        $this->fail('Missing finance category: ' . $name);
    }
}
