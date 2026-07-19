-- Finance vendor directory and vendor-linked expenses.

CREATE TABLE IF NOT EXISTS finance_vendors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    name VARCHAR(180) NOT NULL,
    notes TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_finance_vendor_workspace_name (workspace_id, name),
    KEY idx_finance_vendors_workspace (workspace_id),
    KEY idx_finance_vendors_active (is_active),
    CONSTRAINT fk_finance_vendors_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE finance_expenses
    ADD COLUMN IF NOT EXISTS vendor_id INT NULL AFTER category_id,
    ADD KEY IF NOT EXISTS idx_finance_expenses_vendor (vendor_id);

ALTER TABLE finance_recurring_expenses
    ADD COLUMN IF NOT EXISTS vendor_id INT NULL AFTER category_id,
    ADD KEY IF NOT EXISTS idx_finance_recurring_expenses_vendor (vendor_id);

INSERT INTO finance_vendors (workspace_id, name, created_by)
SELECT workspace_id, name, MIN(created_by) AS created_by
FROM (
    SELECT workspace_id, TRIM(vendor) AS name, created_by
    FROM finance_expenses
    WHERE vendor IS NOT NULL AND TRIM(vendor) <> ''
    UNION ALL
    SELECT workspace_id, TRIM(vendor) AS name, created_by
    FROM finance_recurring_expenses
    WHERE vendor IS NOT NULL AND TRIM(vendor) <> ''
) vendor_seed
GROUP BY workspace_id, name
ON DUPLICATE KEY UPDATE
    is_active = TRUE,
    updated_at = NOW();

UPDATE finance_expenses e
JOIN finance_vendors v
  ON v.workspace_id = e.workspace_id
 AND v.name = TRIM(e.vendor)
SET e.vendor_id = v.id,
    e.vendor = v.name
WHERE e.vendor IS NOT NULL
  AND TRIM(e.vendor) <> '';

UPDATE finance_recurring_expenses r
JOIN finance_vendors v
  ON v.workspace_id = r.workspace_id
 AND v.name = TRIM(r.vendor)
SET r.vendor_id = v.id,
    r.vendor = v.name
WHERE r.vendor IS NOT NULL
  AND TRIM(r.vendor) <> '';
