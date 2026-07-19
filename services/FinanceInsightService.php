<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\BeginnerBudget;
use CRM\Modules\Currencies;

class FinanceInsightService
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    public function summarize(int $workspaceId, int $userId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        $dateFrom = $this->normalizeDate($dateFrom ?: date('Y-m-01'), date('Y-m-01'));
        $dateTo = $this->normalizeDate($dateTo ?: date('Y-m-t'), date('Y-m-t'));
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $revenue = $this->paidRevenue($workspaceId, $dateFrom, $dateTo);
        $manualIncome = (new FinanceIncomeService($this->workspaceScope))->summary($workspaceId, $dateFrom, $dateTo);
        $expenses = $this->expenseSummary($workspaceId, $dateFrom, $dateTo);
        $budget = $this->budgetForPeriod($workspaceId, $dateFrom, $dateTo);
        $budgetVariance = $this->budgetVariance($workspaceId, $budget, $dateFrom, $dateTo);
        $beginnerBudget = $userId > 0 ? ((new BeginnerBudget())->get($userId) ?: []) : [];

        $invoiceRevenue = (float) ($revenue['paid_revenue'] ?? 0);
        $manualSales = (float) ($manualIncome['sales'] ?? 0);
        $manualOtherIncome = (float) ($manualIncome['other_income'] ?? 0);
        $moneyIn = round($invoiceRevenue + $manualSales + $manualOtherIncome, 2);
        $moneyOut = (float) ($expenses['paid_expenses'] ?? 0);
        $netCash = round($moneyIn - $moneyOut, 2);
        $burnRate = $this->averageMonthlyBurn($workspaceId, $dateTo);
        $cashReserve = (float) ($budget['target_cash_reserve'] ?? 0);
        $runwayMonths = $burnRate > 0 && $cashReserve > 0 ? round($cashReserve / $burnRate, 1) : null;
        $paidCustomerCount = max(0, (int) ($revenue['paid_customer_count'] ?? 0));
        $avgRevenuePerCustomer = $paidCustomerCount > 0 ? round($moneyIn / $paidCustomerCount, 2) : null;
        $marketingSpend = (float) ($expenses['marketing_expenses'] ?? 0);
        $cac = $paidCustomerCount > 0 && $marketingSpend > 0 ? round($marketingSpend / $paidCustomerCount, 2) : null;
        $paybackMonths = $cac !== null && $avgRevenuePerCustomer !== null && $avgRevenuePerCustomer > 0
            ? round($cac / $avgRevenuePerCustomer, 1)
            : null;
        $grossMarginPercent = $moneyIn > 0 ? round((($moneyIn - $moneyOut) / $moneyIn) * 100, 1) : null;
        $targetDealValue = (float) ($beginnerBudget['target_deal_value'] ?? 0);
        $breakEvenDeals = $targetDealValue > 0 && $moneyOut > 0 ? (int) ceil($moneyOut / $targetDealValue) : null;
        $avgPaidInvoice = (float) ($revenue['paid_invoice_count'] ?? 0) > 0
            ? round($invoiceRevenue / (int) $revenue['paid_invoice_count'], 2)
            : null;

        $defaultCurrencyCode = $this->defaultCurrencyCode();
        $financeBudgetCurrency = strtoupper(trim((string) ($budget['currency'] ?? '')));

        return [
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'currency' => $financeBudgetCurrency !== '' ? $financeBudgetCurrency : $defaultCurrencyCode,
            'money_in' => $moneyIn,
            'invoice_sales_income' => $invoiceRevenue,
            'manual_sales_income' => $manualSales,
            'manual_other_income' => $manualOtherIncome,
            'manual_income_count' => (int) ($manualIncome['count'] ?? 0),
            'money_out' => $moneyOut,
            'net_cash_movement' => $netCash,
            'paid_invoice_count' => (int) ($revenue['paid_invoice_count'] ?? 0),
            'paid_customer_count' => $paidCustomerCount,
            'pipeline_revenue' => (float) ($revenue['pipeline_revenue'] ?? 0),
            'burn_rate' => $burnRate,
            'runway_months' => $runwayMonths,
            'cash_reserve' => $cashReserve,
            'gross_margin_percent' => $grossMarginPercent,
            'contribution_margin' => $netCash,
            'avg_revenue_per_customer' => $avgRevenuePerCustomer,
            'avg_paid_invoice' => $avgPaidInvoice,
            'cac' => $cac,
            'payback_months' => $paybackMonths,
            'break_even_deals' => $breakEvenDeals,
            'target_deal_value' => $targetDealValue,
            'monthly_marketing_budget' => (float) ($beginnerBudget['monthly_marketing_budget'] ?? 0),
            'monthly_fixed_costs' => (float) ($beginnerBudget['monthly_fixed_costs'] ?? 0),
            'target_cac' => isset($beginnerBudget['target_cac']) && $beginnerBudget['target_cac'] !== null ? (float) $beginnerBudget['target_cac'] : null,
            'expense_by_category' => $this->expenseByCategory($workspaceId, $dateFrom, $dateTo),
            'budget' => $budget,
            'budget_variance' => $budgetVariance,
            'guidance_flags' => $this->guidanceFlags($runwayMonths, $cac, (float) ($beginnerBudget['target_cac'] ?? 0), $grossMarginPercent),
        ];
    }

    public function contextForAI(int $workspaceId, int $userId): array
    {
        $summary = $this->summarize($workspaceId, $userId, date('Y-m-01'), date('Y-m-t'));

        return [
            'period' => $summary['period'],
            'currency' => $summary['currency'] ?? 'USD',
            'money_in' => $summary['money_in'],
            'invoice_sales_income' => $summary['invoice_sales_income'] ?? 0,
            'manual_sales_income' => $summary['manual_sales_income'] ?? 0,
            'manual_other_income' => $summary['manual_other_income'] ?? 0,
            'manual_income_count' => (int) ($summary['manual_income_count'] ?? 0),
            'money_out' => $summary['money_out'],
            'net_cash_movement' => $summary['net_cash_movement'],
            'paid_invoice_count' => (int) ($summary['paid_invoice_count'] ?? 0),
            'paid_customer_count' => (int) ($summary['paid_customer_count'] ?? 0),
            'pipeline_revenue' => (float) ($summary['pipeline_revenue'] ?? 0),
            'burn_rate' => $summary['burn_rate'],
            'runway_months' => $summary['runway_months'],
            'cash_reserve' => $summary['cash_reserve'],
            'avg_revenue_per_customer' => $summary['avg_revenue_per_customer'] ?? null,
            'avg_paid_invoice' => $summary['avg_paid_invoice'] ?? null,
            'cac' => $summary['cac'],
            'target_cac' => $summary['target_cac'],
            'payback_months' => $summary['payback_months'] ?? null,
            'gross_margin_percent' => $summary['gross_margin_percent'],
            'break_even_deals' => $summary['break_even_deals'],
            'target_deal_value' => (float) ($summary['target_deal_value'] ?? 0),
            'monthly_marketing_budget' => (float) ($summary['monthly_marketing_budget'] ?? 0),
            'monthly_fixed_costs' => (float) ($summary['monthly_fixed_costs'] ?? 0),
            'budget_variance_total' => $summary['budget_variance']['total_variance'] ?? 0,
            'budget_variance' => (array) ($summary['budget_variance'] ?? []),
            'expense_by_category' => array_slice((array) ($summary['expense_by_category'] ?? []), 0, 8),
            'guidance_flags' => $summary['guidance_flags'],
        ];
    }

    private function paidRevenue(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        $row = Database::queryOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status IN ('paid','partially_paid') AND document_type = 'invoice' THEN IF(amount_paid > 0, amount_paid, grand_total) ELSE 0 END), 0) AS paid_revenue,
                COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN grand_total ELSE 0 END), 0) AS pipeline_revenue,
                COUNT(CASE WHEN status IN ('paid','partially_paid') AND document_type = 'invoice' THEN 1 END) AS paid_invoice_count,
                COUNT(DISTINCT CASE WHEN status IN ('paid','partially_paid') AND document_type = 'invoice' AND contact_id IS NOT NULL THEN contact_id END) AS paid_customer_count
             FROM invoices
             WHERE workspace_id = ?
               AND COALESCE(paid_at, updated_at, issue_date, created_at) >= ?
               AND COALESCE(paid_at, updated_at, issue_date, created_at) < DATE_ADD(?, INTERVAL 1 DAY)",
            [$workspaceId, $dateFrom, $dateTo]
        );

        return $row ?: [];
    }

    private function expenseSummary(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        $row = Database::queryOne(
            "SELECT
                COALESCE(SUM(CASE WHEN e.status = 'paid' THEN e.amount ELSE 0 END), 0) AS paid_expenses,
                COALESCE(SUM(CASE WHEN e.status = 'paid' AND c.category_type = 'marketing' THEN e.amount ELSE 0 END), 0) AS marketing_expenses
             FROM finance_expenses e
             LEFT JOIN finance_expense_categories c ON c.id = e.category_id AND c.workspace_id = e.workspace_id
             WHERE e.workspace_id = ?
               AND e.expense_date BETWEEN ? AND ?",
            [$workspaceId, $dateFrom, $dateTo]
        );

        return $row ?: [];
    }

    private function expenseByCategory(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        return Database::query(
            "SELECT
                COALESCE(c.name, 'Uncategorized') AS category_name,
                COALESCE(c.category_type, 'other') AS category_type,
                COALESCE(SUM(e.amount), 0) AS total_amount,
                COUNT(*) AS expense_count
             FROM finance_expenses e
             LEFT JOIN finance_expense_categories c ON c.id = e.category_id AND c.workspace_id = e.workspace_id
             WHERE e.workspace_id = ?
               AND e.status = 'paid'
               AND e.expense_date BETWEEN ? AND ?
             GROUP BY c.id, c.name, c.category_type
             ORDER BY total_amount DESC, category_name ASC",
            [$workspaceId, $dateFrom, $dateTo]
        );
    }

    private function budgetForPeriod(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        $budget = Database::queryOne(
            "SELECT *
             FROM finance_budgets
             WHERE workspace_id = ?
               AND period_start <= ?
               AND period_end >= ?
             ORDER BY period_start DESC
             LIMIT 1",
            [$workspaceId, $dateTo, $dateFrom]
        );

        if (!$budget) {
            return [];
        }

        $budget['lines'] = Database::query(
            "SELECT bl.*, c.name AS category_name, c.category_type
             FROM finance_budget_lines bl
             LEFT JOIN finance_expense_categories c ON c.id = bl.category_id AND c.workspace_id = bl.workspace_id
             WHERE bl.budget_id = ?
             ORDER BY bl.label ASC",
            [(int) $budget['id']]
        );

        return $budget;
    }

    private function budgetVariance(int $workspaceId, array $budget, string $dateFrom, string $dateTo): array
    {
        $lines = (array) ($budget['lines'] ?? []);
        if ($lines === []) {
            return ['lines' => [], 'planned_total' => 0.0, 'actual_total' => 0.0, 'total_variance' => 0.0];
        }

        $actualByCategory = [];
        foreach ($this->expenseByCategory($workspaceId, $dateFrom, $dateTo) as $row) {
            $actualByCategory[(string) ($row['category_name'] ?? '')] = (float) ($row['total_amount'] ?? 0);
        }

        $out = [];
        $plannedTotal = 0.0;
        $actualTotal = 0.0;
        foreach ($lines as $line) {
            $label = (string) ($line['category_name'] ?? $line['label'] ?? 'Budget line');
            $planned = (float) ($line['planned_amount'] ?? 0);
            $actual = (float) ($actualByCategory[$label] ?? 0);
            $plannedTotal += $planned;
            $actualTotal += $actual;
            $out[] = [
                'label' => $label,
                'planned_amount' => $planned,
                'actual_amount' => $actual,
                'variance' => round($planned - $actual, 2),
            ];
        }

        return [
            'lines' => $out,
            'planned_total' => round($plannedTotal, 2),
            'actual_total' => round($actualTotal, 2),
            'total_variance' => round($plannedTotal - $actualTotal, 2),
        ];
    }

    private function averageMonthlyBurn(int $workspaceId, string $dateTo): float
    {
        $end = new \DateTimeImmutable($dateTo);
        $start = $end->modify('first day of -2 months');
        $row = Database::queryOne(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM finance_expenses
             WHERE workspace_id = ?
               AND status = 'paid'
               AND expense_date BETWEEN ? AND ?",
            [$workspaceId, $start->format('Y-m-d'), $end->format('Y-m-d')]
        );

        return round(((float) ($row['total'] ?? 0)) / 3, 2);
    }

    private function guidanceFlags(?float $runwayMonths, ?float $cac, float $targetCac, ?float $grossMarginPercent): array
    {
        $flags = [];
        if ($runwayMonths !== null && $runwayMonths < 3) {
            $flags[] = 'runway_below_three_months';
        }
        if ($cac !== null && $targetCac > 0 && $cac > $targetCac) {
            $flags[] = 'cac_above_target';
        }
        if ($grossMarginPercent !== null && $grossMarginPercent < 20) {
            $flags[] = 'margin_pressure';
        }
        return $flags;
    }

    private function normalizeDate(string $value, string $fallback): string
    {
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : $fallback;
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
