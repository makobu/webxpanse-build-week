SET @checkout_workspace_ref_table := (
    SELECT REFERENCED_TABLE_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'billing_checkout_sessions'
      AND CONSTRAINT_NAME = 'fk_billing_checkout_workspace'
    LIMIT 1
);
SET @checkout_drop_fk_sql := IF(
    @checkout_workspace_ref_table = 'billing_workspaces',
    'ALTER TABLE billing_checkout_sessions DROP FOREIGN KEY fk_billing_checkout_workspace',
    'SELECT 1'
);
PREPARE stmt FROM @checkout_drop_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @checkout_workspace_ref_table_after_drop := (
    SELECT REFERENCED_TABLE_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'billing_checkout_sessions'
      AND CONSTRAINT_NAME = 'fk_billing_checkout_workspace'
    LIMIT 1
);
SET @checkout_add_fk_sql := IF(
    @checkout_workspace_ref_table_after_drop IS NULL,
    'ALTER TABLE billing_checkout_sessions ADD CONSTRAINT fk_billing_checkout_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @checkout_add_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @transactions_workspace_ref_table := (
    SELECT REFERENCED_TABLE_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'billing_transactions'
      AND CONSTRAINT_NAME = 'fk_billing_transactions_workspace'
    LIMIT 1
);
SET @transactions_drop_fk_sql := IF(
    @transactions_workspace_ref_table = 'billing_workspaces',
    'ALTER TABLE billing_transactions DROP FOREIGN KEY fk_billing_transactions_workspace',
    'SELECT 1'
);
PREPARE stmt FROM @transactions_drop_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @transactions_workspace_ref_table_after_drop := (
    SELECT REFERENCED_TABLE_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'billing_transactions'
      AND CONSTRAINT_NAME = 'fk_billing_transactions_workspace'
    LIMIT 1
);
SET @transactions_add_fk_sql := IF(
    @transactions_workspace_ref_table_after_drop IS NULL,
    'ALTER TABLE billing_transactions ADD CONSTRAINT fk_billing_transactions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @transactions_add_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @billing_checkout_reference_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'billing_checkout_sessions'
      AND column_name = 'reference'
);
SET @billing_checkout_align_sql := IF(
    @billing_checkout_reference_exists > 0,
    'ALTER TABLE billing_checkout_sessions
        MODIFY COLUMN reference VARCHAR(100) NULL,
        MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        MODIFY COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'ALTER TABLE billing_checkout_sessions
        MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        MODIFY COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
);
PREPARE stmt FROM @billing_checkout_align_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @billing_transactions_reference_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'billing_transactions'
      AND column_name = 'reference'
);
SET @billing_transactions_align_sql := IF(
    @billing_transactions_reference_exists > 0,
    'ALTER TABLE billing_transactions
        MODIFY COLUMN reference VARCHAR(100) NULL,
        MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        MODIFY COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'ALTER TABLE billing_transactions
        MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        MODIFY COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
);
PREPARE stmt FROM @billing_transactions_align_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @billing_checkout_reference_copy_sql := IF(
    @billing_checkout_reference_exists > 0,
    "UPDATE billing_checkout_sessions
     SET reference = COALESCE(reference, provider_reference)
     WHERE reference IS NULL
       AND provider_reference IS NOT NULL
       AND provider_reference <> ''",
    'SELECT 1'
);
PREPARE stmt FROM @billing_checkout_reference_copy_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @billing_transactions_reference_copy_sql := IF(
    @billing_transactions_reference_exists > 0,
    "UPDATE billing_transactions
     SET reference = COALESCE(reference, provider_reference)
     WHERE reference IS NULL
       AND provider_reference IS NOT NULL
       AND provider_reference <> ''",
    'SELECT 1'
);
PREPARE stmt FROM @billing_transactions_reference_copy_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
