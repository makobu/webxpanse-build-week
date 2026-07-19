<?php

namespace CRM\Services;

use CRM\Database;

class FinanceExpenseService
{
    private WorkspaceScopeService $workspaceScope;
    private FinanceCashAccountService $cashAccounts;

    public function __construct(?WorkspaceScopeService $workspaceScope = null, ?FinanceCashAccountService $cashAccounts = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
        $this->cashAccounts = $cashAccounts ?? new FinanceCashAccountService($this->workspaceScope);
    }

    public function listCategories(int $workspaceId = 0): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        $this->seedDefaultCategories($workspaceId);

        return Database::query(
            "SELECT *
             FROM finance_expense_categories
             WHERE workspace_id = ?
             ORDER BY is_default DESC, name ASC",
            [$workspaceId]
        );
    }

    public function saveCategory(int $workspaceId, string $name, string $type = 'other', int $userId = 0, array $metadata = []): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $name = $this->sanitizeText($name, 120);
        if ($name === '') {
            throw new \InvalidArgumentException('Category name is required.');
        }
        $type = $this->normalizeCategoryType($type);
        $statementGroup = $this->normalizeStatementGroup((string) ($metadata['statement_group'] ?? $this->defaultStatementGroup($type)));
        $cashFlowGroup = $this->normalizeCashFlowGroup((string) ($metadata['cash_flow_group'] ?? 'operating'));
        $profitTreatment = $this->normalizeProfitTreatment((string) ($metadata['profit_treatment'] ?? 'normal'));
        $isDeductible = array_key_exists('is_deductible', $metadata) ? (!empty($metadata['is_deductible']) ? 1 : 0) : 1;
        $isCogs = array_key_exists('is_cogs', $metadata) ? (!empty($metadata['is_cogs']) ? 1 : 0) : ($statementGroup === 'cost_of_sales' ? 1 : 0);

        Database::execute(
            "INSERT INTO finance_expense_categories (
                workspace_id, name, category_type, statement_group, cash_flow_group, profit_treatment, is_deductible, is_cogs, created_by
             )
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                category_type = VALUES(category_type),
                statement_group = VALUES(statement_group),
                cash_flow_group = VALUES(cash_flow_group),
                profit_treatment = VALUES(profit_treatment),
                is_deductible = VALUES(is_deductible),
                is_cogs = VALUES(is_cogs),
                updated_at = NOW()",
            [$workspaceId, $name, $type, $statementGroup, $cashFlowGroup, $profitTreatment, $isDeductible, $isCogs, $userId > 0 ? $userId : null]
        );

        $row = Database::queryOne(
            "SELECT id FROM finance_expense_categories WHERE workspace_id = ? AND name = ? LIMIT 1",
            [$workspaceId, $name]
        );

        return (int) ($row['id'] ?? 0);
    }

    public function listVendors(int $workspaceId = 0): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);

        return Database::query(
            "SELECT *
             FROM finance_vendors
             WHERE workspace_id = ?
             ORDER BY is_active DESC, name ASC",
            [$workspaceId]
        );
    }

    public function resolveVendor(int $workspaceId, string $vendorName, int $userId = 0): ?int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $vendorName = $this->sanitizeText($vendorName, 180);
        if ($vendorName === '') {
            return null;
        }

        Database::execute(
            "INSERT INTO finance_vendors (workspace_id, name, created_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                is_active = TRUE,
                updated_at = NOW()",
            [$workspaceId, $vendorName, $userId > 0 ? $userId : null]
        );

        $row = Database::queryOne(
            "SELECT id FROM finance_vendors WHERE workspace_id = ? AND name = ? LIMIT 1",
            [$workspaceId, $vendorName]
        );

        return isset($row['id']) ? (int) $row['id'] : null;
    }

    public function listExpenses(int $workspaceId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $where = ['e.workspace_id = ?'];
        $params = [$workspaceId];

        if (!empty($filters['date_from'])) {
            $where[] = 'e.expense_date >= ?';
            $params[] = $this->normalizeDate((string) $filters['date_from'], date('Y-m-01'));
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'e.expense_date <= ?';
            $params[] = $this->normalizeDate((string) $filters['date_to'], date('Y-m-t'));
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'e.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }
        if (!empty($filters['cash_account_id'])) {
            $where[] = 'e.cash_account_id = ?';
            $params[] = (int) $filters['cash_account_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'e.status = ?';
            $params[] = $this->normalizeExpenseStatus((string) $filters['status']);
        }
        if (!empty($filters['search'])) {
            $where[] = '(COALESCE(v.name, e.vendor) LIKE ? OR e.description LIKE ? OR e.notes LIKE ?)';
            $like = '%' . trim((string) $filters['search']) . '%';
            array_push($params, $like, $like, $like);
        }

        $sql = "SELECT e.*, COALESCE(v.name, e.vendor) AS vendor_name, c.name AS category_name, c.category_type,
                    a.name AS cash_account_name
                FROM finance_expenses e
                LEFT JOIN finance_cash_accounts a ON a.id = e.cash_account_id AND a.workspace_id = e.workspace_id
                LEFT JOIN finance_vendors v ON v.id = e.vendor_id AND v.workspace_id = e.workspace_id
                LEFT JOIN finance_expense_categories c ON c.id = e.category_id AND c.workspace_id = e.workspace_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY e.expense_date DESC, e.id DESC
                LIMIT " . max(1, (int) $limit) . " OFFSET " . max(0, (int) $offset);

        return Database::query($sql, $params);
    }

    public function getExpense(int $workspaceId, int $expenseId): ?array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);

        return Database::queryOne(
            "SELECT e.*, COALESCE(v.name, e.vendor) AS vendor_name, c.name AS category_name,
                a.name AS cash_account_name
             FROM finance_expenses e
             LEFT JOIN finance_cash_accounts a ON a.id = e.cash_account_id AND a.workspace_id = e.workspace_id
             LEFT JOIN finance_vendors v ON v.id = e.vendor_id AND v.workspace_id = e.workspace_id
             LEFT JOIN finance_expense_categories c ON c.id = e.category_id AND c.workspace_id = e.workspace_id
             WHERE e.workspace_id = ? AND e.id = ?
             LIMIT 1",
            [$workspaceId, $expenseId]
        );
    }

    public function saveExpense(int $workspaceId, array $data, int $userId = 0): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $expenseId = (int) ($data['id'] ?? 0);
        $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;
        if ($categoryId !== null) {
            $this->assertCategory($workspaceId, $categoryId);
        }
        $payload = [
            'cash_account_id' => $this->cashAccounts->resolveCashAccountId($workspaceId, $data['cash_account_id'] ?? 0),
            'category_id' => $categoryId,
            'vendor_id' => null,
            'vendor' => $this->sanitizeText((string) ($data['vendor'] ?? ''), 180),
            'description' => $this->sanitizeText((string) ($data['description'] ?? ''), 255),
            'amount' => $this->sanitizeAmount($data['amount'] ?? 0),
            'currency' => $this->sanitizeCurrency((string) ($data['currency'] ?? 'USD')),
            'expense_date' => $this->normalizeDate((string) ($data['expense_date'] ?? ''), date('Y-m-d')),
            'payment_method' => $this->normalizePaymentMethod((string) ($data['payment_method'] ?? 'other')),
            'status' => $this->normalizeExpenseStatus((string) ($data['status'] ?? 'paid')),
            'linked_deal_id' => !empty($data['linked_deal_id']) ? (int) $data['linked_deal_id'] : null,
            'linked_invoice_id' => !empty($data['linked_invoice_id']) ? (int) $data['linked_invoice_id'] : null,
            'receipt_path' => $this->sanitizeText((string) ($data['receipt_path'] ?? ''), 500),
            'notes' => $this->sanitizeText((string) ($data['notes'] ?? ''), 2000),
        ];

        if ($payload['description'] === '') {
            throw new \InvalidArgumentException('Expense description is required.');
        }
        if ($payload['amount'] <= 0) {
            throw new \InvalidArgumentException('Expense amount must be greater than zero.');
        }
        $payload['vendor_id'] = $this->resolveVendor($workspaceId, $payload['vendor'], $userId);
        if ($payload['vendor_id'] !== null) {
            $payload['vendor'] = $this->vendorNameById($workspaceId, (int) $payload['vendor_id']) ?: $payload['vendor'];
        }

        if ($expenseId > 0) {
            $existing = $this->getExpense($workspaceId, $expenseId);
            if (!$existing) {
                throw new \RuntimeException('Expense not found.');
            }
            Database::execute(
                "UPDATE finance_expenses SET
                    cash_account_id = ?, category_id = ?, vendor_id = ?, vendor = ?, description = ?, amount = ?, currency = ?,
                    expense_date = ?, payment_method = ?, status = ?, linked_deal_id = ?,
                    linked_invoice_id = ?, receipt_path = ?, notes = ?
                 WHERE workspace_id = ? AND id = ?",
                [
                    $payload['cash_account_id'], $payload['category_id'], $payload['vendor_id'], $payload['vendor'], $payload['description'], $payload['amount'],
                    $payload['currency'], $payload['expense_date'], $payload['payment_method'], $payload['status'],
                    $payload['linked_deal_id'], $payload['linked_invoice_id'], $payload['receipt_path'], $payload['notes'],
                    $workspaceId, $expenseId,
                ]
            );
            return $expenseId;
        }

        Database::execute(
            "INSERT INTO finance_expenses (
                workspace_id, cash_account_id, category_id, vendor_id, vendor, description, amount, currency, expense_date,
                payment_method, status, linked_deal_id, linked_invoice_id, receipt_path, notes, created_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId, $payload['cash_account_id'], $payload['category_id'], $payload['vendor_id'], $payload['vendor'], $payload['description'], $payload['amount'],
                $payload['currency'], $payload['expense_date'], $payload['payment_method'], $payload['status'],
                $payload['linked_deal_id'], $payload['linked_invoice_id'], $payload['receipt_path'], $payload['notes'],
                $userId > 0 ? $userId : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function deleteExpense(int $workspaceId, int $expenseId): void
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        Database::execute("DELETE FROM finance_expenses WHERE workspace_id = ? AND id = ?", [$workspaceId, $expenseId]);
    }

    public function listRecurringExpenses(int $workspaceId): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);

        return Database::query(
            "SELECT r.*, COALESCE(v.name, r.vendor) AS vendor_name, c.name AS category_name
             FROM finance_recurring_expenses r
             LEFT JOIN finance_vendors v ON v.id = r.vendor_id AND v.workspace_id = r.workspace_id
             LEFT JOIN finance_expense_categories c ON c.id = r.category_id AND c.workspace_id = r.workspace_id
             WHERE r.workspace_id = ?
             ORDER BY r.is_active DESC, r.next_due_date ASC, r.id DESC",
            [$workspaceId]
        );
    }

    public function saveRecurringExpense(int $workspaceId, array $data, int $userId = 0): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $recurringId = (int) ($data['id'] ?? 0);
        $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;
        if ($categoryId !== null) {
            $this->assertCategory($workspaceId, $categoryId);
        }
        $description = $this->sanitizeText((string) ($data['description'] ?? ''), 255);
        $amount = $this->sanitizeAmount($data['amount'] ?? 0);
        if ($description === '' || $amount <= 0) {
            throw new \InvalidArgumentException('Recurring expense description and amount are required.');
        }
        $vendorName = $this->sanitizeText((string) ($data['vendor'] ?? ''), 180);
        $vendorId = $this->resolveVendor($workspaceId, $vendorName, $userId);
        if ($vendorId !== null) {
            $vendorName = $this->vendorNameById($workspaceId, $vendorId) ?: $vendorName;
        }

        $payload = [
            'category_id' => $categoryId,
            'vendor_id' => $vendorId,
            'vendor' => $vendorName,
            'description' => $description,
            'amount' => $amount,
            'currency' => $this->sanitizeCurrency((string) ($data['currency'] ?? 'USD')),
            'frequency' => $this->normalizeFrequency((string) ($data['frequency'] ?? 'monthly')),
            'payment_method' => $this->normalizePaymentMethod((string) ($data['payment_method'] ?? 'other')),
            'start_date' => $this->nullableDate((string) ($data['start_date'] ?? '')),
            'next_due_date' => $this->nullableDate((string) ($data['next_due_date'] ?? '')),
            'end_date' => $this->nullableDate((string) ($data['end_date'] ?? '')),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];

        if ($recurringId > 0) {
            Database::execute(
                "UPDATE finance_recurring_expenses SET
                    category_id = ?, vendor_id = ?, vendor = ?, description = ?, amount = ?, currency = ?,
                    frequency = ?, payment_method = ?, start_date = ?, next_due_date = ?, end_date = ?, is_active = ?
                 WHERE workspace_id = ? AND id = ?",
                [
                    $payload['category_id'], $payload['vendor_id'], $payload['vendor'], $payload['description'], $payload['amount'],
                    $payload['currency'], $payload['frequency'], $payload['payment_method'], $payload['start_date'],
                    $payload['next_due_date'], $payload['end_date'], $payload['is_active'], $workspaceId, $recurringId,
                ]
            );
            return $recurringId;
        }

        Database::execute(
            "INSERT INTO finance_recurring_expenses (
                workspace_id, category_id, vendor_id, vendor, description, amount, currency, frequency,
                payment_method, start_date, next_due_date, end_date, is_active, created_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId, $payload['category_id'], $payload['vendor_id'], $payload['vendor'], $payload['description'], $payload['amount'],
                $payload['currency'], $payload['frequency'], $payload['payment_method'], $payload['start_date'],
                $payload['next_due_date'], $payload['end_date'], $payload['is_active'], $userId > 0 ? $userId : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function deleteRecurringExpense(int $workspaceId, int $recurringId): void
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        Database::execute("DELETE FROM finance_recurring_expenses WHERE workspace_id = ? AND id = ?", [$workspaceId, $recurringId]);
    }

    public function expenseByVendor(int $workspaceId, string $dateFrom, string $dateTo): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        $dateFrom = $this->normalizeDate($dateFrom, date('Y-m-01'));
        $dateTo = $this->normalizeDate($dateTo, date('Y-m-t'));

        return Database::query(
            "SELECT
                COALESCE(CAST(e.vendor_id AS CHAR), CONCAT('legacy:', COALESCE(NULLIF(TRIM(e.vendor), ''), 'No vendor'))) AS vendor_key,
                e.vendor_id,
                COALESCE(v.name, NULLIF(TRIM(e.vendor), ''), 'No vendor') AS vendor_name,
                COALESCE(SUM(CASE WHEN e.status = 'paid' THEN e.amount ELSE 0 END), 0) AS paid_total,
                COUNT(*) AS expense_count,
                MAX(e.expense_date) AS latest_expense_date
             FROM finance_expenses e
             LEFT JOIN finance_vendors v ON v.id = e.vendor_id AND v.workspace_id = e.workspace_id
             WHERE e.workspace_id = ?
               AND e.expense_date BETWEEN ? AND ?
             GROUP BY vendor_key, e.vendor_id, vendor_name
             ORDER BY paid_total DESC, vendor_name ASC",
            [$workspaceId, $dateFrom, $dateTo]
        );
    }

    public function seedDefaultCategories(int $workspaceId): void
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        foreach ([
            'Operations' => 'operations',
            'Marketing' => 'marketing',
            'Payroll' => 'payroll',
            'Software' => 'software',
            'Inventory' => 'inventory',
            'Taxes' => 'taxes',
            'Other' => 'other',
        ] as $name => $type) {
            $statementGroup = $this->defaultStatementGroup($type);
            Database::execute(
                "INSERT INTO finance_expense_categories (workspace_id, name, category_type, statement_group, cash_flow_group, profit_treatment, is_deductible, is_cogs, is_default)
                 VALUES (?, ?, ?, ?, 'operating', 'normal', 1, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    category_type = VALUES(category_type),
                    statement_group = COALESCE(statement_group, VALUES(statement_group)),
                    cash_flow_group = COALESCE(cash_flow_group, VALUES(cash_flow_group)),
                    profit_treatment = COALESCE(profit_treatment, VALUES(profit_treatment))",
                [$workspaceId, $name, $type, $statementGroup, $statementGroup === 'cost_of_sales' ? 1 : 0]
            );
        }
        foreach ([
            ['Owner salary', 'payroll', 'payroll', 'operating', 'normal'],
            ['Profit share', 'payroll', 'payroll', 'financing', 'post_net_profit_share'],
        ] as [$name, $type, $statementGroup, $cashFlowGroup, $profitTreatment]) {
            Database::execute(
                "INSERT INTO finance_expense_categories (workspace_id, name, category_type, statement_group, cash_flow_group, profit_treatment, is_deductible, is_cogs, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, 1, 0, 1)
                 ON DUPLICATE KEY UPDATE
                    category_type = VALUES(category_type),
                    statement_group = VALUES(statement_group),
                    cash_flow_group = VALUES(cash_flow_group),
                    profit_treatment = VALUES(profit_treatment),
                    is_default = 1",
                [$workspaceId, $name, $type, $statementGroup, $cashFlowGroup, $profitTreatment]
            );
        }
    }

    private function assertCategory(int $workspaceId, int $categoryId): void
    {
        $row = Database::queryOne(
            "SELECT id FROM finance_expense_categories WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$workspaceId, $categoryId]
        );
        if (!$row) {
            throw new \RuntimeException('Expense category does not belong to this workspace.');
        }
    }

    private function vendorNameById(int $workspaceId, int $vendorId): string
    {
        $row = Database::queryOne(
            "SELECT name FROM finance_vendors WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$workspaceId, $vendorId]
        );

        return (string) ($row['name'] ?? '');
    }

    private function sanitizeText(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, $max);
    }

    private function sanitizeAmount(mixed $value): float
    {
        $amount = round((float) $value, 2);
        return max(0.0, min($amount, 999999999.99));
    }

    private function sanitizeCurrency(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z]{3,10}$/', $value) ? $value : 'USD';
    }

    private function normalizeDate(string $value, string $fallback): string
    {
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : $fallback;
    }

    private function nullableDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    private function normalizeCategoryType(string $value): string
    {
        return in_array($value, ['operations', 'marketing', 'payroll', 'software', 'inventory', 'taxes', 'other'], true)
            ? $value
            : 'other';
    }

    private function normalizeStatementGroup(string $value): string
    {
        return in_array($value, ['revenue', 'cost_of_sales', 'operating_expense', 'payroll', 'tax', 'asset', 'liability', 'equity'], true)
            ? $value
            : 'operating_expense';
    }

    private function normalizeCashFlowGroup(string $value): string
    {
        return in_array($value, ['operating', 'investing', 'financing'], true)
            ? $value
            : 'operating';
    }

    private function normalizeProfitTreatment(string $value): string
    {
        return $value === 'post_net_profit_share' ? 'post_net_profit_share' : 'normal';
    }

    private function defaultStatementGroup(string $categoryType): string
    {
        return match ($categoryType) {
            'payroll' => 'payroll',
            'taxes' => 'tax',
            'inventory' => 'cost_of_sales',
            default => 'operating_expense',
        };
    }

    private function normalizePaymentMethod(string $value): string
    {
        return in_array($value, ['cash', 'card', 'bank_transfer', 'mobile_money', 'other'], true)
            ? $value
            : 'other';
    }

    private function normalizeExpenseStatus(string $value): string
    {
        return in_array($value, ['planned', 'paid', 'reimbursed', 'cancelled'], true)
            ? $value
            : 'paid';
    }

    private function normalizeFrequency(string $value): string
    {
        return in_array($value, ['weekly', 'monthly', 'quarterly', 'yearly'], true)
            ? $value
            : 'monthly';
    }
}
