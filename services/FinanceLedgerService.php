<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Currencies;

class FinanceLedgerService
{
    private WorkspaceScopeService $workspaceScope;

    /** @var array<string, array{label:string, note:string}> */
    private array $transactionTypes = [
        'founder_capital' => ['label' => 'Founder capital', 'note' => 'Founder cash contributed to the business.'],
        'equity_funding' => ['label' => 'Equity funding', 'note' => 'Investor or shareholder funding.'],
        'loan_received' => ['label' => 'Loan received', 'note' => 'Borrowed funds received into the business.'],
        'loan_repayment' => ['label' => 'Loan repayment', 'note' => 'Cash paid back against a loan.'],
        'owner_draw' => ['label' => 'Owner draw/dividend', 'note' => 'Cash taken out by owners or shareholders.'],
        'asset_purchase' => ['label' => 'Asset purchase', 'note' => 'Cash spent on an asset.'],
        'opening_balance' => ['label' => 'Opening balance', 'note' => 'Starting cash and equity for statements.'],
        'manual_adjustment' => ['label' => 'Manual adjustment', 'note' => 'Small owner-equity balancing entry.'],
    ];

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    public function transactionTypes(): array
    {
        $types = $this->transactionTypes;
        unset($types['owner_draw']);
        return $types;
    }

    public function ensureDefaultAccounts(int $workspaceId): void
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $accounts = [
            ['1000', 'Cash', 'asset', 'debit', 'cash', 1],
            ['1100', 'Accounts Receivable', 'asset', 'debit', 'receivables', 0],
            ['1200', 'Fixed Assets', 'asset', 'debit', 'assets', 0],
            ['1300', 'Other Assets', 'asset', 'debit', 'assets', 0],
            ['2000', 'Accounts Payable', 'liability', 'credit', 'liabilities', 0],
            ['2100', 'Loan Payable', 'liability', 'credit', 'liabilities', 0],
            ['2200', 'Tax Payable', 'liability', 'credit', 'liabilities', 0],
            ['3000', 'Owner Equity', 'equity', 'credit', 'equity', 0],
            ['3100', 'Share Capital', 'equity', 'credit', 'equity', 0],
            ['3200', 'Additional Paid-in Capital', 'equity', 'credit', 'equity', 0],
            ['3300', 'Retained Earnings', 'equity', 'credit', 'equity', 0],
            ['3400', 'Owner Draws and Dividends', 'equity', 'debit', 'equity', 0],
            ['4000', 'Other Income', 'revenue', 'credit', 'non_operating_income', 0],
            ['5000', 'Interest Expense', 'expense', 'debit', 'non_operating_expense', 0],
        ];

        foreach ($accounts as [$code, $name, $type, $normal, $section, $isCash]) {
            Database::execute(
                "INSERT INTO finance_accounts (
                    workspace_id, code, name, account_type, normal_balance, statement_section, is_cash, is_system
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    account_type = VALUES(account_type),
                    normal_balance = VALUES(normal_balance),
                    statement_section = VALUES(statement_section),
                    is_cash = VALUES(is_cash),
                    is_active = 1",
                [$workspaceId, $code, $name, $type, $normal, $section, $isCash]
            );
        }
    }

    public function saveGuidedTransaction(int $workspaceId, array $data, int $userId = 0): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $this->ensureDefaultAccounts($workspaceId);

        $type = $this->normalizeTransactionType((string) ($data['transaction_type'] ?? ''));
        $amount = $this->amount($data['amount'] ?? 0);
        if ($amount <= 0 && ($type !== 'opening_balance' || !$this->hasOpeningDetails($data))) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        $interestAmount = $this->amount($data['interest_amount'] ?? 0);
        if ($interestAmount > $amount) {
            throw new \InvalidArgumentException('Interest cannot exceed the repayment amount.');
        }

        $currency = $this->sanitizeCurrency((string) ($data['currency'] ?? $this->defaultCurrencyCode()));
        $date = $this->normalizeDate((string) ($data['transaction_date'] ?? ''), date('Y-m-d'));
        $counterparty = $this->sanitizeText((string) ($data['counterparty'] ?? $data['source'] ?? ''), 180);
        $ownerUserId = (new FinanceOwnerEquityService($this->workspaceScope))->validateOwnerUserId($workspaceId, $data['owner_user_id'] ?? 0, $type);
        $cashAccountId = (new FinanceCashAccountService($this->workspaceScope))->resolveCashAccountId($workspaceId, $data['cash_account_id'] ?? 0);
        $memo = $this->sanitizeText((string) ($data['memo'] ?? $data['notes'] ?? ''), 2000);
        $entries = $this->guidedEntries($workspaceId, $type, $amount, $interestAmount, $data);
        $this->assertBalanced($entries);

        Database::beginTransaction();
        try {
            $columns = ['workspace_id', 'transaction_type', 'transaction_date', 'amount', 'currency', 'counterparty'];
            $values = [$workspaceId, $type, $date, $amount, $currency, $counterparty ?: null];
            if (Database::columnExists('finance_transactions', 'cash_account_id')) {
                $columns[] = 'cash_account_id';
                $values[] = $cashAccountId;
            }
            if (Database::columnExists('finance_transactions', 'owner_user_id')) {
                $columns[] = 'owner_user_id';
                $values[] = $ownerUserId;
            }
            $columns[] = 'memo';
            $values[] = $memo ?: null;
            $columns[] = 'created_by';
            $values[] = $userId > 0 ? $userId : null;
            Database::execute(
                "INSERT INTO finance_transactions (" . implode(', ', $columns) . ")
                 VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")",
                $values
            );
            $transactionId = (int) Database::lastInsertId();

            foreach ($entries as $entry) {
                Database::execute(
                    "INSERT INTO finance_journal_entries (
                        workspace_id, transaction_id, account_id, entry_type, amount, currency, memo
                     ) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $workspaceId,
                        $transactionId,
                        (int) $entry['account_id'],
                        (string) $entry['entry_type'],
                        (float) $entry['amount'],
                        $currency,
                        $memo ?: null,
                    ]
                );
            }

            Database::commit();
            return $transactionId;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public function getTransaction(int $workspaceId, int $transactionId): ?array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if ($transactionId <= 0 || !Database::tableExists('finance_transactions')) {
            return null;
        }
        $this->ensureDefaultAccounts($workspaceId);

        $transaction = Database::queryOne(
            "SELECT *
             FROM finance_transactions
             WHERE workspace_id = ? AND id = ?
             LIMIT 1",
            [$workspaceId, $transactionId]
        );
        if (!$transaction) {
            return null;
        }

        $entries = Database::query(
            "SELECT je.*, a.code, a.name AS account_name, a.account_type, a.normal_balance, a.statement_section
             FROM finance_journal_entries je
             JOIN finance_accounts a ON a.id = je.account_id AND a.workspace_id = je.workspace_id
             WHERE je.workspace_id = ? AND je.transaction_id = ?
             ORDER BY je.id ASC",
            [$workspaceId, $transactionId]
        );

        $transaction['entries'] = $entries;
        $transaction['opening_details'] = $this->openingDetailsFromEntries($entries);
        return $transaction;
    }

    public function deleteGuidedTransaction(int $workspaceId, int $transactionId): void
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if ($transactionId <= 0) {
            throw new \InvalidArgumentException('Transaction is required.');
        }
        Database::execute(
            "DELETE FROM finance_journal_entries WHERE workspace_id = ? AND transaction_id = ?",
            [$workspaceId, $transactionId]
        );
        Database::execute(
            "DELETE FROM finance_transactions WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $transactionId]
        );
    }

    public function replaceGuidedTransaction(int $workspaceId, int $transactionId, array $data, int $userId = 0): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if ($transactionId <= 0) {
            throw new \InvalidArgumentException('Transaction is required.');
        }
        if (!$this->getTransaction($workspaceId, $transactionId)) {
            throw new \RuntimeException('Finance transaction was not found.');
        }

        $this->ensureDefaultAccounts($workspaceId);
        $type = $this->normalizeTransactionType((string) ($data['transaction_type'] ?? ''));
        $amount = $this->amount($data['amount'] ?? 0);
        if ($amount <= 0 && ($type !== 'opening_balance' || !$this->hasOpeningDetails($data))) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        $interestAmount = $this->amount($data['interest_amount'] ?? 0);
        if ($interestAmount > $amount) {
            throw new \InvalidArgumentException('Interest cannot exceed the repayment amount.');
        }

        $currency = $this->sanitizeCurrency((string) ($data['currency'] ?? $this->defaultCurrencyCode()));
        $date = $this->normalizeDate((string) ($data['transaction_date'] ?? ''), date('Y-m-d'));
        $counterparty = $this->sanitizeText((string) ($data['counterparty'] ?? $data['source'] ?? ''), 180);
        $ownerUserId = (new FinanceOwnerEquityService($this->workspaceScope))->validateOwnerUserId($workspaceId, $data['owner_user_id'] ?? 0, $type);
        $cashAccountId = (new FinanceCashAccountService($this->workspaceScope))->resolveCashAccountId($workspaceId, $data['cash_account_id'] ?? 0);
        $memo = $this->sanitizeText((string) ($data['memo'] ?? $data['notes'] ?? ''), 2000);
        $entries = $this->guidedEntries($workspaceId, $type, $amount, $interestAmount, $data);
        $this->assertBalanced($entries);

        Database::beginTransaction();
        try {
            $sets = ['transaction_type = ?', 'transaction_date = ?', 'amount = ?', 'currency = ?', 'counterparty = ?'];
            $values = [$type, $date, $amount, $currency, $counterparty ?: null];
            if (Database::columnExists('finance_transactions', 'cash_account_id')) {
                $sets[] = 'cash_account_id = ?';
                $values[] = $cashAccountId;
            }
            if (Database::columnExists('finance_transactions', 'owner_user_id')) {
                $sets[] = 'owner_user_id = ?';
                $values[] = $ownerUserId;
            }
            $sets[] = 'memo = ?';
            $values[] = $memo ?: null;
            $values[] = $workspaceId;
            $values[] = $transactionId;
            Database::execute(
                "UPDATE finance_transactions
                 SET " . implode(', ', $sets) . "
                 WHERE workspace_id = ? AND id = ?",
                $values
            );
            Database::execute(
                "DELETE FROM finance_journal_entries WHERE workspace_id = ? AND transaction_id = ?",
                [$workspaceId, $transactionId]
            );
            foreach ($entries as $entry) {
                Database::execute(
                    "INSERT INTO finance_journal_entries (
                        workspace_id, transaction_id, account_id, entry_type, amount, currency, memo
                     ) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $workspaceId,
                        $transactionId,
                        (int) $entry['account_id'],
                        (string) $entry['entry_type'],
                        (float) $entry['amount'],
                        $currency,
                        $memo ?: null,
                    ]
                );
            }
            Database::commit();
            return $transactionId;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public function recentTransactions(int $workspaceId, int $limit = 6): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_transactions')) {
            return [];
        }
        $this->ensureDefaultAccounts($workspaceId);

        return Database::query(
            "SELECT t.*, a.name AS cash_account_name
             FROM finance_transactions t
             LEFT JOIN finance_cash_accounts a ON a.id = t.cash_account_id AND a.workspace_id = t.workspace_id
             WHERE t.workspace_id = ?
             ORDER BY t.transaction_date DESC, t.id DESC
             LIMIT " . max(1, (int) $limit),
            [$workspaceId]
        );
    }

    public function fundingSummary(int $workspaceId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_transactions')) {
            return ['total' => 0.0, 'count' => 0, 'by_type' => []];
        }
        $dateFrom = $this->normalizeDate($dateFrom ?: '1970-01-01', '1970-01-01');
        $dateTo = $this->normalizeDate($dateTo ?: date('Y-m-t'), date('Y-m-t'));

        $rows = Database::query(
            "SELECT transaction_type, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS c
             FROM finance_transactions
             WHERE workspace_id = ?
               AND transaction_date BETWEEN ? AND ?
               AND transaction_type IN ('founder_capital','equity_funding','loan_received','opening_balance')
             GROUP BY transaction_type
             ORDER BY total DESC",
            [$workspaceId, $dateFrom, $dateTo]
        );

        $total = 0.0;
        $byType = [];
        $count = 0;
        foreach ($rows as $row) {
            $amount = (float) ($row['total'] ?? 0);
            $total += $amount;
            $count += (int) ($row['c'] ?? 0);
            $byType[(string) ($row['transaction_type'] ?? '')] = $amount;
        }

        return ['total' => round($total, 2), 'count' => $count, 'by_type' => $byType];
    }

    public function statementData(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_transactions')) {
            return $this->emptyStatementData();
        }
        $this->ensureDefaultAccounts($workspaceId);
        $dateFrom = $this->normalizeDate($dateFrom, date('Y-m-01'));
        $dateTo = $this->normalizeDate($dateTo, date('Y-m-t'));

        $balances = $this->accountBalances($workspaceId, $dateTo);
        $cashFlow = $this->periodCashFlow($workspaceId, $dateFrom, $dateTo);
        $openingCash = $this->openingCash($workspaceId, $dateTo);
        $nonOperating = $this->nonOperatingItems($workspaceId, $dateFrom, $dateTo);
        $counts = $this->transactionCounts($workspaceId, $dateTo);
        $mixedCurrencies = $this->mixedCurrencies($workspaceId, $dateFrom, $dateTo);

        return [
            'balances' => $balances,
            'cash_flow' => $cashFlow,
            'opening_cash' => $openingCash,
            'has_opening_balance' => ($counts['opening_balance'] ?? 0) > 0,
            'has_capital_or_funding' => (($counts['founder_capital'] ?? 0) + ($counts['equity_funding'] ?? 0) + ($counts['loan_received'] ?? 0)) > 0,
            'has_ledger_activity' => array_sum($counts) > 0,
            'transaction_counts' => $counts,
            'mixed_currencies' => $mixedCurrencies,
            'non_operating_items' => $nonOperating,
            'warnings' => $this->ledgerWarnings($balances, $counts, $mixedCurrencies),
        ];
    }

    public function accountBalances(int $workspaceId, string $dateTo): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $dateTo = $this->normalizeDate($dateTo, date('Y-m-t'));

        $rows = Database::query(
            "SELECT
                a.code,
                a.name,
                a.account_type,
                a.normal_balance,
                a.statement_section,
                COALESCE(SUM(CASE WHEN je.entry_type = 'debit' THEN je.amount ELSE 0 END), 0) AS debits,
                COALESCE(SUM(CASE WHEN je.entry_type = 'credit' THEN je.amount ELSE 0 END), 0) AS credits
             FROM finance_accounts a
             LEFT JOIN finance_journal_entries je ON je.account_id = a.id AND je.workspace_id = a.workspace_id
             LEFT JOIN finance_transactions t ON t.id = je.transaction_id AND t.workspace_id = je.workspace_id
             WHERE a.workspace_id = ?
               AND a.is_active = 1
               AND (t.id IS NULL OR t.transaction_date <= ?)
             GROUP BY a.id, a.code, a.name, a.account_type, a.normal_balance, a.statement_section
             ORDER BY a.code",
            [$workspaceId, $dateTo]
        );

        $balances = [];
        foreach ($rows as $row) {
            $debits = (float) ($row['debits'] ?? 0);
            $credits = (float) ($row['credits'] ?? 0);
            $normal = (string) ($row['normal_balance'] ?? 'debit');
            $balance = $normal === 'credit' ? $credits - $debits : $debits - $credits;
            $balances[(string) $row['code']] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'account_type' => (string) $row['account_type'],
                'normal_balance' => $normal,
                'statement_section' => (string) $row['statement_section'],
                'debits' => round($debits, 2),
                'credits' => round($credits, 2),
                'balance' => round($balance, 2),
            ];
        }

        return $balances;
    }

    public function sourceRows(int $workspaceId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_transactions')) {
            return [];
        }
        $this->ensureDefaultAccounts($workspaceId);
        $params = [$workspaceId];
        $where = ['t.workspace_id = ?'];
        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 't.transaction_date >= ?';
            $params[] = $this->normalizeDate($dateFrom, '1970-01-01');
        }
        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 't.transaction_date <= ?';
            $params[] = $this->normalizeDate($dateTo, date('Y-m-t'));
        }

        return Database::query(
            "SELECT
                t.id,
                t.transaction_type,
                t.transaction_date,
                t.amount,
                t.currency,
                t.counterparty,
                t.memo,
                t.cash_account_id,
                ca.name AS cash_account_name,
                a.code AS account_code,
                a.name AS account_name,
                a.account_type,
                a.statement_section,
                je.entry_type,
                je.amount AS entry_amount
             FROM finance_transactions t
             JOIN finance_journal_entries je ON je.transaction_id = t.id AND je.workspace_id = t.workspace_id
             JOIN finance_accounts a ON a.id = je.account_id AND a.workspace_id = je.workspace_id
             LEFT JOIN finance_cash_accounts ca ON ca.id = t.cash_account_id AND ca.workspace_id = t.workspace_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY t.transaction_date DESC, t.id DESC, je.id ASC",
            $params
        );
    }

    private function guidedEntries(int $workspaceId, string $type, float $amount, float $interestAmount, array $data = []): array
    {
        $cash = $this->accountId($workspaceId, '1000');
        $receivables = $this->accountId($workspaceId, '1100');
        $ownerEquity = $this->accountId($workspaceId, '3000');
        $shareCapital = $this->accountId($workspaceId, '3100');
        $accountsPayable = $this->accountId($workspaceId, '2000');
        $loanPayable = $this->accountId($workspaceId, '2100');
        $fixedAssets = $this->accountId($workspaceId, '1200');
        $retainedEarnings = $this->accountId($workspaceId, '3300');
        $ownerDraws = $this->accountId($workspaceId, '3400');
        $interestExpense = $this->accountId($workspaceId, '5000');

        if ($type === 'founder_capital') {
            return [$this->debit($cash, $amount), $this->credit($ownerEquity, $amount)];
        }
        if ($type === 'equity_funding') {
            return [$this->debit($cash, $amount), $this->credit($shareCapital, $amount)];
        }
        if ($type === 'loan_received') {
            return [$this->debit($cash, $amount), $this->credit($loanPayable, $amount)];
        }
        if ($type === 'loan_repayment') {
            $principal = round($amount - $interestAmount, 2);
            $entries = [$this->debit($loanPayable, $principal), $this->credit($cash, $amount)];
            if ($interestAmount > 0) {
                $entries[] = $this->debit($interestExpense, $interestAmount);
            }
            return $entries;
        }
        if ($type === 'owner_draw') {
            return [$this->debit($ownerDraws, $amount), $this->credit($cash, $amount)];
        }
        if ($type === 'asset_purchase') {
            return [$this->debit($fixedAssets, $amount), $this->credit($cash, $amount)];
        }
        if ($type === 'opening_balance') {
            if ($this->hasOpeningDetails($data)) {
                $openingCash = $this->amount($data['opening_cash'] ?? $amount);
                $openingReceivables = $this->amount($data['opening_receivables'] ?? 0);
                $openingAssets = $this->amount($data['opening_assets'] ?? 0);
                $openingPayables = $this->amount($data['opening_payables'] ?? 0);
                $openingLoan = $this->amount($data['opening_loan_balance'] ?? 0);
                $openingEquity = $this->amount($data['opening_equity'] ?? 0);
                $entries = [];
                if ($openingCash > 0) {
                    $entries[] = $this->debit($cash, $openingCash);
                }
                if ($openingReceivables > 0) {
                    $entries[] = $this->debit($receivables, $openingReceivables);
                }
                if ($openingAssets > 0) {
                    $entries[] = $this->debit($fixedAssets, $openingAssets);
                }
                if ($openingPayables > 0) {
                    $entries[] = $this->credit($accountsPayable, $openingPayables);
                }
                if ($openingLoan > 0) {
                    $entries[] = $this->credit($loanPayable, $openingLoan);
                }
                if ($openingEquity > 0) {
                    $entries[] = $this->credit($retainedEarnings, $openingEquity);
                }
                $debits = $this->entryTotal($entries, 'debit');
                $credits = $this->entryTotal($entries, 'credit');
                $difference = round($debits - $credits, 2);
                if ($difference > 0) {
                    $entries[] = $this->credit($retainedEarnings, $difference);
                } elseif ($difference < 0) {
                    $entries[] = $this->debit($retainedEarnings, abs($difference));
                }
                return $entries;
            }
            return [$this->debit($cash, $amount), $this->credit($retainedEarnings, $amount)];
        }

        return [$this->debit($cash, $amount), $this->credit($ownerEquity, $amount)];
    }

    private function periodCashFlow(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        $rows = Database::query(
            "SELECT transaction_type, COALESCE(SUM(amount), 0) AS total
             FROM finance_transactions
             WHERE workspace_id = ?
               AND transaction_date BETWEEN ? AND ?
             GROUP BY transaction_type",
            [$workspaceId, $dateFrom, $dateTo]
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) ($row['transaction_type'] ?? '')] = (float) ($row['total'] ?? 0);
        }

        $financingInflows = ($totals['founder_capital'] ?? 0) + ($totals['equity_funding'] ?? 0) + ($totals['loan_received'] ?? 0) + ($totals['manual_adjustment'] ?? 0);
        $financingOutflows = ($totals['loan_repayment'] ?? 0) + ($totals['owner_draw'] ?? 0);
        $investingOutflows = (float) ($totals['asset_purchase'] ?? 0);

        return [
            'financing_inflows' => round($financingInflows, 2),
            'financing_outflows' => round($financingOutflows, 2),
            'financing_net' => round($financingInflows - $financingOutflows, 2),
            'investing_inflows' => 0.0,
            'investing_outflows' => round($investingOutflows, 2),
            'investing_net' => round(0 - $investingOutflows, 2),
            'by_type' => array_map(static fn ($value) => round((float) $value, 2), $totals),
        ];
    }

    private function nonOperatingItems(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        $rows = Database::query(
            "SELECT
                a.code,
                a.name,
                a.account_type,
                a.normal_balance,
                COALESCE(SUM(CASE WHEN je.entry_type = 'debit' THEN je.amount ELSE 0 END), 0) AS debits,
                COALESCE(SUM(CASE WHEN je.entry_type = 'credit' THEN je.amount ELSE 0 END), 0) AS credits
             FROM finance_journal_entries je
             JOIN finance_accounts a ON a.id = je.account_id AND a.workspace_id = je.workspace_id
             JOIN finance_transactions t ON t.id = je.transaction_id AND t.workspace_id = je.workspace_id
             WHERE je.workspace_id = ?
               AND t.transaction_date BETWEEN ? AND ?
               AND a.statement_section IN ('non_operating_income','non_operating_expense')
             GROUP BY a.id, a.code, a.name, a.account_type, a.normal_balance
             ORDER BY a.code",
            [$workspaceId, $dateFrom, $dateTo]
        );

        $items = [];
        foreach ($rows as $row) {
            $debits = (float) ($row['debits'] ?? 0);
            $credits = (float) ($row['credits'] ?? 0);
            $normal = (string) ($row['normal_balance'] ?? 'debit');
            $amount = $normal === 'credit' ? $credits - $debits : $debits - $credits;
            if (abs($amount) < 0.01) {
                continue;
            }
            $items[] = [
                'code' => (string) $row['code'],
                'label' => (string) $row['name'],
                'account_type' => (string) $row['account_type'],
                'amount' => round($amount, 2),
            ];
        }

        return $items;
    }

    private function openingCash(int $workspaceId, string $dateTo): float
    {
        $row = Database::queryOne(
            "SELECT COALESCE(SUM(CASE
                    WHEN je.entry_type = 'debit' THEN je.amount
                    WHEN je.entry_type = 'credit' THEN -je.amount
                    ELSE 0
                END), 0) AS total
             FROM finance_transactions t
             JOIN finance_journal_entries je ON je.transaction_id = t.id AND je.workspace_id = t.workspace_id
             JOIN finance_accounts a ON a.id = je.account_id AND a.workspace_id = je.workspace_id
             WHERE t.workspace_id = ?
               AND t.transaction_type = 'opening_balance'
               AND t.transaction_date <= ?
               AND a.code = '1000'",
            [$workspaceId, $dateTo]
        );

        return round((float) ($row['total'] ?? 0), 2);
    }

    private function transactionCounts(int $workspaceId, string $dateTo): array
    {
        $rows = Database::query(
            "SELECT transaction_type, COUNT(*) AS c
             FROM finance_transactions
             WHERE workspace_id = ?
               AND transaction_date <= ?
             GROUP BY transaction_type",
            [$workspaceId, $dateTo]
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) ($row['transaction_type'] ?? '')] = (int) ($row['c'] ?? 0);
        }

        return $counts;
    }

    private function ledgerWarnings(array $balances, array $counts, array $mixedCurrencies = []): array
    {
        $warnings = [];
        $loanBalance = (float) ($balances['2100']['balance'] ?? 0);
        $assetBalance = (float) ($balances['1200']['balance'] ?? 0);
        $equityBase = (float) ($balances['3000']['balance'] ?? 0) + (float) ($balances['3100']['balance'] ?? 0) + (float) ($balances['3300']['balance'] ?? 0);

        if (($counts['loan_repayment'] ?? 0) > 0 && $loanBalance < -0.01) {
            $warnings[] = 'loan_repayments_without_loan_balance';
        }
        if (($counts['asset_purchase'] ?? 0) > 0 && $assetBalance <= 0) {
            $warnings[] = 'asset_purchase_without_asset_balance';
        }
        if (($counts['owner_draw'] ?? 0) > 0 && $equityBase <= 0) {
            $warnings[] = 'equity_draw_without_opening_equity';
        }
        if (count($mixedCurrencies) > 1) {
            $warnings[] = 'mixed_currency_without_fx';
        }

        return $warnings;
    }

    private function accountId(int $workspaceId, string $code): int
    {
        $row = Database::queryOne(
            "SELECT id FROM finance_accounts WHERE workspace_id = ? AND code = ? LIMIT 1",
            [$workspaceId, $code]
        );
        if (!$row) {
            $this->ensureDefaultAccounts($workspaceId);
            $row = Database::queryOne(
                "SELECT id FROM finance_accounts WHERE workspace_id = ? AND code = ? LIMIT 1",
                [$workspaceId, $code]
            );
        }
        if (!$row) {
            throw new \RuntimeException('Finance account ' . $code . ' is missing.');
        }
        return (int) $row['id'];
    }

    private function debit(int $accountId, float $amount): array
    {
        return ['account_id' => $accountId, 'entry_type' => 'debit', 'amount' => round($amount, 2)];
    }

    private function credit(int $accountId, float $amount): array
    {
        return ['account_id' => $accountId, 'entry_type' => 'credit', 'amount' => round($amount, 2)];
    }

    private function assertBalanced(array $entries): void
    {
        $debits = 0.0;
        $credits = 0.0;
        foreach ($entries as $entry) {
            if ((float) ($entry['amount'] ?? 0) <= 0) {
                throw new \InvalidArgumentException('Journal entry amounts must be greater than zero.');
            }
            if (($entry['entry_type'] ?? '') === 'debit') {
                $debits += (float) $entry['amount'];
            } elseif (($entry['entry_type'] ?? '') === 'credit') {
                $credits += (float) $entry['amount'];
            }
        }
        if (round($debits, 2) !== round($credits, 2)) {
            throw new \InvalidArgumentException('Journal entries must balance.');
        }
    }

    private function hasOpeningDetails(array $data): bool
    {
        if (!empty($data['opening_setup_complete'])) {
            return true;
        }
        foreach (['opening_cash', 'opening_receivables', 'opening_payables', 'opening_loan_balance', 'opening_assets', 'opening_equity'] as $key) {
            if (array_key_exists($key, $data) && trim((string) $data[$key]) !== '') {
                return true;
            }
        }
        return false;
    }

    private function entryTotal(array $entries, string $type): float
    {
        $total = 0.0;
        foreach ($entries as $entry) {
            if (($entry['entry_type'] ?? '') === $type) {
                $total += (float) ($entry['amount'] ?? 0);
            }
        }
        return round($total, 2);
    }

    private function openingDetailsFromEntries(array $entries): array
    {
        $details = [
            'opening_cash' => 0.0,
            'opening_receivables' => 0.0,
            'opening_payables' => 0.0,
            'opening_loan_balance' => 0.0,
            'opening_assets' => 0.0,
            'opening_equity' => 0.0,
        ];
        foreach ($entries as $entry) {
            $code = (string) ($entry['code'] ?? '');
            $amount = (float) ($entry['amount'] ?? 0);
            if ($code === '1000' && ($entry['entry_type'] ?? '') === 'debit') {
                $details['opening_cash'] += $amount;
            } elseif ($code === '1100' && ($entry['entry_type'] ?? '') === 'debit') {
                $details['opening_receivables'] += $amount;
            } elseif ($code === '1200' && ($entry['entry_type'] ?? '') === 'debit') {
                $details['opening_assets'] += $amount;
            } elseif ($code === '2000' && ($entry['entry_type'] ?? '') === 'credit') {
                $details['opening_payables'] += $amount;
            } elseif ($code === '2100' && ($entry['entry_type'] ?? '') === 'credit') {
                $details['opening_loan_balance'] += $amount;
            } elseif ($code === '3300' && ($entry['entry_type'] ?? '') === 'credit') {
                $details['opening_equity'] += $amount;
            }
        }
        return array_map(static fn ($value) => round((float) $value, 2), $details);
    }

    private function mixedCurrencies(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        $rows = Database::query(
            "SELECT DISTINCT currency
             FROM finance_transactions
             WHERE workspace_id = ?
               AND transaction_date BETWEEN ? AND ?
               AND currency IS NOT NULL
               AND currency <> ''
             ORDER BY currency",
            [$workspaceId, $dateFrom, $dateTo]
        );
        return array_values(array_filter(array_map(
            static fn ($row) => strtoupper(trim((string) ($row['currency'] ?? ''))),
            $rows
        )));
    }

    private function normalizeTransactionType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!isset($this->transactionTypes[$type])) {
            throw new \InvalidArgumentException('Unsupported finance transaction type.');
        }
        return $type;
    }

    private function sanitizeCurrency(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z]{3,10}$/', $value) ? $value : $this->defaultCurrencyCode();
    }

    private function normalizeDate(string $value, string $fallback): string
    {
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : $fallback;
    }

    private function amount(mixed $value): float
    {
        return round(max(0, min((float) $value, 999999999.99)), 2);
    }

    private function sanitizeText(string $value, int $max): string
    {
        return mb_substr(trim(strip_tags($value)), 0, $max);
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

    private function emptyStatementData(): array
    {
        return [
            'balances' => [],
            'cash_flow' => [
                'financing_inflows' => 0.0,
                'financing_outflows' => 0.0,
                'financing_net' => 0.0,
                'investing_inflows' => 0.0,
                'investing_outflows' => 0.0,
                'investing_net' => 0.0,
                'by_type' => [],
            ],
            'opening_cash' => 0.0,
            'has_opening_balance' => false,
            'has_capital_or_funding' => false,
            'has_ledger_activity' => false,
            'transaction_counts' => [],
            'mixed_currencies' => [],
            'non_operating_items' => [],
            'warnings' => [],
        ];
    }
}
