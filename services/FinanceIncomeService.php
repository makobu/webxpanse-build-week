<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Currencies;

class FinanceIncomeService
{
    private WorkspaceScopeService $workspaceScope;
    private FinanceCashAccountService $cashAccounts;

    public function __construct(
        ?WorkspaceScopeService $workspaceScope = null,
        ?FinanceCashAccountService $cashAccounts = null
    ) {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
        $this->cashAccounts = $cashAccounts ?? new FinanceCashAccountService($this->workspaceScope);
    }

    public function listEntries(int $workspaceId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_income_entries')) {
            return [];
        }
        $this->cashAccounts->ensureDefaultAccount($workspaceId);
        $this->seedDefaultCategories($workspaceId);

        $where = ['i.workspace_id = ?'];
        $params = [$workspaceId];
        if (!empty($filters['date_from'])) {
            $where[] = 'i.income_date >= ?';
            $params[] = $this->normalizeDate((string) $filters['date_from'], date('Y-m-01'));
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'i.income_date <= ?';
            $params[] = $this->normalizeDate((string) $filters['date_to'], date('Y-m-t'));
        }
        if (!empty($filters['cash_account_id'])) {
            $where[] = 'i.cash_account_id = ?';
            $params[] = (int) $filters['cash_account_id'];
        }
        if (!empty($filters['income_type'])) {
            $where[] = 'i.income_type = ?';
            $params[] = $this->normalizeIncomeType((string) $filters['income_type']);
        }

        return Database::query(
            "SELECT i.*, a.name AS cash_account_name, c.name AS income_category_name
             FROM finance_income_entries i
             LEFT JOIN finance_cash_accounts a ON a.id = i.cash_account_id AND a.workspace_id = i.workspace_id
             LEFT JOIN finance_income_categories c ON c.id = i.income_category_id AND c.workspace_id = i.workspace_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY i.income_date DESC, i.id DESC
             LIMIT " . max(1, (int) $limit) . " OFFSET " . max(0, (int) $offset),
            $params
        );
    }

    public function getEntry(int $workspaceId, int $entryId): ?array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if ($entryId <= 0 || !Database::tableExists('finance_income_entries')) {
            return null;
        }

        $this->seedDefaultCategories($workspaceId);

        return Database::queryOne(
            "SELECT i.*, a.name AS cash_account_name, c.name AS income_category_name
             FROM finance_income_entries i
             LEFT JOIN finance_cash_accounts a ON a.id = i.cash_account_id AND a.workspace_id = i.workspace_id
             LEFT JOIN finance_income_categories c ON c.id = i.income_category_id AND c.workspace_id = i.workspace_id
             WHERE i.workspace_id = ? AND i.id = ?
             LIMIT 1",
            [$workspaceId, $entryId]
        );
    }

    public function saveEntry(int $workspaceId, array $data, int $userId = 0): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_income_entries')) {
            throw new \RuntimeException('Finance income tables are not installed.');
        }

        $entryId = (int) ($data['id'] ?? 0);
        $cashAccountId = $this->cashAccounts->resolveCashAccountId($workspaceId, $data['cash_account_id'] ?? 0);
        $incomeType = $this->normalizeIncomeType((string) ($data['income_type'] ?? 'sale'));
        $incomeCategoryId = $this->resolveCategoryId($workspaceId, $data['income_category_id'] ?? 0, $incomeType);
        if ($incomeCategoryId !== null) {
            $incomeType = $this->categoryIncomeType($workspaceId, $incomeCategoryId) ?: $incomeType;
        }
        $payload = [
            'cash_account_id' => $cashAccountId,
            'income_type' => $incomeType,
            'income_category_id' => $incomeCategoryId,
            'source' => $this->sanitizeText((string) ($data['source'] ?? ''), 180),
            'description' => $this->sanitizeText((string) ($data['description'] ?? ''), 255),
            'amount' => $this->amount($data['amount'] ?? 0),
            'currency' => $this->sanitizeCurrency((string) ($data['currency'] ?? $this->defaultCurrencyCode())),
            'income_date' => $this->normalizeDate((string) ($data['income_date'] ?? ''), date('Y-m-d')),
            'linked_invoice_id' => !empty($data['linked_invoice_id']) ? (int) $data['linked_invoice_id'] : null,
            'notes' => $this->sanitizeText((string) ($data['notes'] ?? ''), 2000),
        ];
        if ($payload['description'] === '') {
            throw new \InvalidArgumentException('Description required.');
        }
        if ($payload['amount'] <= 0) {
            throw new \InvalidArgumentException('Amount required.');
        }

        if ($entryId > 0 && $this->getEntry($workspaceId, $entryId)) {
            Database::execute(
                "UPDATE finance_income_entries
                 SET cash_account_id = ?, income_type = ?, income_category_id = ?, source = ?, description = ?, amount = ?,
                     currency = ?, income_date = ?, linked_invoice_id = ?, notes = ?
                 WHERE workspace_id = ? AND id = ?",
                [
                    $payload['cash_account_id'],
                    $payload['income_type'],
                    $payload['income_category_id'],
                    $payload['source'] ?: null,
                    $payload['description'],
                    $payload['amount'],
                    $payload['currency'],
                    $payload['income_date'],
                    $payload['linked_invoice_id'],
                    $payload['notes'] ?: null,
                    $workspaceId,
                    $entryId,
                ]
            );
            return $entryId;
        }

        Database::execute(
            "INSERT INTO finance_income_entries (
                workspace_id, cash_account_id, income_type, income_category_id, source, description, amount,
                currency, income_date, linked_invoice_id, notes, created_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $payload['cash_account_id'],
                $payload['income_type'],
                $payload['income_category_id'],
                $payload['source'] ?: null,
                $payload['description'],
                $payload['amount'],
                $payload['currency'],
                $payload['income_date'],
                $payload['linked_invoice_id'],
                $payload['notes'] ?: null,
                $userId > 0 ? $userId : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function listCategories(int $workspaceId = 0): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        if (!Database::tableExists('finance_income_categories')) {
            return [];
        }
        $this->seedDefaultCategories($workspaceId);

        return Database::query(
            "SELECT *
             FROM finance_income_categories
             WHERE workspace_id = ?
             ORDER BY is_default DESC, income_type ASC, name ASC",
            [$workspaceId]
        );
    }

    public function saveCategory(int $workspaceId, string $name, string $incomeType = 'sale', int $userId = 0): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_income_categories')) {
            throw new \RuntimeException('Finance income categories are not installed.');
        }
        $name = $this->sanitizeText($name, 120);
        if ($name === '') {
            throw new \InvalidArgumentException('Label required.');
        }
        $incomeType = $this->normalizeIncomeType($incomeType);
        Database::execute(
            "INSERT INTO finance_income_categories (workspace_id, name, income_type, is_default, is_active, created_by)
             VALUES (?, ?, ?, 0, 1, ?)
             ON DUPLICATE KEY UPDATE
                income_type = VALUES(income_type),
                is_active = 1,
                updated_at = NOW()",
            [$workspaceId, $name, $incomeType, $userId > 0 ? $userId : null]
        );

        $row = Database::queryOne(
            "SELECT id FROM finance_income_categories WHERE workspace_id = ? AND name = ? LIMIT 1",
            [$workspaceId, $name]
        );

        return (int) ($row['id'] ?? 0);
    }

    public function deleteEntry(int $workspaceId, int $entryId): void
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        Database::execute(
            "DELETE FROM finance_income_entries WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $entryId]
        );
    }

    public function summary(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_income_entries')) {
            return ['sales' => 0.0, 'other_income' => 0.0, 'total' => 0.0, 'count' => 0];
        }

        $row = Database::queryOne(
            "SELECT
                COALESCE(SUM(CASE WHEN income_type = 'sale' THEN amount ELSE 0 END), 0) AS sales,
                COALESCE(SUM(CASE WHEN income_type = 'other_income' THEN amount ELSE 0 END), 0) AS other_income,
                COALESCE(SUM(amount), 0) AS total,
                COUNT(*) AS c
             FROM finance_income_entries
             WHERE workspace_id = ?
               AND income_date BETWEEN ? AND ?",
            [$workspaceId, $dateFrom, $dateTo]
        ) ?: [];

        return [
            'sales' => round((float) ($row['sales'] ?? 0), 2),
            'other_income' => round((float) ($row['other_income'] ?? 0), 2),
            'total' => round((float) ($row['total'] ?? 0), 2),
            'count' => (int) ($row['c'] ?? 0),
        ];
    }

    public function sourceRows(int $workspaceId, string $dateFrom, string $dateTo, ?int $cashAccountId = null): array
    {
        $filters = ['date_from' => $dateFrom, 'date_to' => $dateTo];
        if ($cashAccountId !== null && $cashAccountId > 0) {
            $filters['cash_account_id'] = $cashAccountId;
        }
        return $this->listEntries($workspaceId, $filters, 10000, 0);
    }

    public function seedDefaultCategories(int $workspaceId): void
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('finance_income_categories')) {
            return;
        }
        foreach ([
            ['Sales', 'sale', 1],
            ['Service income', 'sale', 0],
            ['Product sales', 'sale', 0],
            ['Other income', 'other_income', 1],
        ] as [$name, $type, $isDefault]) {
            Database::execute(
                "INSERT INTO finance_income_categories (workspace_id, name, income_type, is_default, is_active)
                 VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    income_type = VALUES(income_type),
                    is_default = VALUES(is_default),
                    is_active = 1",
                [$workspaceId, $name, $type, $isDefault]
            );
        }
    }

    private function normalizeIncomeType(string $value): string
    {
        return in_array($value, ['sale', 'other_income'], true) ? $value : 'sale';
    }

    private function resolveCategoryId(int $workspaceId, mixed $categoryId, string $incomeType): ?int
    {
        if (!Database::tableExists('finance_income_categories')) {
            return null;
        }
        $this->seedDefaultCategories($workspaceId);
        $categoryId = (int) $categoryId;
        if ($categoryId > 0) {
            $row = Database::queryOne(
                "SELECT id, income_type FROM finance_income_categories
                 WHERE workspace_id = ? AND id = ? AND is_active = 1
                 LIMIT 1",
                [$workspaceId, $categoryId]
            );
            if (!$row) {
                throw new \InvalidArgumentException('Income label not found.');
            }
            return (int) $row['id'];
        }

        $defaultName = $incomeType === 'other_income' ? 'Other income' : 'Sales';
        $row = Database::queryOne(
            "SELECT id FROM finance_income_categories
             WHERE workspace_id = ? AND name = ?
             LIMIT 1",
            [$workspaceId, $defaultName]
        );
        return isset($row['id']) ? (int) $row['id'] : null;
    }

    private function categoryIncomeType(int $workspaceId, int $categoryId): ?string
    {
        $row = Database::queryOne(
            "SELECT income_type FROM finance_income_categories
             WHERE workspace_id = ? AND id = ?
             LIMIT 1",
            [$workspaceId, $categoryId]
        );
        return isset($row['income_type']) ? $this->normalizeIncomeType((string) $row['income_type']) : null;
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
