-- Professional Finance upgrade: accountant role, accounting mappings, and statement snapshots.

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','owner','accountant','sales','marketing','viewer') DEFAULT 'viewer';

ALTER TABLE finance_expense_categories
    ADD COLUMN IF NOT EXISTS statement_group ENUM('revenue','cost_of_sales','operating_expense','payroll','tax','asset','liability','equity') NOT NULL DEFAULT 'operating_expense' AFTER category_type,
    ADD COLUMN IF NOT EXISTS cash_flow_group ENUM('operating','investing','financing') NOT NULL DEFAULT 'operating' AFTER statement_group,
    ADD COLUMN IF NOT EXISTS is_deductible BOOLEAN NOT NULL DEFAULT TRUE AFTER cash_flow_group,
    ADD COLUMN IF NOT EXISTS is_cogs BOOLEAN NOT NULL DEFAULT FALSE AFTER is_deductible;

UPDATE finance_expense_categories
SET statement_group = CASE category_type
        WHEN 'payroll' THEN 'payroll'
        WHEN 'taxes' THEN 'tax'
        WHEN 'inventory' THEN 'cost_of_sales'
        ELSE 'operating_expense'
    END,
    cash_flow_group = 'operating',
    is_deductible = TRUE,
    is_cogs = CASE category_type WHEN 'inventory' THEN TRUE ELSE FALSE END
WHERE statement_group IS NULL
   OR statement_group = 'operating_expense';

CREATE TABLE IF NOT EXISTS finance_statement_snapshots (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    statement_type ENUM('profit_and_loss','cash_flow','working_balance_sheet','budget_variance','report_quality') NOT NULL,
    statement_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_finance_statement_snapshot (workspace_id, period_start, period_end, statement_type),
    KEY idx_finance_statement_snapshots_workspace (workspace_id),
    KEY idx_finance_statement_snapshots_period (period_start, period_end),
    CONSTRAINT fk_finance_statement_snapshots_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (name, slug, description, is_system, is_active) VALUES
('Accountant', 'accountant', 'Finance operator access for expenses, budgets, reports, accounting mappings, and finance AI guidance', TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system),
    is_active = VALUES(is_active);

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('finance.reports.view', 'View Finance Reports', 'View P&L, cash flow, working balance sheet, and budget variance reports', FALSE),
('finance.accounting.manage', 'Manage Accounting Mappings', 'Manage finance category accounting mappings and report cleanup', FALSE),
('ai.finance.guidance', 'Use Finance AI Guidance', 'Use AI finance and accounting guidance tools', FALSE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

UPDATE permissions
SET label = 'View Finance',
    description = 'View expenses, budgets, cash flow, runway, and finance reports'
WHERE permission_key = 'finance.view';

UPDATE permissions
SET label = 'Manage Finance',
    description = 'Create and manage expenses, budgets, recurring expenses, reports, and finance setup'
WHERE permission_key = 'finance.manage';

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'finance.view',
    'finance.manage',
    'finance.reports.view',
    'finance.accounting.manage',
    'ai.finance.guidance',
    'feature.invoices',
    'invoices.view',
    'contacts.view_all'
)
WHERE r.slug = 'accountant'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'finance.view',
    'finance.manage',
    'finance.reports.view',
    'finance.accounting.manage',
    'ai.finance.guidance'
)
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
