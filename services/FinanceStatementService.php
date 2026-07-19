<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Currencies;

class FinanceStatementService
{
    private WorkspaceScopeService $workspaceScope;
    private FinanceInsightService $insights;
    private FinanceLedgerService $ledger;
    private FinanceIncomeService $income;
    private FinanceCashAccountService $cashAccounts;
    private FinanceOpeningSetupService $openingSetup;

    public function __construct(
        ?WorkspaceScopeService $workspaceScope = null,
        ?FinanceInsightService $insights = null,
        ?FinanceLedgerService $ledger = null,
        ?FinanceIncomeService $income = null,
        ?FinanceCashAccountService $cashAccounts = null,
        ?FinanceOpeningSetupService $openingSetup = null
    )
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
        $this->insights = $insights ?? new FinanceInsightService($this->workspaceScope);
        $this->ledger = $ledger ?? new FinanceLedgerService($this->workspaceScope);
        $this->cashAccounts = $cashAccounts ?? new FinanceCashAccountService($this->workspaceScope);
        $this->income = $income ?? new FinanceIncomeService($this->workspaceScope, $this->cashAccounts);
        $this->openingSetup = $openingSetup ?? new FinanceOpeningSetupService($this->workspaceScope, $this->cashAccounts, null, $this->ledger);
    }

    public function generate(int $workspaceId, int $userId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        $dateFrom = $this->normalizeDate($dateFrom ?: date('Y-m-01'), date('Y-m-01'));
        $dateTo = $this->normalizeDate($dateTo ?: date('Y-m-t'), date('Y-m-t'));
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $summary = $this->insights->summarize($workspaceId, $userId, $dateFrom, $dateTo);
        $ledgerData = $this->ledger->statementData($workspaceId, $dateFrom, $dateTo);
        $pnl = $this->profitAndLoss($workspaceId, $summary, $dateFrom, $dateTo, $ledgerData);
        $cashFlow = $this->cashFlow($workspaceId, $summary, $dateFrom, $dateTo, $ledgerData);
        $bankStatement = $this->bankStatement($workspaceId, $dateFrom, $dateTo);
        $workingBalanceSheet = $this->workingBalanceSheet($workspaceId, $summary, $dateFrom, $dateTo, $ledgerData, $pnl, $cashFlow);
        $budgetVariance = (array) ($summary['budget_variance'] ?? []);
        $quality = $this->reportQuality($workspaceId, $summary, $pnl, $workingBalanceSheet, $dateFrom, $dateTo, $ledgerData);
        $setupStatus = $this->setupStatus($workspaceId, $summary, $pnl, $cashFlow, $workingBalanceSheet, $quality, $ledgerData, $dateFrom, $dateTo);

        $currency = strtoupper(trim((string) ($summary['currency'] ?? ''))) ?: $this->defaultCurrencyCode();

        $result = [
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'currency' => $currency,
            'summary' => $summary,
            'profit_and_loss' => $pnl,
            'cash_flow' => $cashFlow,
            'bank_statement' => $bankStatement,
            'working_balance_sheet' => $workingBalanceSheet,
            'budget_variance' => $budgetVariance,
            'report_quality' => $quality,
            'setup_status' => $setupStatus,
            'ai_help_cards' => $this->aiHelpCards($summary, $pnl, $workingBalanceSheet, $quality),
        ];
        $ownerRoi = (new FinanceOwnerEquityService($this->workspaceScope))->personalRoi($workspaceId, $userId, $result);
        if ($ownerRoi !== null) {
            $result['owner_roi'] = $ownerRoi;
        }

        return $result;
    }

    public function generateBankStatement(int $workspaceId, ?string $dateFrom = null, ?string $dateTo = null, ?int $cashAccountId = null): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        $dateFrom = $this->normalizeDate($dateFrom ?: date('Y-m-01'), date('Y-m-01'));
        $dateTo = $this->normalizeDate($dateTo ?: date('Y-m-t'), date('Y-m-t'));
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return $this->bankStatement($workspaceId, $dateFrom, $dateTo, $cashAccountId);
    }

    public function contextForAI(int $workspaceId, int $userId): array
    {
        $statements = $this->generate($workspaceId, $userId, date('Y-m-01'), date('Y-m-t'));
        $quality = (array) ($statements['report_quality'] ?? []);

        return [
            'period' => $statements['period'],
            'statement_summaries' => [
                'pnl' => (array) ($statements['profit_and_loss']['summary'] ?? []),
                'cash_flow' => (array) ($statements['cash_flow']['summary'] ?? []),
                'bank_statement' => (array) ($statements['bank_statement']['summary'] ?? []),
                'working_balance_sheet' => (array) ($statements['working_balance_sheet']['summary'] ?? []),
            ],
            'uncategorized_expenses_count' => (int) ($quality['uncategorized_expenses_count'] ?? 0),
            'missing_category_mappings' => (array) ($quality['missing_category_mappings'] ?? []),
            'budget_variance' => (array) ($statements['budget_variance'] ?? []),
            'cash_runway_state' => [
                'cash_reserve' => $statements['summary']['cash_reserve'] ?? 0,
                'burn_rate' => $statements['summary']['burn_rate'] ?? 0,
                'runway_months' => $statements['summary']['runway_months'] ?? null,
            ],
            'receivables_payables_snapshot' => [
                'receivables' => $statements['working_balance_sheet']['receivables'] ?? 0,
                'planned_outflows' => $statements['working_balance_sheet']['planned_outflows'] ?? 0,
            ],
            'capital_funding_snapshot' => [
                'financing_cash_flow' => $statements['cash_flow']['summary']['financing_cash_flow'] ?? 0,
                'investing_cash_flow' => $statements['cash_flow']['summary']['investing_cash_flow'] ?? 0,
                'equity' => (array) ($statements['working_balance_sheet']['equity'] ?? []),
                'data_quality' => (array) ($statements['working_balance_sheet']['data_quality'] ?? []),
            ],
            'report_confidence_warnings' => (array) ($quality['warnings'] ?? []),
        ];
    }

    private function profitAndLoss(int $workspaceId, array $summary, string $dateFrom, string $dateTo, array $ledgerData): array
    {
        $invoiceSales = (float) ($summary['invoice_sales_income'] ?? $summary['money_in'] ?? 0);
        $manualSales = (float) ($summary['manual_sales_income'] ?? 0);
        $manualOtherIncome = (float) ($summary['manual_other_income'] ?? 0);
        $salesRevenue = round($invoiceSales + $manualSales, 2);
        $revenue = round($salesRevenue + $manualOtherIncome, 2);
        $expenseRows = $this->expensesByStatementGroup($workspaceId, $dateFrom, $dateTo);
        $nonOperatingItems = (array) ($ledgerData['non_operating_items'] ?? []);
        $groups = [
            'cost_of_sales' => 0.0,
            'operating_expense' => 0.0,
            'payroll' => 0.0,
            'tax' => 0.0,
            'asset' => 0.0,
            'liability' => 0.0,
            'equity' => 0.0,
            'uncategorized' => 0.0,
        ];

        foreach ($expenseRows as $row) {
            if ((string) ($row['profit_treatment'] ?? 'normal') === 'post_net_profit_share') {
                continue;
            }
            $group = (string) ($row['statement_group'] ?? 'operating_expense');
            if (!isset($groups[$group])) {
                $group = 'operating_expense';
            }
            $groups[$group] = round($groups[$group] + (float) ($row['total_amount'] ?? 0), 2);
        }

        $profitSharePayouts = round(array_sum(array_map(
            static fn (array $row): float => (string) ($row['profit_treatment'] ?? 'normal') === 'post_net_profit_share'
                ? (float) ($row['total_amount'] ?? 0)
                : 0.0,
            $expenseRows
        )), 2);
        $costOfSales = $groups['cost_of_sales'];
        $operatingExpenses = round($groups['operating_expense'] + $groups['payroll'] + $groups['tax'] + $groups['uncategorized'], 2);
        $grossProfit = round($salesRevenue - $costOfSales, 2);
        $operatingIncome = round($grossProfit - $operatingExpenses, 2);
        $nonOperatingIncome = 0.0;
        $nonOperatingExpense = 0.0;
        foreach ($nonOperatingItems as $item) {
            if (($item['account_type'] ?? '') === 'revenue') {
                $nonOperatingIncome += (float) ($item['amount'] ?? 0);
            } elseif (($item['account_type'] ?? '') === 'expense') {
                $nonOperatingExpense += (float) ($item['amount'] ?? 0);
            }
        }
        $netIncome = round($operatingIncome + $manualOtherIncome + $nonOperatingIncome - $nonOperatingExpense, 2);
        $netAfterProfitShare = round($netIncome - $profitSharePayouts, 2);
        $grossMargin = $salesRevenue > 0 ? round(($grossProfit / $salesRevenue) * 100, 1) : null;
        $netMargin = $revenue > 0 ? round(($netIncome / $revenue) * 100, 1) : null;

        return [
            'summary' => [
                'revenue' => $revenue,
                'sales_revenue' => $salesRevenue,
                'invoice_sales' => round($invoiceSales, 2),
                'manual_sales' => round($manualSales, 2),
                'other_income' => round($manualOtherIncome + $nonOperatingIncome, 2),
                'cost_of_sales' => $costOfSales,
                'gross_profit' => $grossProfit,
                'operating_expenses' => $operatingExpenses,
                'operating_income' => $operatingIncome,
                'non_operating_income' => round($nonOperatingIncome, 2),
                'manual_other_income' => round($manualOtherIncome, 2),
                'non_operating_expense' => round($nonOperatingExpense, 2),
                'net_income' => $netIncome,
                'profit_share_payouts' => $profitSharePayouts,
                'net_after_profit_share' => $netAfterProfitShare,
                'gross_margin_percent' => $grossMargin,
                'net_margin_percent' => $netMargin,
            ],
            'expense_groups' => $groups,
            'expense_lines' => $expenseRows,
            'non_operating_items' => $nonOperatingItems,
            'drilldowns' => $this->profitAndLossDrilldowns($workspaceId, $dateFrom, $dateTo, $expenseRows, $nonOperatingItems),
        ];
    }

    private function cashFlow(int $workspaceId, array $summary, string $dateFrom, string $dateTo, array $ledgerData): array
    {
        $operatingCashIn = (float) ($summary['money_in'] ?? 0);
        $rows = Database::query(
            "SELECT
                COALESCE(c.cash_flow_group, 'operating') AS cash_flow_group,
                COALESCE(SUM(CASE WHEN e.status = 'paid' THEN e.amount ELSE 0 END), 0) AS total_amount
             FROM finance_expenses e
             LEFT JOIN finance_expense_categories c ON c.id = e.category_id AND c.workspace_id = e.workspace_id
             WHERE e.workspace_id = ?
               AND e.expense_date BETWEEN ? AND ?
             GROUP BY COALESCE(c.cash_flow_group, 'operating')
             ORDER BY total_amount DESC",
            [$workspaceId, $dateFrom, $dateTo]
        );

        $groups = ['operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0];
        foreach ($rows as $row) {
            $group = (string) ($row['cash_flow_group'] ?? 'operating');
            if (!isset($groups[$group])) {
                $group = 'operating';
            }
            $groups[$group] = round((float) ($row['total_amount'] ?? 0), 2);
        }
        $ledgerCashFlow = (array) ($ledgerData['cash_flow'] ?? []);
        $operatingCashOut = (float) ($groups['operating'] ?? 0);
        $investingInflows = (float) ($ledgerCashFlow['investing_inflows'] ?? 0);
        $investingOutflows = round((float) ($ledgerCashFlow['investing_outflows'] ?? 0) + (float) ($groups['investing'] ?? 0), 2);
        $financingInflows = (float) ($ledgerCashFlow['financing_inflows'] ?? 0);
        $financingOutflows = round((float) ($ledgerCashFlow['financing_outflows'] ?? 0) + (float) ($groups['financing'] ?? 0), 2);
        $operatingNet = round($operatingCashIn - $operatingCashOut, 2);
        $investingCashFlow = round($investingInflows - $investingOutflows, 2);
        $financingCashFlow = round($financingInflows - $financingOutflows, 2);
        $cashIn = round($operatingCashIn + $investingInflows + $financingInflows, 2);
        $cashOut = round($operatingCashOut + $investingOutflows + $financingOutflows, 2);
        $netCashMovement = round($operatingNet + $investingCashFlow + $financingCashFlow, 2);
        $openingCash = (float) ($ledgerData['opening_cash'] ?? 0);

        return [
            'summary' => [
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'operating_cash_flow' => $operatingNet,
                'investing_cash_flow' => round($investingCashFlow, 2),
                'financing_cash_flow' => round($financingCashFlow, 2),
                'net_cash_movement' => $netCashMovement,
                'opening_cash' => $openingCash > 0 ? $openingCash : null,
                'ending_cash' => !empty($ledgerData['has_opening_balance']) ? round($openingCash + $netCashMovement, 2) : null,
            ],
            'cash_out_by_group' => $groups,
            'sections' => [
                'operating' => [
                    'cash_in' => round($operatingCashIn, 2),
                    'cash_out' => round($operatingCashOut, 2),
                    'net' => $operatingNet,
                ],
                'investing' => [
                    'cash_in' => round($investingInflows, 2),
                    'cash_out' => round($investingOutflows, 2),
                    'net' => round($investingCashFlow, 2),
                ],
                'financing' => [
                    'cash_in' => round($financingInflows, 2),
                    'cash_out' => round($financingOutflows, 2),
                    'net' => round($financingCashFlow, 2),
                ],
            ],
            'drilldowns' => $this->cashFlowDrilldowns($workspaceId, $dateFrom, $dateTo),
        ];
    }

    private function bankStatement(int $workspaceId, string $dateFrom, string $dateTo, ?int $cashAccountId = null): array
    {
        $accounts = $this->cashAccounts->listAccounts($workspaceId);
        $defaultAccountId = $this->cashAccounts->defaultAccountId($workspaceId);
        $selectedAccountId = $cashAccountId !== null && $cashAccountId > 0
            ? $this->cashAccounts->resolveCashAccountId($workspaceId, $cashAccountId)
            : null;
        $beforeStart = (new \DateTimeImmutable($dateFrom))->modify('-1 day')->format('Y-m-d');
        $openingRows = $beforeStart >= '1970-01-01'
            ? $this->bankActivityRows($workspaceId, '1970-01-01', $beforeStart, $selectedAccountId, $defaultAccountId)
            : [];
        $periodRows = $this->bankActivityRows($workspaceId, $dateFrom, $dateTo, $selectedAccountId, $defaultAccountId);

        $openingBalance = 0.0;
        foreach ($openingRows as $row) {
            $openingBalance += (float) ($row['money_in'] ?? 0);
            $openingBalance -= (float) ($row['money_out'] ?? 0);
        }

        $balance = round($openingBalance, 2);
        $cashIn = 0.0;
        $cashOut = 0.0;
        foreach ($periodRows as &$row) {
            $cashIn += (float) ($row['money_in'] ?? 0);
            $cashOut += (float) ($row['money_out'] ?? 0);
            $balance = round($balance + (float) ($row['money_in'] ?? 0) - (float) ($row['money_out'] ?? 0), 2);
            $row['balance'] = $balance;
        }
        unset($row);

        return [
            'summary' => [
                'opening_balance' => round($openingBalance, 2),
                'cash_in' => round($cashIn, 2),
                'cash_out' => round($cashOut, 2),
                'ending_balance' => $balance,
                'cash_account_id' => $selectedAccountId,
            ],
            'accounts' => $accounts,
            'rows' => $periodRows,
        ];
    }

    private function bankActivityRows(int $workspaceId, string $dateFrom, string $dateTo, ?int $cashAccountId, int $defaultAccountId): array
    {
        if ($dateFrom > $dateTo) {
            return [];
        }
        $accounts = [];
        foreach ($this->cashAccounts->listAccounts($workspaceId, true) as $account) {
            $accounts[(int) ($account['id'] ?? 0)] = (string) ($account['name'] ?? 'Account');
        }
        $defaultAccountName = (string) ($accounts[$defaultAccountId] ?? 'Main Bank');
        $rows = [];
        $hasDetailedOpeningBanks = $this->openingSetup->hasCompletedDetailedBankSetup($workspaceId);

        if ($hasDetailedOpeningBanks) {
            foreach ($this->openingSetup->openingBankRowsForStatement($workspaceId, $cashAccountId) as $openingBank) {
                $date = (string) ($openingBank['opening_date'] ?? $dateFrom);
                $amount = round((float) ($openingBank['opening_balance'] ?? 0), 2);
                if ($amount <= 0 || $date < $dateFrom || $date > $dateTo) {
                    continue;
                }
                $accountId = (int) ($openingBank['cash_account_id'] ?? $defaultAccountId);
                $rows[] = [
                    'date' => $date,
                    'ref' => 'OPEN-' . (string) $accountId,
                    'source' => 'opening',
                    'type' => 'Opening',
                    'cash_account_id' => $accountId,
                    'cash_account_name' => (string) ($openingBank['cash_account_name'] ?? $accounts[$accountId] ?? $defaultAccountName),
                    'description' => 'Opening balance',
                    'currency' => (string) ($openingBank['currency'] ?? $this->defaultCurrencyCode()),
                    'money_in' => $amount,
                    'money_out' => 0.0,
                ];
            }
        }

        if (Database::tableExists('invoices') && ($cashAccountId === null || $cashAccountId === $defaultAccountId)) {
            foreach (Database::query(
                "SELECT id, invoice_number, title, status, currency,
                    IF(amount_paid > 0, amount_paid, grand_total) AS amount,
                    COALESCE(paid_at, updated_at, issue_date, created_at) AS activity_date
                 FROM invoices
                 WHERE workspace_id = ?
                   AND status IN ('paid','partially_paid')
                   AND document_type = 'invoice'
                   AND COALESCE(paid_at, updated_at, issue_date, created_at) >= ?
                   AND COALESCE(paid_at, updated_at, issue_date, created_at) < DATE_ADD(?, INTERVAL 1 DAY)",
                [$workspaceId, $dateFrom, $dateTo]
            ) as $invoice) {
                $amount = round((float) ($invoice['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    continue;
                }
                $rows[] = [
                    'date' => substr((string) ($invoice['activity_date'] ?? $dateFrom), 0, 10),
                    'ref' => (string) ($invoice['invoice_number'] ?? ('INV-' . ($invoice['id'] ?? ''))),
                    'source' => 'invoice',
                    'type' => 'Sale',
                    'cash_account_id' => $defaultAccountId,
                    'cash_account_name' => $defaultAccountName,
                    'description' => (string) ($invoice['title'] ?? 'Invoice'),
                    'currency' => (string) ($invoice['currency'] ?? $this->defaultCurrencyCode()),
                    'money_in' => $amount,
                    'money_out' => 0.0,
                ];
            }
        }

        foreach ($this->income->sourceRows($workspaceId, $dateFrom, $dateTo, $cashAccountId) as $income) {
            $amount = round((float) ($income['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $accountId = (int) ($income['cash_account_id'] ?? $defaultAccountId);
            $rows[] = [
                'date' => (string) ($income['income_date'] ?? $dateFrom),
                'ref' => 'IN-' . (string) ($income['id'] ?? ''),
                'source' => 'income',
                'type' => (string) ($income['income_type'] ?? 'sale'),
                'cash_account_id' => $accountId,
                'cash_account_name' => (string) ($income['cash_account_name'] ?? $accounts[$accountId] ?? $defaultAccountName),
                'description' => (string) ($income['description'] ?? 'Income'),
                'currency' => (string) ($income['currency'] ?? $this->defaultCurrencyCode()),
                'money_in' => $amount,
                'money_out' => 0.0,
            ];
        }

        $expenseWhere = ['e.workspace_id = ?', "e.status = 'paid'", 'e.expense_date BETWEEN ? AND ?'];
        $expenseParams = [$workspaceId, $dateFrom, $dateTo];
        if ($cashAccountId !== null) {
            $expenseWhere[] = 'e.cash_account_id = ?';
            $expenseParams[] = $cashAccountId;
        }
        foreach (Database::query(
            "SELECT e.*, COALESCE(v.name, e.vendor) AS vendor_name, a.name AS cash_account_name
             FROM finance_expenses e
             LEFT JOIN finance_vendors v ON v.id = e.vendor_id AND v.workspace_id = e.workspace_id
             LEFT JOIN finance_cash_accounts a ON a.id = e.cash_account_id AND a.workspace_id = e.workspace_id
             WHERE " . implode(' AND ', $expenseWhere),
            $expenseParams
        ) as $expense) {
            $amount = round((float) ($expense['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $accountId = (int) ($expense['cash_account_id'] ?? $defaultAccountId);
            $rows[] = [
                'date' => (string) ($expense['expense_date'] ?? $dateFrom),
                'ref' => 'OUT-' . (string) ($expense['id'] ?? ''),
                'source' => 'expense',
                'type' => 'Expense',
                'cash_account_id' => $accountId,
                'cash_account_name' => (string) ($expense['cash_account_name'] ?? $accounts[$accountId] ?? $defaultAccountName),
                'description' => (string) ($expense['description'] ?? 'Expense'),
                'counterparty' => (string) ($expense['vendor_name'] ?? ''),
                'currency' => (string) ($expense['currency'] ?? $this->defaultCurrencyCode()),
                'money_in' => 0.0,
                'money_out' => $amount,
            ];
        }

        $ledgerWhere = ['workspace_id = ?', 'transaction_date BETWEEN ? AND ?'];
        $ledgerParams = [$workspaceId, $dateFrom, $dateTo];
        if ($cashAccountId !== null) {
            $ledgerWhere[] = 'cash_account_id = ?';
            $ledgerParams[] = $cashAccountId;
        }
        foreach (Database::query(
            "SELECT *
             FROM finance_transactions
             WHERE " . implode(' AND ', $ledgerWhere),
            $ledgerParams
        ) as $entry) {
            $type = (string) ($entry['transaction_type'] ?? '');
            $amount = round((float) ($entry['amount'] ?? 0), 2);
            if ($type === 'opening_balance' && $hasDetailedOpeningBanks) {
                continue;
            }
            if ($amount <= 0 && $type !== 'opening_balance') {
                continue;
            }
            $moneyInTypes = ['opening_balance', 'founder_capital', 'equity_funding', 'loan_received', 'manual_adjustment'];
            $moneyOutTypes = ['loan_repayment', 'owner_draw', 'asset_purchase'];
            if (!in_array($type, $moneyInTypes, true) && !in_array($type, $moneyOutTypes, true)) {
                continue;
            }
            $accountId = (int) ($entry['cash_account_id'] ?? $defaultAccountId);
            $rows[] = [
                'date' => (string) ($entry['transaction_date'] ?? $dateFrom),
                'ref' => 'CAP-' . (string) ($entry['id'] ?? ''),
                'source' => 'capital',
                'type' => $type,
                'cash_account_id' => $accountId,
                'cash_account_name' => (string) ($accounts[$accountId] ?? $defaultAccountName),
                'description' => (string) ($entry['memo'] ?? $entry['counterparty'] ?? ucwords(str_replace('_', ' ', $type))),
                'counterparty' => (string) ($entry['counterparty'] ?? ''),
                'currency' => (string) ($entry['currency'] ?? $this->defaultCurrencyCode()),
                'money_in' => in_array($type, $moneyInTypes, true) ? $amount : 0.0,
                'money_out' => in_array($type, $moneyOutTypes, true) ? $amount : 0.0,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''))
                ?: strcmp((string) ($a['source'] ?? ''), (string) ($b['source'] ?? ''))
                ?: strcmp((string) ($a['ref'] ?? ''), (string) ($b['ref'] ?? ''));
        });

        return $rows;
    }

    private function workingBalanceSheet(int $workspaceId, array $summary, string $dateFrom, string $dateTo, array $ledgerData, array $pnl, array $cashFlow): array
    {
        $cashReserveFallback = (float) ($summary['cash_reserve'] ?? 0);
        $cash = ($cashFlow['summary']['ending_cash'] ?? null) !== null
            ? (float) $cashFlow['summary']['ending_cash']
            : $cashReserveFallback;
        $receivables = $this->unpaidInvoiceReceivables($workspaceId, $dateTo);
        $plannedOutflows = $this->plannedOutflows($workspaceId, $dateFrom, $dateTo);
        $balances = (array) ($ledgerData['balances'] ?? []);
        $fixedAssets = max(0.0, (float) ($balances['1200']['balance'] ?? 0));
        $otherAssets = max(0.0, (float) ($balances['1300']['balance'] ?? 0));
        $loanPayable = max(0.0, (float) ($balances['2100']['balance'] ?? 0));
        $accountsPayable = max(0.0, (float) ($balances['2000']['balance'] ?? 0));
        $taxPayable = max(0.0, (float) ($balances['2200']['balance'] ?? 0));
        $shareCapital = max(0.0, (float) ($balances['3100']['balance'] ?? 0) + (float) ($balances['3200']['balance'] ?? 0));
        $ownerCapital = max(0.0, (float) ($balances['3000']['balance'] ?? 0));
        $openingRetainedEarnings = (float) ($balances['3300']['balance'] ?? 0);
        $ownerDraws = max(0.0, (float) ($balances['3400']['balance'] ?? 0));
        $currentNetIncome = (float) ($pnl['summary']['net_income'] ?? 0);
        $retainedEarnings = round($openingRetainedEarnings + $currentNetIncome, 2);
        $realEquity = round($shareCapital + $ownerCapital + $retainedEarnings - $ownerDraws, 2);
        $assets = round($cash + $receivables + $fixedAssets + $otherAssets, 2);
        $liabilities = round($plannedOutflows + $loanPayable + $accountsPayable + $taxPayable, 2);
        $ownerEquity = !empty($ledgerData['has_ledger_activity']) ? $realEquity : round($assets - $liabilities, 2);
        $balanceDifference = round($assets - ($liabilities + $ownerEquity), 2);

        return [
            'label' => 'Working balance sheet',
            'cash_reserve' => $cashReserveFallback,
            'cash' => round($cash, 2),
            'receivables' => $receivables,
            'planned_outflows' => $plannedOutflows,
            'owner_equity' => $ownerEquity,
            'summary' => [
                'assets' => $assets,
                'liabilities' => $liabilities,
                'owner_equity' => $ownerEquity,
                'balanced_total' => round($liabilities + $ownerEquity, 2),
                'balance_difference' => $balanceDifference,
            ],
            'accounts' => [
                'cash' => round($cash, 2),
                'receivables' => $receivables,
                'fixed_assets' => round($fixedAssets, 2),
                'other_assets' => round($otherAssets, 2),
                'planned_outflows' => $plannedOutflows,
                'accounts_payable' => round($accountsPayable, 2),
                'loan_payable' => round($loanPayable, 2),
                'tax_payable' => round($taxPayable, 2),
            ],
            'equity' => [
                'share_capital' => round($shareCapital, 2),
                'owner_capital' => round($ownerCapital, 2),
                'retained_earnings' => $retainedEarnings,
                'current_net_income' => round($currentNetIncome, 2),
                'owner_draws' => round($ownerDraws, 2),
                'total_equity' => $ownerEquity,
                'uses_balancing_fallback' => empty($ledgerData['has_ledger_activity']),
            ],
            'data_quality' => [
                'has_opening_balance' => !empty($ledgerData['has_opening_balance']),
                'has_capital_or_funding' => !empty($ledgerData['has_capital_or_funding']),
                'uses_cash_reserve_fallback' => empty($ledgerData['has_opening_balance']),
                'balance_difference' => $balanceDifference,
                'warnings' => (array) ($ledgerData['warnings'] ?? []),
            ],
            'confidence_note' => !empty($ledgerData['has_opening_balance'])
                ? 'Working statement from invoices, expenses, recurring outflows, and capital/funding entries.'
                : 'Working statement is using budget cash reserve until an opening balance is recorded.',
            'drilldowns' => $this->balanceSheetDrilldowns($workspaceId, $dateTo, $ledgerData, $pnl),
        ];
    }

    private function reportQuality(int $workspaceId, array $summary, array $pnl, array $balanceSheet, string $dateFrom, string $dateTo, array $ledgerData): array
    {
        $uncategorizedCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM finance_expenses
             WHERE workspace_id = ?
               AND (category_id IS NULL OR category_id = 0)
               AND expense_date BETWEEN ? AND ?",
            [$workspaceId, $dateFrom, $dateTo]
        )['c'] ?? 0);

        $missingMappings = [];
        $mappingRows = Database::query(
            "SELECT name
             FROM finance_expense_categories
             WHERE workspace_id = ?
               AND (statement_group IS NULL OR statement_group = '' OR cash_flow_group IS NULL OR cash_flow_group = '')
             ORDER BY name ASC
             LIMIT 20",
            [$workspaceId]
        );
        foreach ($mappingRows as $row) {
            $missingMappings[] = (string) ($row['name'] ?? 'Category');
        }

        $warnings = [];
        if ((float) ($summary['money_in'] ?? 0) <= 0) {
            $warnings[] = 'no_paid_invoice_revenue';
        }
        if ($uncategorizedCount > 0) {
            $warnings[] = 'uncategorized_expenses';
        }
        if ($missingMappings !== []) {
            $warnings[] = 'missing_category_accounting_mappings';
        }
        if (($summary['runway_months'] ?? null) !== null && (float) $summary['runway_months'] < 3) {
            $warnings[] = 'runway_below_three_months';
        }
        if ((float) ($balanceSheet['receivables'] ?? 0) > (float) ($summary['money_in'] ?? 0) && (float) ($summary['money_in'] ?? 0) <= 0) {
            $warnings[] = 'receivables_without_cash_collection';
        }
        if (($pnl['summary']['net_margin_percent'] ?? null) !== null && (float) $pnl['summary']['net_margin_percent'] < 15) {
            $warnings[] = 'low_net_margin';
        }
        if (empty($ledgerData['has_opening_balance'])) {
            $warnings[] = 'missing_opening_cash';
        }
        if (empty($ledgerData['has_capital_or_funding'])) {
            $warnings[] = 'no_capital_or_funding_history';
        }
        if (!empty($balanceSheet['data_quality']['uses_cash_reserve_fallback'])) {
            $warnings[] = 'balance_sheet_using_budget_reserve';
        }
        if (count($this->mixedCurrencies($workspaceId, $dateFrom, $dateTo)) > 1) {
            $warnings[] = 'mixed_currency_without_fx';
        }
        foreach ((array) ($ledgerData['warnings'] ?? []) as $warning) {
            $warnings[] = (string) $warning;
        }
        $warnings = array_values(array_unique($warnings));

        return [
            'warnings' => $warnings,
            'uncategorized_expenses_count' => $uncategorizedCount,
            'missing_category_mappings' => $missingMappings,
            'confidence' => $warnings === [] ? 'high' : (count($warnings) <= 2 ? 'medium' : 'needs_cleanup'),
        ];
    }

    private function setupStatus(int $workspaceId, array $summary, array $pnl, array $cashFlow, array $balanceSheet, array $quality, array $ledgerData, string $dateFrom, string $dateTo): array
    {
        $warnings = (array) ($quality['warnings'] ?? []);
        $counts = (array) ($ledgerData['transaction_counts'] ?? []);
        $loanBalance = (float) ($balanceSheet['accounts']['loan_payable'] ?? 0);
        $assetBalance = (float) (($balanceSheet['accounts']['fixed_assets'] ?? 0) + ($balanceSheet['accounts']['other_assets'] ?? 0));
        $balanceDifference = abs((float) ($balanceSheet['summary']['balance_difference'] ?? 0));
        $vendorCount = $this->vendorCount($workspaceId);
        $missingMappings = (array) ($quality['missing_category_mappings'] ?? []);
        $uncategorized = (int) ($quality['uncategorized_expenses_count'] ?? 0);

        return [
            'opening_balances' => $this->statusItem(
                'opening_balances',
                'Opening balances',
                !empty($ledgerData['has_opening_balance']) ? 'done' : 'missing',
                !empty($ledgerData['has_opening_balance']) ? 'Opening set' : 'Opening missing',
                'balance_sheet',
                ['amount' => (float) ($cashFlow['summary']['opening_cash'] ?? 0)]
            ),
            'capital_funding' => $this->statusItem(
                'capital_funding',
                'Capital/funding',
                !empty($ledgerData['has_capital_or_funding']) ? 'done' : 'missing',
                !empty($ledgerData['has_capital_or_funding']) ? 'Funding recorded' : 'Funding missing',
                'balance_sheet',
                ['count' => (int) (($counts['founder_capital'] ?? 0) + ($counts['equity_funding'] ?? 0) + ($counts['loan_received'] ?? 0))]
            ),
            'loans' => $this->statusItem(
                'loans',
                'Loans',
                in_array('loan_repayments_without_loan_balance', $warnings, true) ? 'review' : ($loanBalance > 0 || ($counts['loan_received'] ?? 0) > 0 || ($counts['loan_repayment'] ?? 0) > 0 ? 'done' : 'none'),
                in_array('loan_repayments_without_loan_balance', $warnings, true) ? 'Loan review' : ($loanBalance > 0 ? 'Loan tracked' : 'No loans'),
                'balance_sheet',
                ['amount' => $loanBalance, 'warning' => in_array('loan_repayments_without_loan_balance', $warnings, true) ? 'loan_repayments_without_loan_balance' : null]
            ),
            'assets' => $this->statusItem(
                'assets',
                'Assets',
                in_array('asset_purchase_without_asset_balance', $warnings, true) ? 'review' : ($assetBalance > 0 || ($counts['asset_purchase'] ?? 0) > 0 ? 'done' : 'none'),
                in_array('asset_purchase_without_asset_balance', $warnings, true) ? 'Asset review' : ($assetBalance > 0 ? 'Assets tracked' : 'No assets'),
                'balance_sheet',
                ['amount' => $assetBalance, 'warning' => in_array('asset_purchase_without_asset_balance', $warnings, true) ? 'asset_purchase_without_asset_balance' : null]
            ),
            'category_mappings' => $this->statusItem(
                'category_mappings',
                'Category mappings',
                ($missingMappings !== [] || $uncategorized > 0) ? 'review' : 'done',
                ($missingMappings !== [] || $uncategorized > 0) ? 'Mapping needed' : 'Mappings done',
                'reports',
                ['count' => count($missingMappings) + $uncategorized]
            ),
            'vendors' => $this->statusItem(
                'vendors',
                'Vendors',
                $vendorCount > 0 ? 'done' : 'missing',
                $vendorCount > 0 ? 'Vendors tracked' : 'Vendors missing',
                'expenses',
                ['count' => $vendorCount]
            ),
            'statement_balance' => $this->statusItem(
                'statement_balance',
                'Statement balance',
                $balanceDifference > 0.01 ? 'review' : 'balanced',
                $balanceDifference > 0.01 ? 'Balance review' : 'Balanced',
                'balance_sheet',
                ['amount' => (float) ($balanceSheet['summary']['balance_difference'] ?? 0)]
            ),
            'mixed_currency' => $this->statusItem(
                'mixed_currency',
                'Currency',
                in_array('mixed_currency_without_fx', $warnings, true) ? 'review' : 'done',
                in_array('mixed_currency_without_fx', $warnings, true) ? 'Mixed currency' : 'Currency clear',
                'reports',
                ['count' => count($this->mixedCurrencies($workspaceId, $dateFrom, $dateTo))]
            ),
        ];
    }

    private function statusItem(string $key, string $label, string $state, string $message, string $targetTab, array $extra = []): array
    {
        return array_filter(array_merge([
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'short_message' => $message,
            'target_tab' => $targetTab,
        ], $extra), static fn ($value) => $value !== null);
    }

    private function aiHelpCards(array $summary, array $pnl, array $balanceSheet, array $quality): array
    {
        $cards = [];
        $runway = $summary['runway_months'] ?? null;
        if ($runway !== null && (float) $runway < 3) {
            $cards[] = [
                'issue' => 'Runway risk',
                'why' => 'Reserve covers less than three months at current burn.',
                'action' => 'Reduce planned outflows or prioritize paid invoices this week.',
            ];
        }
        if ((int) ($quality['uncategorized_expenses_count'] ?? 0) > 0) {
            $cards[] = [
                'issue' => 'Uncategorized costs',
                'why' => 'P&L and cash-flow groups become less reliable.',
                'action' => 'Map uncategorized expenses to an accounting group.',
            ];
        }
        if (($pnl['summary']['net_margin_percent'] ?? null) !== null && (float) $pnl['summary']['net_margin_percent'] < 15) {
            $cards[] = [
                'issue' => 'Margin pressure',
                'why' => 'Net income is thin after recorded expenses.',
                'action' => 'Review delivery cost, payroll, and pricing before adding spend.',
            ];
        }
        if ((float) ($balanceSheet['receivables'] ?? 0) > 0 && (float) ($summary['money_in'] ?? 0) <= 0) {
            $cards[] = [
                'issue' => 'Cash not collected',
                'why' => 'Unpaid invoices are receivables, not cash.',
                'action' => 'Follow up unpaid invoices before treating revenue as available.',
            ];
        }
        if (!empty($quality['warnings']) && in_array('missing_opening_cash', (array) $quality['warnings'], true)) {
            $cards[] = [
                'issue' => 'Opening cash missing',
                'why' => 'Balance Sheet is using a working cash fallback.',
                'action' => 'Add an opening balance in Capital & Funding.',
            ];
        }
        if (!empty($quality['warnings']) && in_array('no_capital_or_funding_history', (array) $quality['warnings'], true)) {
            $cards[] = [
                'issue' => 'Funding history missing',
                'why' => 'Capital, loans, and owner equity are not fully represented yet.',
                'action' => 'Record founder capital, equity funding, or loan activity when it exists.',
            ];
        }
        if ($cards === []) {
            $cards[] = [
                'issue' => 'Report quality',
                'why' => 'Core finance data is mapped enough for useful guidance.',
                'action' => 'Keep invoices, expenses, budget, and recurring costs current.',
            ];
        }
        return array_slice($cards, 0, 5);
    }

    private function expensesByStatementGroup(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        return Database::query(
            "SELECT
                COALESCE(c.name, 'Uncategorized') AS category_name,
                COALESCE(c.category_type, 'other') AS category_type,
                CASE
                    WHEN e.category_id IS NULL THEN 'uncategorized'
                    ELSE COALESCE(c.statement_group, 'operating_expense')
                END AS statement_group,
                COALESCE(c.cash_flow_group, 'operating') AS cash_flow_group,
                COALESCE(c.profit_treatment, 'normal') AS profit_treatment,
                COALESCE(c.is_deductible, 1) AS is_deductible,
                COALESCE(c.is_cogs, 0) AS is_cogs,
                COALESCE(SUM(e.amount), 0) AS total_amount,
                COUNT(*) AS expense_count
             FROM finance_expenses e
             LEFT JOIN finance_expense_categories c ON c.id = e.category_id AND c.workspace_id = e.workspace_id
             WHERE e.workspace_id = ?
               AND e.status = 'paid'
               AND e.expense_date BETWEEN ? AND ?
             GROUP BY c.id, c.name, c.category_type, c.statement_group, c.cash_flow_group, c.profit_treatment, c.is_deductible, c.is_cogs, e.category_id
             ORDER BY total_amount DESC, category_name ASC",
            [$workspaceId, $dateFrom, $dateTo]
        );
    }

    private function profitAndLossDrilldowns(int $workspaceId, string $dateFrom, string $dateTo, array $expenseRows, array $nonOperatingItems): array
    {
        return [
            'revenue' => $this->paidInvoiceRows($workspaceId, $dateFrom, $dateTo),
            'manual_income' => $this->income->sourceRows($workspaceId, $dateFrom, $dateTo),
            'income_labels' => $this->manualIncomeByLabel($workspaceId, $dateFrom, $dateTo),
            'expenses' => $expenseRows,
            'profit_share_expenses' => array_values(array_filter($expenseRows, static fn (array $row): bool => (string) ($row['profit_treatment'] ?? 'normal') === 'post_net_profit_share')),
            'non_operating_items' => $nonOperatingItems,
        ];
    }

    private function cashFlowDrilldowns(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        $expenses = $this->expenseCashFlowRows($workspaceId, $dateFrom, $dateTo);
        $ledgerRows = $this->ledger->sourceRows($workspaceId, $dateFrom, $dateTo);
        return [
            'operating' => [
                'invoices' => $this->paidInvoiceRows($workspaceId, $dateFrom, $dateTo),
                'manual_income' => $this->income->sourceRows($workspaceId, $dateFrom, $dateTo),
                'expenses' => array_values(array_filter($expenses, static fn ($row) => ($row['cash_flow_group'] ?? 'operating') === 'operating')),
            ],
            'investing' => [
                'expenses' => array_values(array_filter($expenses, static fn ($row) => ($row['cash_flow_group'] ?? 'operating') === 'investing')),
                'ledger_entries' => array_values(array_filter($ledgerRows, static fn ($row) => in_array((string) ($row['transaction_type'] ?? ''), ['asset_purchase'], true))),
            ],
            'financing' => [
                'expenses' => array_values(array_filter($expenses, static fn ($row) => ($row['cash_flow_group'] ?? 'operating') === 'financing')),
                'ledger_entries' => array_values(array_filter($ledgerRows, static fn ($row) => in_array((string) ($row['transaction_type'] ?? ''), ['founder_capital', 'equity_funding', 'loan_received', 'loan_repayment', 'owner_draw', 'manual_adjustment'], true))),
            ],
        ];
    }

    private function balanceSheetDrilldowns(int $workspaceId, string $dateTo, array $ledgerData, array $pnl): array
    {
        $ledgerRows = $this->ledger->sourceRows($workspaceId, null, $dateTo);
        $byCodes = static function (array $codes) use ($ledgerRows): array {
            return array_values(array_filter($ledgerRows, static fn ($row) => in_array((string) ($row['account_code'] ?? ''), $codes, true)));
        };

        return [
            'cash' => $byCodes(['1000']),
            'receivables' => [
                'invoices' => $this->unpaidInvoiceRows($workspaceId, $dateTo),
                'ledger_entries' => $byCodes(['1100']),
            ],
            'assets' => $byCodes(['1200', '1300']),
            'loans' => $byCodes(['2000', '2100', '2200']),
            'equity' => [
                'ledger_entries' => $byCodes(['3000', '3100', '3200', '3300', '3400']),
                'current_net_income' => (float) ($pnl['summary']['net_income'] ?? 0),
            ],
            'ledger_balances' => (array) ($ledgerData['balances'] ?? []),
        ];
    }

    private function paidInvoiceRows(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        if (!Database::tableExists('invoices')) {
            return [];
        }
        return Database::query(
            "SELECT id, invoice_number, title, status, currency,
                IF(amount_paid > 0, amount_paid, grand_total) AS amount,
                COALESCE(paid_at, updated_at, issue_date, created_at) AS activity_date
             FROM invoices
             WHERE workspace_id = ?
               AND status IN ('paid','partially_paid')
               AND document_type = 'invoice'
               AND COALESCE(paid_at, updated_at, issue_date, created_at) >= ?
               AND COALESCE(paid_at, updated_at, issue_date, created_at) < DATE_ADD(?, INTERVAL 1 DAY)
             ORDER BY activity_date DESC, id DESC
             LIMIT 50",
            [$workspaceId, $dateFrom, $dateTo]
        );
    }

    private function manualIncomeByLabel(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        if (!Database::tableExists('finance_income_entries')) {
            return [];
        }

        return Database::query(
            "SELECT
                COALESCE(c.name, CASE WHEN i.income_type = 'other_income' THEN 'Other income' ELSE 'Sales' END) AS label_name,
                i.income_type,
                COALESCE(SUM(i.amount), 0) AS total_amount,
                COUNT(*) AS income_count
             FROM finance_income_entries i
             LEFT JOIN finance_income_categories c ON c.id = i.income_category_id AND c.workspace_id = i.workspace_id
             WHERE i.workspace_id = ?
               AND i.income_date BETWEEN ? AND ?
             GROUP BY label_name, i.income_type
             ORDER BY total_amount DESC, label_name ASC",
            [$workspaceId, $dateFrom, $dateTo]
        );
    }

    private function unpaidInvoiceRows(int $workspaceId, string $dateTo): array
    {
        if (!Database::tableExists('invoices')) {
            return [];
        }
        return Database::query(
            "SELECT id, invoice_number, title, status, currency,
                CASE
                    WHEN balance_due > 0 THEN balance_due
                    WHEN grand_total > amount_paid THEN grand_total - amount_paid
                    ELSE 0
                END AS amount,
                COALESCE(issue_date, created_at) AS activity_date
             FROM invoices
             WHERE workspace_id = ?
               AND document_type = 'invoice'
               AND status NOT IN ('paid', 'cancelled')
               AND COALESCE(issue_date, created_at) <= DATE_ADD(?, INTERVAL 1 DAY)
             ORDER BY activity_date DESC, id DESC
             LIMIT 50",
            [$workspaceId, $dateTo]
        );
    }

    private function expenseCashFlowRows(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        return Database::query(
            "SELECT e.id, e.description, e.vendor, e.amount, e.currency, e.expense_date, e.status,
                e.cash_account_id, a.name AS cash_account_name,
                COALESCE(c.name, 'Uncategorized') AS category_name,
                COALESCE(c.cash_flow_group, 'operating') AS cash_flow_group,
                COALESCE(c.profit_treatment, 'normal') AS profit_treatment
             FROM finance_expenses e
             LEFT JOIN finance_cash_accounts a ON a.id = e.cash_account_id AND a.workspace_id = e.workspace_id
             LEFT JOIN finance_expense_categories c ON c.id = e.category_id AND c.workspace_id = e.workspace_id
             WHERE e.workspace_id = ?
               AND e.status = 'paid'
               AND e.expense_date BETWEEN ? AND ?
             ORDER BY e.expense_date DESC, e.id DESC
             LIMIT 80",
            [$workspaceId, $dateFrom, $dateTo]
        );
    }

    private function vendorCount(int $workspaceId): int
    {
        if (!Database::tableExists('finance_vendors')) {
            return 0;
        }
        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM finance_vendors WHERE workspace_id = ? AND is_active = 1",
            [$workspaceId]
        )['c'] ?? 0);
    }

    private function mixedCurrencies(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        $currencies = [];
        $queries = [
            ["SELECT DISTINCT currency FROM finance_expenses WHERE workspace_id = ? AND expense_date BETWEEN ? AND ? AND currency <> ''", [$workspaceId, $dateFrom, $dateTo]],
            ["SELECT DISTINCT currency FROM finance_income_entries WHERE workspace_id = ? AND income_date BETWEEN ? AND ? AND currency <> ''", [$workspaceId, $dateFrom, $dateTo]],
            ["SELECT DISTINCT currency FROM finance_transactions WHERE workspace_id = ? AND transaction_date BETWEEN ? AND ? AND currency <> ''", [$workspaceId, $dateFrom, $dateTo]],
        ];
        if (Database::tableExists('invoices')) {
            $queries[] = ["SELECT DISTINCT currency FROM invoices WHERE workspace_id = ? AND COALESCE(paid_at, updated_at, issue_date, created_at) >= ? AND COALESCE(paid_at, updated_at, issue_date, created_at) < DATE_ADD(?, INTERVAL 1 DAY) AND currency <> ''", [$workspaceId, $dateFrom, $dateTo]];
        }
        foreach ($queries as [$sql, $params]) {
            try {
                foreach (Database::query($sql, $params) as $row) {
                    $code = strtoupper(trim((string) ($row['currency'] ?? '')));
                    if ($code !== '') {
                        $currencies[$code] = true;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return array_keys($currencies);
    }

    private function unpaidInvoiceReceivables(int $workspaceId, string $dateTo): float
    {
        if (!Database::tableExists('invoices')) {
            return 0.0;
        }

        $row = Database::queryOne(
            "SELECT COALESCE(SUM(CASE
                    WHEN balance_due > 0 THEN balance_due
                    WHEN grand_total > amount_paid THEN grand_total - amount_paid
                    ELSE 0
                END), 0) AS total
             FROM invoices
             WHERE workspace_id = ?
               AND document_type = 'invoice'
               AND status NOT IN ('paid', 'cancelled')
               AND COALESCE(issue_date, created_at) <= DATE_ADD(?, INTERVAL 1 DAY)",
            [$workspaceId, $dateTo]
        );

        return round((float) ($row['total'] ?? 0), 2);
    }

    private function plannedOutflows(int $workspaceId, string $dateFrom, string $dateTo): float
    {
        $planned = (float) (Database::queryOne(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM finance_expenses
             WHERE workspace_id = ?
               AND status = 'planned'
               AND expense_date BETWEEN ? AND ?",
            [$workspaceId, $dateFrom, $dateTo]
        )['total'] ?? 0);

        $recurring = (float) (Database::queryOne(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM finance_recurring_expenses
             WHERE workspace_id = ?
               AND is_active = 1
               AND next_due_date BETWEEN ? AND ?",
            [$workspaceId, $dateFrom, $dateTo]
        )['total'] ?? 0);

        return round($planned + $recurring, 2);
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
