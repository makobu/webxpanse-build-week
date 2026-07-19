-- Minimal-words Finance: cash accounts and manual income.

CREATE TABLE IF NOT EXISTS finance_cash_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    name VARCHAR(140) NOT NULL,
    account_type ENUM('bank','cash','mobile_money','other') NOT NULL DEFAULT 'bank',
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_finance_cash_account_workspace_name (workspace_id, name),
    KEY idx_finance_cash_accounts_workspace_active (workspace_id, is_active),
    KEY idx_finance_cash_accounts_default (workspace_id, is_default),
    CONSTRAINT fk_finance_cash_accounts_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_income_entries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    cash_account_id INT NULL,
    income_type ENUM('sale','other_income') NOT NULL DEFAULT 'sale',
    source VARCHAR(180) NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    income_date DATE NOT NULL,
    linked_invoice_id INT NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_finance_income_workspace_date (workspace_id, income_date),
    KEY idx_finance_income_cash_account (cash_account_id),
    KEY idx_finance_income_type (workspace_id, income_type),
    CONSTRAINT fk_finance_income_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_income_cash_account
        FOREIGN KEY (cash_account_id) REFERENCES finance_cash_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE finance_expenses
    ADD COLUMN IF NOT EXISTS cash_account_id INT NULL AFTER workspace_id,
    ADD KEY IF NOT EXISTS idx_finance_expenses_cash_account (cash_account_id);

ALTER TABLE finance_transactions
    ADD COLUMN IF NOT EXISTS cash_account_id INT NULL AFTER workspace_id,
    ADD KEY IF NOT EXISTS idx_finance_transactions_cash_account (cash_account_id);

INSERT INTO finance_cash_accounts (workspace_id, name, account_type, currency, is_default, is_active)
SELECT
    w.id,
    'Main Bank',
    'bank',
    COALESCE((SELECT code FROM currencies WHERE is_default = 1 AND is_active = 1 LIMIT 1), 'USD'),
    TRUE,
    TRUE
FROM workspaces w
LEFT JOIN finance_cash_accounts existing
  ON existing.workspace_id = w.id
 AND existing.name = 'Main Bank'
WHERE existing.id IS NULL;

UPDATE finance_expenses e
JOIN finance_cash_accounts a
  ON a.workspace_id = e.workspace_id
 AND a.is_default = TRUE
SET e.cash_account_id = a.id
WHERE e.cash_account_id IS NULL;

UPDATE finance_transactions t
JOIN finance_cash_accounts a
  ON a.workspace_id = t.workspace_id
 AND a.is_default = TRUE
SET t.cash_account_id = a.id
WHERE t.cash_account_id IS NULL;
