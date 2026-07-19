<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Currencies;

class FinanceOpeningSetupService
{
    private const REQUIRED_TABS = ['start', 'bank', 'assets', 'receivables', 'liabilities', 'owners'];

    private WorkspaceScopeService $workspaceScope;
    private FinanceCashAccountService $cashAccounts;
    private FinanceOwnerEquityService $ownerEquity;
    private FinanceLedgerService $ledger;
    private array $statusCache = [];

    public function __construct(
        ?WorkspaceScopeService $workspaceScope = null,
        ?FinanceCashAccountService $cashAccounts = null,
        ?FinanceOwnerEquityService $ownerEquity = null,
        ?FinanceLedgerService $ledger = null
    ) {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
        $this->cashAccounts = $cashAccounts ?? new FinanceCashAccountService($this->workspaceScope);
        $this->ownerEquity = $ownerEquity ?? new FinanceOwnerEquityService($this->workspaceScope);
        $this->ledger = $ledger ?? new FinanceLedgerService($this->workspaceScope);
    }

    public function setupFormData(int $workspaceId): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $setup = $this->setupRow($workspaceId) ?? $this->defaultSetup();
        $summary = $this->summary($workspaceId);
        $owners = $this->ownerRows($workspaceId, $summary);
        $profiles = $this->ownerEquity->profiles($workspaceId);

        $opening = [
            'opening_date' => (string) ($setup['opening_date'] ?? date('Y-m-d')),
            'currency' => (string) ($setup['currency'] ?? $this->defaultCurrencyCode()),
            'opening_cash' => $this->money($summary['bank_total'] ?? 0),
            'opening_receivables' => $this->money($summary['receivables_total'] ?? 0),
            'opening_payables' => $this->money($summary['other_liabilities_total'] ?? 0),
            'opening_loan_balance' => $this->money($summary['loans_total'] ?? 0),
            'opening_assets' => $this->money($summary['assets_total'] ?? 0),
            'opening_equity' => $this->money($summary['opening_equity'] ?? 0),
            'notes' => (string) ($setup['notes'] ?? ''),
        ];

        return [
            'setup' => $setup,
            'opening' => $opening,
            'bank_accounts' => $this->bankRows($workspaceId),
            'assets' => $this->assetRows($workspaceId),
            'receivables' => $this->receivableRows($workspaceId),
            'liabilities' => $this->liabilityRows($workspaceId),
            'owners' => $owners,
            'profiles' => array_values($profiles),
            'summary' => $summary,
            'status' => $this->status($workspaceId),
        ];
    }

    public function status(int $workspaceId): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);

        $setup = $this->setupRow($workspaceId);
        $legacyOpening = $this->latestOpeningBalance($workspaceId);
        if (!Database::tableExists('finance_opening_setups')) {
            return $legacyOpening !== null
                ? ['ok' => true, 'detail' => 'Opening figures were saved.', 'legacy' => true]
                : ['ok' => false, 'detail' => 'Opening setup is not installed.'];
        }

        if ($setup === null) {
            return $legacyOpening !== null
                ? ['ok' => true, 'detail' => 'Opening figures were saved.', 'legacy' => true]
                : [
                    'ok' => false,
                    'detail' => 'Start Finance setup.',
                    'missing_tabs' => self::REQUIRED_TABS,
                    'summary' => $this->summary($workspaceId),
                ];
        }

        $missing = $this->missingTabs($setup);
        $hasOpeningTransaction = (int) ($setup['opening_transaction_id'] ?? 0) > 0 || $legacyOpening !== null;
        $ok = empty($setup['is_complete']) === false && $missing === [] && $hasOpeningTransaction;
        $detail = $ok
            ? 'Initial balance sheet saved.'
            : ($missing === [] ? 'Review and finish setup.' : 'Finish: ' . implode(', ', array_map([$this, 'tabLabel'], $missing)) . '.');

        return [
            'ok' => $ok,
            'detail' => $detail,
            'missing_tabs' => $missing,
            'reviewed_tabs' => $this->reviewedTabs($setup),
            'is_complete' => !empty($setup['is_complete']),
            'opening_transaction_id' => (int) ($setup['opening_transaction_id'] ?? 0),
            'summary' => $this->summary($workspaceId),
        ];
    }

    public function saveTab(int $workspaceId, string $tab, array $data, int $userId = 0): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        unset($this->statusCache[$workspaceId]);
        if (!Database::tableExists('finance_opening_setups')) {
            throw new \RuntimeException('Finance setup tables are not installed.');
        }

        $tab = $this->normalizeTab($tab);
        $this->ensureSetup($workspaceId, $data, $userId);

        if ($tab === 'review') {
            $this->applyReviewConfirmations($workspaceId, $data, $userId);
            $this->completeReview($workspaceId, $userId);
            return $this->setupFormData($workspaceId);
        }

        if ($tab === 'start') {
            $this->saveStart($workspaceId, $data, $userId);
        } elseif ($tab === 'bank') {
            $this->saveBank($workspaceId, $data, $userId);
        } elseif ($tab === 'assets') {
            $rows = !empty($data['assets_none']) ? [] : $this->normalizeAssets((array) ($data['assets'] ?? []));
            $this->replaceSimpleRows($workspaceId, 'finance_opening_assets', $rows);
        } elseif ($tab === 'receivables') {
            $rows = !empty($data['receivables_none']) ? [] : $this->normalizeReceivables((array) ($data['receivables'] ?? []));
            $this->replaceSimpleRows($workspaceId, 'finance_opening_receivables', $rows);
        } elseif ($tab === 'liabilities') {
            $rows = !empty($data['liabilities_none']) ? [] : $this->normalizeLiabilities((array) ($data['liabilities'] ?? []));
            $this->replaceSimpleRows($workspaceId, 'finance_opening_liabilities', $rows);
        } elseif ($tab === 'owners') {
            $this->saveOwners($workspaceId, $data, $userId);
        }

        $this->markReviewed($workspaceId, $tab, $userId, true);
        return $this->setupFormData($workspaceId);
    }

    public function saveLegacySetup(int $workspaceId, array $data, int $userId = 0): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        unset($this->statusCache[$workspaceId]);
        if (!Database::tableExists('finance_opening_setups')) {
            throw new \RuntimeException('Finance setup tables are not installed.');
        }

        $opening = [];
        foreach (['opening_cash', 'opening_receivables', 'opening_payables', 'opening_loan_balance', 'opening_assets', 'opening_equity'] as $key) {
            if (!array_key_exists($key, $data) || trim((string) $data[$key]) === '') {
                throw new \InvalidArgumentException('Save every opening figure, using 0.00 when it does not apply.');
            }
            $opening[$key] = $this->amount($data[$key]);
        }

        $currency = $this->sanitizeCurrency((string) ($data['currency'] ?? $this->defaultCurrencyCode()));
        $this->ensureSetup($workspaceId, [
            'opening_date' => $data['opening_date'] ?? date('Y-m-d'),
            'currency' => $currency,
            'notes' => (string) ($data['notes'] ?? ''),
        ], $userId);
        $this->saveStart($workspaceId, [
            'opening_date' => $data['opening_date'] ?? date('Y-m-d'),
            'currency' => $currency,
            'notes' => (string) ($data['notes'] ?? ''),
        ], $userId);

        $this->saveBank($workspaceId, [
            'currency' => $currency,
            'bank_accounts' => [[
                'name' => 'Main Bank',
                'account_type' => 'bank',
                'opening_balance' => $opening['opening_cash'],
                'note' => 'Opening cash',
                'is_default' => 1,
            ]],
        ], $userId);
        $this->replaceSimpleRows($workspaceId, 'finance_opening_assets', $opening['opening_assets'] > 0 ? [[
            'name' => 'Opening assets',
            'asset_type' => 'other',
            'value' => $opening['opening_assets'],
            'note' => 'Saved from old setup form',
        ]] : []);
        $this->replaceSimpleRows($workspaceId, 'finance_opening_receivables', $opening['opening_receivables'] > 0 ? [[
            'from_name' => 'Opening receivables',
            'amount' => $opening['opening_receivables'],
            'due_date' => null,
            'note' => 'Saved from old setup form',
        ]] : []);

        $liabilities = [];
        if ($opening['opening_loan_balance'] > 0) {
            $liabilities[] = [
                'name' => 'Opening loan',
                'liability_type' => 'loan',
                'amount' => $opening['opening_loan_balance'],
                'due_date' => null,
                'interest_rate' => null,
                'note' => 'Saved from old setup form',
            ];
        }
        if ($opening['opening_payables'] > 0) {
            $liabilities[] = [
                'name' => 'Opening payables',
                'liability_type' => 'supplier_bill',
                'amount' => $opening['opening_payables'],
                'due_date' => null,
                'interest_rate' => null,
                'note' => 'Saved from old setup form',
            ];
        }
        $this->replaceSimpleRows($workspaceId, 'finance_opening_liabilities', $liabilities);
        $this->saveOwners($workspaceId, array_merge($data, ['currency' => $currency]), $userId);

        foreach (self::REQUIRED_TABS as $tab) {
            $this->markReviewed($workspaceId, $tab, $userId, false);
        }
        $this->completeReview($workspaceId, $userId);

        return $this->setupFormData($workspaceId);
    }

    public function summary(int $workspaceId): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $bankTotal = $this->sumTable($workspaceId, 'finance_opening_bank_balances', 'opening_balance');
        $assetsTotal = $this->sumTable($workspaceId, 'finance_opening_assets', 'value');
        $receivablesTotal = $this->sumTable($workspaceId, 'finance_opening_receivables', 'amount');
        $loansTotal = $this->sumLiabilities($workspaceId, true);
        $otherLiabilities = $this->sumLiabilities($workspaceId, false);
        $liabilitiesTotal = round($loansTotal + $otherLiabilities, 2);
        $totalAssets = round($bankTotal + $assetsTotal + $receivablesTotal, 2);
        $businessValue = round($totalAssets - $liabilitiesTotal, 2);
        $setup = $this->setupRow($workspaceId) ?? [];

        return [
            'bank_total' => $bankTotal,
            'assets_total' => $assetsTotal,
            'receivables_total' => $receivablesTotal,
            'total_assets' => $totalAssets,
            'loans_total' => $loansTotal,
            'other_liabilities_total' => $otherLiabilities,
            'liabilities_total' => $liabilitiesTotal,
            'business_value' => $businessValue,
            'opening_equity' => max($businessValue, 0.0),
            'reviewed_tabs' => $this->reviewedTabs($setup),
            'missing_tabs' => $this->missingTabs($setup),
            'all_tabs_reviewed' => $this->missingTabs($setup) === [],
            'row_counts' => [
                'bank' => count($this->bankRows($workspaceId, false)),
                'assets' => count($this->assetRows($workspaceId)),
                'receivables' => count($this->receivableRows($workspaceId)),
                'liabilities' => count($this->liabilityRows($workspaceId)),
            ],
        ];
    }

    public function openingBankRowsForStatement(int $workspaceId, ?int $cashAccountId = null): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_opening_setups') || !Database::tableExists('finance_opening_bank_balances')) {
            return [];
        }
        $setup = $this->setupRow($workspaceId);
        if ($setup === null || empty($setup['is_complete'])) {
            return [];
        }

        $where = ['b.workspace_id = ?'];
        $params = [$workspaceId];
        if ($cashAccountId !== null && $cashAccountId > 0) {
            $where[] = 'b.cash_account_id = ?';
            $params[] = $cashAccountId;
        }

        $rows = Database::query(
            "SELECT
                b.*,
                a.name AS cash_account_name,
                a.currency,
                s.opening_date
             FROM finance_opening_bank_balances b
             JOIN finance_cash_accounts a ON a.id = b.cash_account_id AND a.workspace_id = b.workspace_id
             JOIN finance_opening_setups s ON s.workspace_id = b.workspace_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY a.is_default DESC, a.name ASC",
            $params
        );

        return array_map(static function (array $row): array {
            $row['opening_balance'] = round((float) ($row['opening_balance'] ?? 0), 2);
            return $row;
        }, $rows);
    }

    public function hasCompletedDetailedBankSetup(int $workspaceId): bool
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_opening_setups') || !Database::tableExists('finance_opening_bank_balances')) {
            return false;
        }
        $setup = $this->setupRow($workspaceId);
        if ($setup === null || empty($setup['is_complete'])) {
            return false;
        }
        $count = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM finance_opening_bank_balances WHERE workspace_id = ?",
            [$workspaceId]
        )['c'] ?? 0);

        return $count > 0;
    }

    private function saveStart(int $workspaceId, array $data, int $userId): void
    {
        $currency = $this->sanitizeCurrency((string) ($data['currency'] ?? $this->defaultCurrencyCode()));
        $date = $this->normalizeDate((string) ($data['opening_date'] ?? ''), date('Y-m-d'));
        $notes = $this->sanitizeText((string) ($data['notes'] ?? ''), 2000);

        Database::execute(
            "UPDATE finance_opening_setups
             SET opening_date = ?, currency = ?, notes = ?, updated_by = ?, updated_at = NOW()
             WHERE workspace_id = ?",
            [$date, $currency, $notes !== '' ? $notes : null, $userId > 0 ? $userId : null, $workspaceId]
        );
    }

    private function saveBank(int $workspaceId, array $data, int $userId): void
    {
        $currency = $this->sanitizeCurrency((string) ($data['currency'] ?? $this->setupCurrency($workspaceId)));
        $rows = (array) ($data['bank_accounts'] ?? []);
        $normalized = [];
        $defaultSet = false;
        foreach ($rows as $row) {
            $row = (array) $row;
            $name = $this->sanitizeText((string) ($row['name'] ?? ''), 140);
            $amount = $this->amount($row['opening_balance'] ?? 0);
            if ($name === '' && $amount <= 0) {
                continue;
            }
            if ($name === '') {
                throw new \InvalidArgumentException('Account name required.');
            }
            $isDefault = !empty($row['is_default']) || !$defaultSet;
            $defaultSet = $defaultSet || $isDefault;
            $accountId = $this->cashAccounts->saveAccount($workspaceId, [
                'id' => (int) ($row['cash_account_id'] ?? $row['id'] ?? 0),
                'name' => $name,
                'account_type' => $this->normalizeCashAccountType((string) ($row['account_type'] ?? 'bank')),
                'currency' => $currency,
                'is_default' => $isDefault ? 1 : 0,
                'is_active' => 1,
            ], $userId);
            $normalized[] = [
                'cash_account_id' => $accountId,
                'opening_balance' => $amount,
                'note' => $this->sanitizeText((string) ($row['note'] ?? ''), 255),
            ];
        }

        if ($normalized === []) {
            $this->cashAccounts->ensureDefaultAccount($workspaceId, $userId);
        }

        Database::execute("DELETE FROM finance_opening_bank_balances WHERE workspace_id = ?", [$workspaceId]);
        foreach ($normalized as $row) {
            Database::execute(
                "INSERT INTO finance_opening_bank_balances (workspace_id, cash_account_id, opening_balance, note)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    opening_balance = VALUES(opening_balance),
                    note = VALUES(note),
                    updated_at = NOW()",
                [
                    $workspaceId,
                    (int) $row['cash_account_id'],
                    (float) $row['opening_balance'],
                    $row['note'] !== '' ? (string) $row['note'] : null,
                ]
            );
        }
    }

    private function saveOwners(int $workspaceId, array $data, int $userId): void
    {
        $currency = $this->setupCurrency($workspaceId);
        $profiles = [];
        foreach ((array) ($data['owner_equity'] ?? []) as $ownerUserId => $profile) {
            $profile = (array) $profile;
            $profiles[] = [
                'user_id' => (int) ($profile['user_id'] ?? $ownerUserId),
                'ownership_percent' => $profile['ownership_percent'] ?? 0,
                'opening_owner_capital' => $profile['opening_owner_capital'] ?? 0,
                'opening_owner_draws' => $profile['opening_owner_draws'] ?? 0,
                'currency' => $currency,
                'notes' => (string) ($profile['notes'] ?? ''),
            ];
        }
        $this->ownerEquity->saveProfiles($workspaceId, $profiles, $userId);
    }

    private function completeReview(int $workspaceId, int $userId): void
    {
        $setup = $this->setupRow($workspaceId);
        if ($setup === null) {
            throw new \InvalidArgumentException('Start setup first.');
        }
        $missing = $this->missingTabs($setup);
        if ($missing !== []) {
            throw new \InvalidArgumentException('Finish every setup tab before Review.');
        }
        $this->assertOwnersReady($workspaceId);

        $summary = $this->summary($workspaceId);
        $payload = [
            'transaction_type' => 'opening_balance',
            'transaction_date' => (string) ($setup['opening_date'] ?? date('Y-m-d')),
            'amount' => (float) ($summary['bank_total'] ?? 0),
            'currency' => (string) ($setup['currency'] ?? $this->defaultCurrencyCode()),
            'counterparty' => 'Opening setup',
            'memo' => (string) ($setup['notes'] ?? ''),
            'opening_cash' => (float) ($summary['bank_total'] ?? 0),
            'opening_receivables' => (float) ($summary['receivables_total'] ?? 0),
            'opening_payables' => (float) ($summary['other_liabilities_total'] ?? 0),
            'opening_loan_balance' => (float) ($summary['loans_total'] ?? 0),
            'opening_assets' => (float) ($summary['assets_total'] ?? 0),
            'opening_equity' => (float) ($summary['opening_equity'] ?? 0),
            'opening_setup_complete' => 1,
        ];

        $existingId = (int) ($setup['opening_transaction_id'] ?? 0);
        if ($existingId <= 0) {
            $existingId = $this->latestOpeningBalanceId($workspaceId);
        }
        $transactionId = $existingId > 0
            ? $this->ledger->replaceGuidedTransaction($workspaceId, $existingId, $payload, $userId)
            : $this->ledger->saveGuidedTransaction($workspaceId, $payload, $userId);

        Database::execute(
            "UPDATE finance_opening_setups
             SET is_complete = 1,
                 completed_at = NOW(),
                 opening_transaction_id = ?,
                 updated_by = ?,
                 updated_at = NOW()
             WHERE workspace_id = ?",
            [$transactionId, $userId > 0 ? $userId : null, $workspaceId]
        );
    }

    private function replaceSimpleRows(int $workspaceId, string $table, array $rows): void
    {
        Database::execute("DELETE FROM {$table} WHERE workspace_id = ?", [$workspaceId]);
        foreach ($rows as $row) {
            if ($table === 'finance_opening_assets') {
                Database::execute(
                    "INSERT INTO finance_opening_assets (workspace_id, name, asset_type, value, note)
                     VALUES (?, ?, ?, ?, ?)",
                    [$workspaceId, $row['name'], $row['asset_type'], $row['value'], $row['note'] ?: null]
                );
            } elseif ($table === 'finance_opening_receivables') {
                Database::execute(
                    "INSERT INTO finance_opening_receivables (workspace_id, from_name, amount, due_date, note)
                     VALUES (?, ?, ?, ?, ?)",
                    [$workspaceId, $row['from_name'], $row['amount'], $row['due_date'], $row['note'] ?: null]
                );
            } elseif ($table === 'finance_opening_liabilities') {
                Database::execute(
                    "INSERT INTO finance_opening_liabilities (workspace_id, name, liability_type, amount, due_date, interest_rate, note)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$workspaceId, $row['name'], $row['liability_type'], $row['amount'], $row['due_date'], $row['interest_rate'], $row['note'] ?: null]
                );
            }
        }
    }

    private function applyReviewConfirmations(int $workspaceId, array $data, int $userId): void
    {
        if (!empty($data['start_confirmed'])) {
            $this->markReviewed($workspaceId, 'start', $userId, false);
        }

        foreach ([
            'assets' => ['field' => 'assets_none', 'rows' => $this->assetRows($workspaceId), 'table' => 'finance_opening_assets'],
            'receivables' => ['field' => 'receivables_none', 'rows' => $this->receivableRows($workspaceId), 'table' => 'finance_opening_receivables'],
            'liabilities' => ['field' => 'liabilities_none', 'rows' => $this->liabilityRows($workspaceId), 'table' => 'finance_opening_liabilities'],
        ] as $tab => $config) {
            if (empty($data[(string) $config['field']])) {
                continue;
            }
            if ((array) $config['rows'] !== []) {
                continue;
            }
            $this->replaceSimpleRows($workspaceId, (string) $config['table'], []);
            $this->markReviewed($workspaceId, (string) $tab, $userId, false);
        }
    }

    private function normalizeAssets(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $name = $this->sanitizeText((string) ($row['name'] ?? ''), 180);
            $value = $this->amount($row['value'] ?? 0);
            if ($name === '' && $value <= 0) {
                continue;
            }
            if ($name === '') {
                throw new \InvalidArgumentException('Asset name required.');
            }
            $out[] = [
                'name' => $name,
                'asset_type' => $this->normalizeAssetType((string) ($row['asset_type'] ?? 'other')),
                'value' => $value,
                'note' => $this->sanitizeText((string) ($row['note'] ?? ''), 255),
            ];
        }
        return $out;
    }

    private function normalizeReceivables(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $name = $this->sanitizeText((string) ($row['from_name'] ?? ''), 180);
            $amount = $this->amount($row['amount'] ?? 0);
            if ($name === '' && $amount <= 0) {
                continue;
            }
            if ($name === '') {
                throw new \InvalidArgumentException('Name required.');
            }
            $out[] = [
                'from_name' => $name,
                'amount' => $amount,
                'due_date' => $this->normalizeOptionalDate((string) ($row['due_date'] ?? '')),
                'note' => $this->sanitizeText((string) ($row['note'] ?? ''), 255),
            ];
        }
        return $out;
    }

    private function normalizeLiabilities(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $name = $this->sanitizeText((string) ($row['name'] ?? ''), 180);
            $amount = $this->amount($row['amount'] ?? 0);
            if ($name === '' && $amount <= 0) {
                continue;
            }
            if ($name === '') {
                throw new \InvalidArgumentException('Name required.');
            }
            $out[] = [
                'name' => $name,
                'liability_type' => $this->normalizeLiabilityType((string) ($row['liability_type'] ?? 'other')),
                'amount' => $amount,
                'due_date' => $this->normalizeOptionalDate((string) ($row['due_date'] ?? '')),
                'interest_rate' => trim((string) ($row['interest_rate'] ?? '')) === '' ? null : $this->percent($row['interest_rate']),
                'note' => $this->sanitizeText((string) ($row['note'] ?? ''), 255),
            ];
        }
        return $out;
    }

    private function ownerRows(int $workspaceId, array $summary): array
    {
        $owners = $this->ownerEquity->activeOwners($workspaceId);
        $profiles = [];
        foreach ($this->ownerEquity->profiles($workspaceId) as $profile) {
            $profiles[(int) ($profile['user_id'] ?? 0)] = $profile;
        }
        $businessValue = (float) ($summary['business_value'] ?? 0);
        foreach ($owners as &$owner) {
            $profile = (array) ($profiles[(int) ($owner['user_id'] ?? 0)] ?? []);
            $percent = (float) ($profile['ownership_percent'] ?? (count($owners) === 1 ? 100 : 0));
            $owner['ownership_percent'] = (string) $percent;
            $owner['opening_owner_capital'] = (string) ($profile['opening_owner_capital'] ?? '0.00');
            $owner['opening_owner_draws'] = (string) ($profile['opening_owner_draws'] ?? '0.00');
            $owner['notes'] = (string) ($profile['notes'] ?? '');
            $owner['owner_value'] = round($businessValue * ($percent / 100), 2);
        }
        unset($owner);

        return $owners;
    }

    private function assertOwnersReady(int $workspaceId): void
    {
        $owners = $this->ownerEquity->activeOwners($workspaceId);
        if ($owners === []) {
            throw new \InvalidArgumentException('Add an owner first.');
        }
        $profiles = $this->ownerEquity->profiles($workspaceId);
        if (count($profiles) < count($owners)) {
            throw new \InvalidArgumentException('Save owner shares first.');
        }
        $total = round(array_sum(array_map(static fn(array $profile): float => (float) ($profile['ownership_percent'] ?? 0), $profiles)), 4);
        if (abs($total - 100.0) > 0.0001) {
            throw new \InvalidArgumentException('Owner shares must total 100%.');
        }
    }

    private function ensureSetup(int $workspaceId, array $data = [], int $userId = 0): void
    {
        $existing = $this->setupRow($workspaceId);
        if ($existing !== null) {
            return;
        }
        Database::execute(
            "INSERT INTO finance_opening_setups (workspace_id, opening_date, currency, notes, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $this->normalizeDate((string) ($data['opening_date'] ?? ''), date('Y-m-d')),
                $this->sanitizeCurrency((string) ($data['currency'] ?? $this->defaultCurrencyCode())),
                $this->sanitizeText((string) ($data['notes'] ?? ''), 2000) ?: null,
                $userId > 0 ? $userId : null,
                $userId > 0 ? $userId : null,
            ]
        );
    }

    private function setupRow(int $workspaceId): ?array
    {
        if (!Database::tableExists('finance_opening_setups')) {
            return null;
        }
        return Database::queryOne(
            "SELECT * FROM finance_opening_setups WHERE workspace_id = ? LIMIT 1",
            [$workspaceId]
        );
    }

    private function defaultSetup(): array
    {
        return [
            'opening_date' => date('Y-m-d'),
            'currency' => $this->defaultCurrencyCode(),
            'notes' => '',
            'start_reviewed' => 0,
            'bank_reviewed' => 0,
            'assets_reviewed' => 0,
            'receivables_reviewed' => 0,
            'liabilities_reviewed' => 0,
            'owners_reviewed' => 0,
            'is_complete' => 0,
        ];
    }

    private function bankRows(int $workspaceId, bool $fallbackToAccounts = true): array
    {
        if (!Database::tableExists('finance_opening_bank_balances')) {
            return [];
        }
        $rows = Database::query(
            "SELECT
                b.*,
                a.name,
                a.account_type,
                a.currency,
                a.is_default
             FROM finance_opening_bank_balances b
             JOIN finance_cash_accounts a ON a.id = b.cash_account_id AND a.workspace_id = b.workspace_id
             WHERE b.workspace_id = ?
             ORDER BY a.is_default DESC, a.name ASC",
            [$workspaceId]
        );
        if ($rows === [] && $fallbackToAccounts) {
            $accounts = $this->cashAccounts->listAccounts($workspaceId);
            foreach ($accounts as $account) {
                $rows[] = [
                    'cash_account_id' => (int) ($account['id'] ?? 0),
                    'name' => (string) ($account['name'] ?? ''),
                    'account_type' => (string) ($account['account_type'] ?? 'bank'),
                    'currency' => (string) ($account['currency'] ?? $this->setupCurrency($workspaceId)),
                    'is_default' => (int) ($account['is_default'] ?? 0),
                    'opening_balance' => '0.00',
                    'note' => '',
                ];
            }
        }
        return $rows;
    }

    private function assetRows(int $workspaceId): array
    {
        return Database::tableExists('finance_opening_assets')
            ? Database::query("SELECT * FROM finance_opening_assets WHERE workspace_id = ? ORDER BY id ASC", [$workspaceId])
            : [];
    }

    private function receivableRows(int $workspaceId): array
    {
        return Database::tableExists('finance_opening_receivables')
            ? Database::query("SELECT * FROM finance_opening_receivables WHERE workspace_id = ? ORDER BY id ASC", [$workspaceId])
            : [];
    }

    private function liabilityRows(int $workspaceId): array
    {
        return Database::tableExists('finance_opening_liabilities')
            ? Database::query("SELECT * FROM finance_opening_liabilities WHERE workspace_id = ? ORDER BY liability_type ASC, id ASC", [$workspaceId])
            : [];
    }

    private function markReviewed(int $workspaceId, string $tab, int $userId, bool $resetComplete): void
    {
        $columns = [
            'start' => 'start_reviewed',
            'bank' => 'bank_reviewed',
            'assets' => 'assets_reviewed',
            'receivables' => 'receivables_reviewed',
            'liabilities' => 'liabilities_reviewed',
            'owners' => 'owners_reviewed',
        ];
        $column = $columns[$tab] ?? null;
        if ($column === null) {
            return;
        }
        $completeSql = $resetComplete ? ', is_complete = 0, completed_at = NULL' : '';
        Database::execute(
            "UPDATE finance_opening_setups
             SET {$column} = 1,
                 updated_by = ?,
                 updated_at = NOW()
                 {$completeSql}
             WHERE workspace_id = ?",
            [$userId > 0 ? $userId : null, $workspaceId]
        );
    }

    private function missingTabs(array $setup): array
    {
        if ($setup === []) {
            return self::REQUIRED_TABS;
        }
        $missing = [];
        foreach (self::REQUIRED_TABS as $tab) {
            if (empty($setup[$tab . '_reviewed'])) {
                $missing[] = $tab;
            }
        }
        return $missing;
    }

    private function reviewedTabs(array $setup): array
    {
        $reviewed = [];
        foreach (self::REQUIRED_TABS as $tab) {
            if (!empty($setup[$tab . '_reviewed'])) {
                $reviewed[] = $tab;
            }
        }
        return $reviewed;
    }

    private function latestOpeningBalance(int $workspaceId): ?array
    {
        if (!Database::tableExists('finance_transactions')) {
            return null;
        }
        $row = Database::queryOne(
            "SELECT id
             FROM finance_transactions
             WHERE workspace_id = ? AND transaction_type = 'opening_balance'
             ORDER BY transaction_date DESC, id DESC
             LIMIT 1",
            [$workspaceId]
        );
        $id = (int) ($row['id'] ?? 0);
        return $id > 0 ? $this->ledger->getTransaction($workspaceId, $id) : null;
    }

    private function latestOpeningBalanceId(int $workspaceId): int
    {
        $transaction = $this->latestOpeningBalance($workspaceId);
        return (int) ($transaction['id'] ?? 0);
    }

    private function sumTable(int $workspaceId, string $table, string $column): float
    {
        if (!Database::tableExists($table)) {
            return 0.0;
        }
        $row = Database::queryOne(
            "SELECT COALESCE(SUM({$column}), 0) AS total FROM {$table} WHERE workspace_id = ?",
            [$workspaceId]
        );
        return round((float) ($row['total'] ?? 0), 2);
    }

    private function sumLiabilities(int $workspaceId, bool $loansOnly): float
    {
        if (!Database::tableExists('finance_opening_liabilities')) {
            return 0.0;
        }
        $where = $loansOnly ? "liability_type = 'loan'" : "liability_type <> 'loan'";
        $row = Database::queryOne(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM finance_opening_liabilities
             WHERE workspace_id = ? AND {$where}",
            [$workspaceId]
        );
        return round((float) ($row['total'] ?? 0), 2);
    }

    private function setupCurrency(int $workspaceId): string
    {
        $setup = $this->setupRow($workspaceId);
        return $this->sanitizeCurrency((string) ($setup['currency'] ?? $this->defaultCurrencyCode()));
    }

    private function normalizeTab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        $aliases = [
            'setup' => 'start',
            'opening' => 'start',
            'accounts' => 'bank',
            'owed_to_us' => 'receivables',
            'owed_by_us' => 'liabilities',
            'capital' => 'owners',
            'ownership' => 'owners',
        ];
        $tab = $aliases[$tab] ?? $tab;
        $valid = array_merge(self::REQUIRED_TABS, ['review']);
        return in_array($tab, $valid, true) ? $tab : 'start';
    }

    private function tabLabel(string $tab): string
    {
        return [
            'start' => 'Start',
            'bank' => 'Bank',
            'assets' => 'Assets',
            'receivables' => 'Owed To Us',
            'liabilities' => 'Owed By Us',
            'owners' => 'Owners',
        ][$tab] ?? $tab;
    }

    private function normalizeCashAccountType(string $value): string
    {
        return in_array($value, ['bank', 'cash', 'mobile_money', 'other'], true) ? $value : 'bank';
    }

    private function normalizeAssetType(string $value): string
    {
        return in_array($value, ['equipment', 'vehicle', 'furniture', 'property', 'software', 'other'], true) ? $value : 'other';
    }

    private function normalizeLiabilityType(string $value): string
    {
        return in_array($value, ['loan', 'supplier_bill', 'tax', 'other'], true) ? $value : 'other';
    }

    private function normalizeDate(string $value, string $fallback): string
    {
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : $fallback;
    }

    private function normalizeOptionalDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    private function amount(mixed $value): float
    {
        return round(max(0, min((float) $value, 999999999.99)), 2);
    }

    private function percent(mixed $value): float
    {
        return round(max(0, min((float) $value, 100.0)), 4);
    }

    private function sanitizeCurrency(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z]{3,10}$/', $value) ? $value : $this->defaultCurrencyCode();
    }

    private function sanitizeText(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');
        return mb_substr($value, 0, $max);
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
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
        }

        return 'USD';
    }
}
