<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\BeginnerBudget;
use CRM\Modules\Currencies;

class FounderFinanceService
{
    private FinanceExpenseService $expenses;
    private FinanceInsightService $insights;
    private FinanceLedgerService $ledger;
    private FinanceStatementService $statements;
    private FinanceCashAccountService $cashAccounts;
    private FinanceIncomeService $income;
    private WorkspaceScopeService $workspaceScope;

    public function __construct(
        ?FinanceExpenseService $expenses = null,
        ?FinanceInsightService $insights = null,
        ?WorkspaceScopeService $workspaceScope = null,
        ?FinanceLedgerService $ledger = null,
        ?FinanceStatementService $statements = null,
        ?FinanceCashAccountService $cashAccounts = null,
        ?FinanceIncomeService $income = null
    ) {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
        $this->cashAccounts = $cashAccounts ?? new FinanceCashAccountService($this->workspaceScope);
        $this->income = $income ?? new FinanceIncomeService($this->workspaceScope, $this->cashAccounts);
        $this->expenses = $expenses ?? new FinanceExpenseService($this->workspaceScope, $this->cashAccounts);
        $this->insights = $insights ?? new FinanceInsightService($this->workspaceScope);
        $this->ledger = $ledger ?? new FinanceLedgerService($this->workspaceScope);
        $this->statements = $statements ?? new FinanceStatementService($this->workspaceScope, $this->insights, $this->ledger, $this->income, $this->cashAccounts);
    }

    public function dashboard(int $workspaceId, int $userId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        $dateFrom = $dateFrom ?: date('Y-m-01');
        $dateTo = $dateTo ?: date('Y-m-t');
        $statements = $this->statements->generate($workspaceId, $userId, $dateFrom, $dateTo);
        $ownerRoi = (array) ($statements['owner_roi'] ?? []);
        $financeGate = (new WorkspaceFinanceGateService())->status($workspaceId, null);

        $dashboard = [
            'summary' => $this->insights->summarize($workspaceId, $userId, $dateFrom, $dateTo),
            'categories' => $this->expenses->listCategories($workspaceId),
            'income_categories' => $this->income->listCategories($workspaceId),
            'cash_accounts' => $this->cashAccounts->listAccounts($workspaceId),
            'income_entries' => $this->income->listEntries($workspaceId, ['date_from' => $dateFrom, 'date_to' => $dateTo], 80, 0),
            'vendors' => $this->expenses->listVendors($workspaceId),
            'expenses' => $this->expenses->listExpenses($workspaceId, ['date_from' => $dateFrom, 'date_to' => $dateTo], 50, 0),
            'expense_by_vendor' => $this->expenses->expenseByVendor($workspaceId, $dateFrom, $dateTo),
            'recurring_expenses' => $this->expenses->listRecurringExpenses($workspaceId),
            'budget' => $this->getFounderBudget($workspaceId, $userId, $dateFrom, $dateTo),
            'statements' => $statements,
            'ledger_transactions' => $this->ledger->recentTransactions($workspaceId, 6),
            'ledger_funding_summary' => $this->ledger->fundingSummary($workspaceId, $dateFrom, $dateTo),
            'finance_gate' => $financeGate,
        ];
        if ($ownerRoi !== []) {
            $dashboard['owner_roi'] = $ownerRoi;
        }

        return $dashboard;
    }

    public function saveFounderBudget(int $workspaceId, int $userId, array $data): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $periodStart = $this->normalizeDate((string) ($data['period_start'] ?? date('Y-m-01')), date('Y-m-01'));
        $periodEnd = $this->normalizeDate((string) ($data['period_end'] ?? date('Y-m-t')), date('Y-m-t'));
        if ($periodStart > $periodEnd) {
            [$periodStart, $periodEnd] = [$periodEnd, $periodStart];
        }

        $currency = $this->sanitizeCurrency((string) ($data['currency'] ?? $this->defaultCurrencyCode()), $this->defaultCurrencyCode());
        $name = trim((string) ($data['name'] ?? 'Monthly founder budget'));
        $targetRevenue = $this->amount($data['target_revenue'] ?? 0);
        $cashReserve = $this->amount($data['target_cash_reserve'] ?? 0);

        Database::execute(
            "INSERT INTO finance_budgets (workspace_id, user_id, name, period_start, period_end, currency, target_revenue, target_cash_reserve, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                name = VALUES(name),
                currency = VALUES(currency),
                target_revenue = VALUES(target_revenue),
                target_cash_reserve = VALUES(target_cash_reserve),
                updated_at = NOW()",
            [$workspaceId, $userId > 0 ? $userId : null, mb_substr($name, 0, 160), $periodStart, $periodEnd, $currency, $targetRevenue, $cashReserve, $userId > 0 ? $userId : null]
        );

        $budget = Database::queryOne(
            "SELECT id FROM finance_budgets WHERE workspace_id = ? AND period_start = ? AND period_end = ? LIMIT 1",
            [$workspaceId, $periodStart, $periodEnd]
        );
        $budgetId = (int) ($budget['id'] ?? 0);

        Database::execute("DELETE FROM finance_budget_lines WHERE workspace_id = ? AND budget_id = ?", [$workspaceId, $budgetId]);
        foreach ((array) ($data['budget_lines'] ?? []) as $line) {
            $label = trim((string) ($line['label'] ?? ''));
            $planned = $this->amount($line['planned_amount'] ?? 0);
            if ($label === '' || $planned <= 0) {
                continue;
            }
            Database::execute(
                "INSERT INTO finance_budget_lines (budget_id, workspace_id, category_id, label, planned_amount)
                 VALUES (?, ?, ?, ?, ?)",
                [$budgetId, $workspaceId, !empty($line['category_id']) ? (int) $line['category_id'] : null, mb_substr($label, 0, 160), $planned]
            );
        }

        if ($userId > 0) {
            (new BeginnerBudget())->save($userId, [
                'monthly_marketing_budget' => $data['monthly_marketing_budget'] ?? 0,
                'monthly_fixed_costs' => $data['monthly_fixed_costs'] ?? 0,
                'target_deal_value' => $data['target_deal_value'] ?? 0,
                'target_cac' => $data['target_cac'] ?? null,
                'currency_code' => $currency,
            ]);
        }

        return $budgetId;
    }

    public function getFounderBudget(int $workspaceId, int $userId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $dateFrom = $this->normalizeDate($dateFrom ?: date('Y-m-01'), date('Y-m-01'));
        $dateTo = $this->normalizeDate($dateTo ?: date('Y-m-t'), date('Y-m-t'));
        $budget = Database::queryOne(
            "SELECT *
             FROM finance_budgets
             WHERE workspace_id = ?
               AND period_start <= ?
               AND period_end >= ?
             ORDER BY period_start DESC
             LIMIT 1",
            [$workspaceId, $dateTo, $dateFrom]
        ) ?: [];

        if ($budget) {
            $budget['lines'] = Database::query(
                "SELECT * FROM finance_budget_lines WHERE workspace_id = ? AND budget_id = ? ORDER BY label",
                [$workspaceId, (int) $budget['id']]
            );
        }

        $legacy = $userId > 0 ? ((new BeginnerBudget())->get($userId) ?: []) : [];
        $budget['legacy_beginner_budget'] = $legacy;
        return $budget;
    }

    public function aiContext(int $workspaceId, int $userId): array
    {
        $context = $this->insights->contextForAI($workspaceId, $userId);
        $context['finance_accounting_context'] = $this->statements->contextForAI($workspaceId, $userId);
        return $context;
    }

    public function accountingContext(int $workspaceId, int $userId): array
    {
        return $this->statements->contextForAI($workspaceId, $userId);
    }

    private function normalizeDate(string $value, string $fallback): string
    {
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : $fallback;
    }

    private function sanitizeCurrency(string $value, string $fallback = 'USD'): string
    {
        $value = strtoupper(trim($value));
        $fallback = strtoupper(trim($fallback)) ?: 'USD';
        return preg_match('/^[A-Z]{3,10}$/', $value) ? $value : $fallback;
    }

    private function amount(mixed $value): float
    {
        return round(max(0, min((float) $value, 999999999.99)), 2);
    }

    private function defaultCurrencyCode(): string
    {
        try {
            if (Database::tableExists('currencies')) {
                $default = (new Currencies())->getDefault();
                $code = strtoupper(trim((string) ($default['code'] ?? '')));
                if ($code !== '') {
                    return $code;
                }
            }
        } catch (\Throwable $e) {
            return 'USD';
        }

        return 'USD';
    }
}
