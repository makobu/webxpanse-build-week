-- Finance labels and owner payout reporting.

CREATE TABLE IF NOT EXISTS finance_income_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    income_type ENUM('sale','other_income') NOT NULL DEFAULT 'sale',
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_finance_income_category_workspace_name (workspace_id, name),
    KEY idx_finance_income_categories_workspace_active (workspace_id, is_active),
    KEY idx_finance_income_categories_type (workspace_id, income_type),
    CONSTRAINT fk_finance_income_categories_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE finance_income_entries
    ADD COLUMN IF NOT EXISTS income_category_id INT NULL AFTER income_type,
    ADD KEY IF NOT EXISTS idx_finance_income_category (income_category_id);

ALTER TABLE finance_expense_categories
    ADD COLUMN IF NOT EXISTS profit_treatment ENUM('normal','post_net_profit_share') NOT NULL DEFAULT 'normal' AFTER cash_flow_group,
    ADD KEY IF NOT EXISTS idx_finance_expense_profit_treatment (workspace_id, profit_treatment);

INSERT INTO finance_income_categories (workspace_id, name, income_type, is_default, is_active)
SELECT w.id, defaults.name, defaults.income_type, defaults.is_default, TRUE
FROM workspaces w
JOIN (
    SELECT 'Sales' AS name, 'sale' AS income_type, TRUE AS is_default
    UNION ALL SELECT 'Service income', 'sale', FALSE
    UNION ALL SELECT 'Product sales', 'sale', FALSE
    UNION ALL SELECT 'Other income', 'other_income', TRUE
) defaults
LEFT JOIN finance_income_categories existing
    ON existing.workspace_id = w.id AND existing.name = defaults.name
WHERE existing.id IS NULL;

UPDATE finance_income_entries i
JOIN finance_income_categories c
    ON c.workspace_id = i.workspace_id
   AND c.name = CASE WHEN i.income_type = 'other_income' THEN 'Other income' ELSE 'Sales' END
SET i.income_category_id = c.id
WHERE i.income_category_id IS NULL;

INSERT INTO finance_expense_categories (
    workspace_id, name, category_type, statement_group, cash_flow_group,
    profit_treatment, is_deductible, is_cogs, is_default
)
SELECT w.id, 'Owner salary', 'payroll', 'payroll', 'operating', 'normal', TRUE, FALSE, TRUE
FROM workspaces w
LEFT JOIN finance_expense_categories existing
    ON existing.workspace_id = w.id AND existing.name = 'Owner salary'
WHERE existing.id IS NULL;

INSERT INTO finance_expense_categories (
    workspace_id, name, category_type, statement_group, cash_flow_group,
    profit_treatment, is_deductible, is_cogs, is_default
)
SELECT w.id, 'Profit share', 'payroll', 'payroll', 'financing', 'post_net_profit_share', TRUE, FALSE, TRUE
FROM workspaces w
LEFT JOIN finance_expense_categories existing
    ON existing.workspace_id = w.id AND existing.name = 'Profit share'
WHERE existing.id IS NULL;

UPDATE finance_expense_categories
SET category_type = 'payroll',
    statement_group = 'payroll',
    cash_flow_group = 'operating',
    profit_treatment = 'normal',
    is_deductible = TRUE,
    is_cogs = FALSE,
    is_default = TRUE
WHERE name = 'Owner salary';

UPDATE finance_expense_categories
SET category_type = 'payroll',
    statement_group = 'payroll',
    cash_flow_group = 'financing',
    profit_treatment = 'post_net_profit_share',
    is_deductible = TRUE,
    is_cogs = FALSE,
    is_default = TRUE
WHERE name = 'Profit share';
