-- Lightweight Finance ledger for capital, funding, equity, loans, assets, and opening balances.

CREATE TABLE IF NOT EXISTS finance_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(140) NOT NULL,
    account_type ENUM('asset','liability','equity','revenue','expense') NOT NULL,
    normal_balance ENUM('debit','credit') NOT NULL,
    statement_section VARCHAR(80) NOT NULL DEFAULT '',
    is_cash BOOLEAN NOT NULL DEFAULT FALSE,
    is_system BOOLEAN NOT NULL DEFAULT TRUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_finance_account_workspace_code (workspace_id, code),
    KEY idx_finance_accounts_workspace_type (workspace_id, account_type),
    KEY idx_finance_accounts_cash (workspace_id, is_cash),
    CONSTRAINT fk_finance_accounts_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    transaction_type ENUM('founder_capital','equity_funding','loan_received','loan_repayment','owner_draw','asset_purchase','opening_balance','manual_adjustment') NOT NULL,
    transaction_date DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    counterparty VARCHAR(180) NULL,
    memo TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_finance_transactions_workspace_date (workspace_id, transaction_date),
    KEY idx_finance_transactions_type (workspace_id, transaction_type),
    CONSTRAINT fk_finance_transactions_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_journal_entries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    transaction_id INT NOT NULL,
    account_id INT NOT NULL,
    entry_type ENUM('debit','credit') NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    memo VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_finance_journal_workspace_account (workspace_id, account_id),
    KEY idx_finance_journal_transaction (transaction_id),
    CONSTRAINT fk_finance_journal_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_journal_transaction
        FOREIGN KEY (transaction_id) REFERENCES finance_transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_journal_account
        FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO finance_accounts (
    workspace_id, code, name, account_type, normal_balance, statement_section, is_cash, is_system
)
SELECT w.id, seed.code, seed.name, seed.account_type, seed.normal_balance, seed.statement_section, seed.is_cash, TRUE
FROM workspaces w
JOIN (
    SELECT '1000' AS code, 'Cash' AS name, 'asset' AS account_type, 'debit' AS normal_balance, 'cash' AS statement_section, TRUE AS is_cash
    UNION ALL SELECT '1100', 'Accounts Receivable', 'asset', 'debit', 'receivables', FALSE
    UNION ALL SELECT '1200', 'Fixed Assets', 'asset', 'debit', 'assets', FALSE
    UNION ALL SELECT '1300', 'Other Assets', 'asset', 'debit', 'assets', FALSE
    UNION ALL SELECT '2000', 'Accounts Payable', 'liability', 'credit', 'liabilities', FALSE
    UNION ALL SELECT '2100', 'Loan Payable', 'liability', 'credit', 'liabilities', FALSE
    UNION ALL SELECT '2200', 'Tax Payable', 'liability', 'credit', 'liabilities', FALSE
    UNION ALL SELECT '3000', 'Owner Equity', 'equity', 'credit', 'equity', FALSE
    UNION ALL SELECT '3100', 'Share Capital', 'equity', 'credit', 'equity', FALSE
    UNION ALL SELECT '3200', 'Additional Paid-in Capital', 'equity', 'credit', 'equity', FALSE
    UNION ALL SELECT '3300', 'Retained Earnings', 'equity', 'credit', 'equity', FALSE
    UNION ALL SELECT '3400', 'Owner Draws and Dividends', 'equity', 'debit', 'equity', FALSE
    UNION ALL SELECT '4000', 'Other Income', 'revenue', 'credit', 'non_operating_income', FALSE
    UNION ALL SELECT '5000', 'Interest Expense', 'expense', 'debit', 'non_operating_expense', FALSE
) seed
LEFT JOIN finance_accounts existing
  ON existing.workspace_id = w.id
 AND existing.code = seed.code
WHERE existing.id IS NULL;
