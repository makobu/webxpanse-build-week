-- Detailed beginner Finance opening setup.

CREATE TABLE IF NOT EXISTS finance_opening_setups (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    opening_date DATE NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    notes TEXT NULL,
    start_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
    bank_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
    assets_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
    receivables_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
    liabilities_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
    owners_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
    is_complete BOOLEAN NOT NULL DEFAULT FALSE,
    completed_at TIMESTAMP NULL,
    opening_transaction_id INT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_finance_opening_setup_workspace (workspace_id),
    KEY idx_finance_opening_setups_complete (workspace_id, is_complete),
    KEY idx_finance_opening_setups_transaction (opening_transaction_id),
    CONSTRAINT fk_finance_opening_setups_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_opening_setups_transaction
        FOREIGN KEY (opening_transaction_id) REFERENCES finance_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_opening_bank_balances (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    cash_account_id INT NOT NULL,
    opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_finance_opening_bank_account (workspace_id, cash_account_id),
    KEY idx_finance_opening_bank_workspace (workspace_id),
    CONSTRAINT fk_finance_opening_bank_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_opening_bank_cash_account
        FOREIGN KEY (cash_account_id) REFERENCES finance_cash_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_opening_assets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    name VARCHAR(180) NOT NULL,
    asset_type ENUM('equipment','vehicle','furniture','property','software','other') NOT NULL DEFAULT 'other',
    value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_finance_opening_assets_workspace (workspace_id),
    CONSTRAINT fk_finance_opening_assets_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_opening_receivables (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    from_name VARCHAR(180) NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    due_date DATE NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_finance_opening_receivables_workspace (workspace_id),
    CONSTRAINT fk_finance_opening_receivables_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_opening_liabilities (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    name VARCHAR(180) NOT NULL,
    liability_type ENUM('loan','supplier_bill','tax','other') NOT NULL DEFAULT 'other',
    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    due_date DATE NULL,
    interest_rate DECIMAL(8,4) NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_finance_opening_liabilities_workspace (workspace_id),
    KEY idx_finance_opening_liabilities_type (workspace_id, liability_type),
    CONSTRAINT fk_finance_opening_liabilities_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO finance_opening_setups (
    workspace_id, opening_date, currency, notes,
    start_reviewed, bank_reviewed, assets_reviewed, receivables_reviewed,
    liabilities_reviewed, owners_reviewed, is_complete, completed_at,
    opening_transaction_id, created_by, updated_by
)
SELECT
    t.workspace_id,
    t.transaction_date,
    t.currency,
    t.memo,
    TRUE,
    TRUE,
    TRUE,
    TRUE,
    TRUE,
    TRUE,
    TRUE,
    NOW(),
    t.id,
    t.created_by,
    t.created_by
FROM finance_transactions t
LEFT JOIN finance_transactions newer
  ON newer.workspace_id = t.workspace_id
 AND newer.transaction_type = 'opening_balance'
 AND (
    newer.transaction_date > t.transaction_date
    OR (newer.transaction_date = t.transaction_date AND newer.id > t.id)
 )
WHERE t.transaction_type = 'opening_balance'
  AND newer.id IS NULL
ON DUPLICATE KEY UPDATE
    opening_date = VALUES(opening_date),
    currency = VALUES(currency),
    notes = VALUES(notes),
    is_complete = TRUE,
    completed_at = COALESCE(completed_at, NOW()),
    opening_transaction_id = VALUES(opening_transaction_id),
    updated_by = VALUES(updated_by);

INSERT INTO finance_opening_bank_balances (workspace_id, cash_account_id, opening_balance, note)
SELECT
    t.workspace_id,
    ca.id,
    ROUND(SUM(CASE WHEN a.code = '1000' AND je.entry_type = 'debit' THEN je.amount ELSE 0 END), 2),
    'Opening cash'
FROM finance_transactions t
JOIN finance_journal_entries je ON je.transaction_id = t.id AND je.workspace_id = t.workspace_id
JOIN finance_accounts a ON a.id = je.account_id AND a.workspace_id = je.workspace_id
JOIN finance_cash_accounts ca ON ca.workspace_id = t.workspace_id AND ca.is_default = TRUE
LEFT JOIN finance_opening_bank_balances existing
  ON existing.workspace_id = t.workspace_id
 AND existing.cash_account_id = ca.id
WHERE t.id IN (SELECT opening_transaction_id FROM finance_opening_setups WHERE opening_transaction_id IS NOT NULL)
  AND existing.id IS NULL
GROUP BY t.workspace_id, ca.id
HAVING SUM(CASE WHEN a.code = '1000' AND je.entry_type = 'debit' THEN je.amount ELSE 0 END) > 0;

INSERT INTO finance_opening_assets (workspace_id, name, asset_type, value, note)
SELECT
    t.workspace_id,
    'Opening assets',
    'other',
    ROUND(SUM(CASE WHEN a.code = '1200' AND je.entry_type = 'debit' THEN je.amount ELSE 0 END), 2),
    'Backfilled from old Finance setup'
FROM finance_transactions t
JOIN finance_journal_entries je ON je.transaction_id = t.id AND je.workspace_id = t.workspace_id
JOIN finance_accounts a ON a.id = je.account_id AND a.workspace_id = je.workspace_id
WHERE t.id IN (SELECT opening_transaction_id FROM finance_opening_setups WHERE opening_transaction_id IS NOT NULL)
GROUP BY t.workspace_id
HAVING SUM(CASE WHEN a.code = '1200' AND je.entry_type = 'debit' THEN je.amount ELSE 0 END) > 0;

INSERT INTO finance_opening_receivables (workspace_id, from_name, amount, due_date, note)
SELECT
    t.workspace_id,
    'Opening receivables',
    ROUND(SUM(CASE WHEN a.code = '1100' AND je.entry_type = 'debit' THEN je.amount ELSE 0 END), 2),
    NULL,
    'Backfilled from old Finance setup'
FROM finance_transactions t
JOIN finance_journal_entries je ON je.transaction_id = t.id AND je.workspace_id = t.workspace_id
JOIN finance_accounts a ON a.id = je.account_id AND a.workspace_id = je.workspace_id
WHERE t.id IN (SELECT opening_transaction_id FROM finance_opening_setups WHERE opening_transaction_id IS NOT NULL)
GROUP BY t.workspace_id
HAVING SUM(CASE WHEN a.code = '1100' AND je.entry_type = 'debit' THEN je.amount ELSE 0 END) > 0;

INSERT INTO finance_opening_liabilities (workspace_id, name, liability_type, amount, due_date, interest_rate, note)
SELECT
    t.workspace_id,
    'Opening loan',
    'loan',
    ROUND(SUM(CASE WHEN a.code = '2100' AND je.entry_type = 'credit' THEN je.amount ELSE 0 END), 2),
    NULL,
    NULL,
    'Backfilled from old Finance setup'
FROM finance_transactions t
JOIN finance_journal_entries je ON je.transaction_id = t.id AND je.workspace_id = t.workspace_id
JOIN finance_accounts a ON a.id = je.account_id AND a.workspace_id = je.workspace_id
WHERE t.id IN (SELECT opening_transaction_id FROM finance_opening_setups WHERE opening_transaction_id IS NOT NULL)
GROUP BY t.workspace_id
HAVING SUM(CASE WHEN a.code = '2100' AND je.entry_type = 'credit' THEN je.amount ELSE 0 END) > 0;

INSERT INTO finance_opening_liabilities (workspace_id, name, liability_type, amount, due_date, interest_rate, note)
SELECT
    t.workspace_id,
    'Opening payables',
    'supplier_bill',
    ROUND(SUM(CASE WHEN a.code = '2000' AND je.entry_type = 'credit' THEN je.amount ELSE 0 END), 2),
    NULL,
    NULL,
    'Backfilled from old Finance setup'
FROM finance_transactions t
JOIN finance_journal_entries je ON je.transaction_id = t.id AND je.workspace_id = t.workspace_id
JOIN finance_accounts a ON a.id = je.account_id AND a.workspace_id = je.workspace_id
WHERE t.id IN (SELECT opening_transaction_id FROM finance_opening_setups WHERE opening_transaction_id IS NOT NULL)
GROUP BY t.workspace_id
HAVING SUM(CASE WHEN a.code = '2000' AND je.entry_type = 'credit' THEN je.amount ELSE 0 END) > 0;
