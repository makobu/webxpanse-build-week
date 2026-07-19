-- Keep subscription package source identity on commercial document lines.
-- Use information_schema guards instead of MariaDB-only ALTER TABLE IF NOT EXISTS syntax.
-- This keeps phpMyAdmin imports safe on MySQL 5.7/8.0 and on partially applied databases.
SET @crm_has_catalog_source_type := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'invoice_line_items'
      AND COLUMN_NAME = 'catalog_source_type'
);
SET @crm_sql := IF(
    @crm_has_catalog_source_type = 0,
    'ALTER TABLE invoice_line_items ADD COLUMN catalog_source_type ENUM(''manual'', ''workspace_offer'', ''workspace_package'') NOT NULL DEFAULT ''manual'' AFTER product_id',
    'SET @crm_migration_noop = 1'
);
PREPARE crm_stmt FROM @crm_sql;
EXECUTE crm_stmt;
DEALLOCATE PREPARE crm_stmt;

SET @crm_has_billing_plan_price_id := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'invoice_line_items'
      AND COLUMN_NAME = 'billing_plan_price_id'
);
SET @crm_sql := IF(
    @crm_has_billing_plan_price_id = 0,
    'ALTER TABLE invoice_line_items ADD COLUMN billing_plan_price_id INT NULL AFTER catalog_source_type',
    'SET @crm_migration_noop = 1'
);
PREPARE crm_stmt FROM @crm_sql;
EXECUTE crm_stmt;
DEALLOCATE PREPARE crm_stmt;

SET @crm_has_billing_price_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'invoice_line_items'
      AND INDEX_NAME = 'idx_invoice_line_billing_price'
);
SET @crm_sql := IF(
    @crm_has_billing_price_index = 0,
    'ALTER TABLE invoice_line_items ADD INDEX idx_invoice_line_billing_price (billing_plan_price_id)',
    'SET @crm_migration_noop = 1'
);
PREPARE crm_stmt FROM @crm_sql;
EXECUTE crm_stmt;
DEALLOCATE PREPARE crm_stmt;

SET @crm_has_billing_price_fk := (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'invoice_line_items'
      AND CONSTRAINT_NAME = 'fk_invoice_line_billing_price'
);
SET @crm_sql := IF(
    @crm_has_billing_price_fk = 0,
    'ALTER TABLE invoice_line_items ADD CONSTRAINT fk_invoice_line_billing_price FOREIGN KEY (billing_plan_price_id) REFERENCES billing_plan_prices(id) ON DELETE SET NULL',
    'SET @crm_migration_noop = 1'
);
PREPARE crm_stmt FROM @crm_sql;
EXECUTE crm_stmt;
DEALLOCATE PREPARE crm_stmt;

UPDATE invoice_line_items
SET catalog_source_type = 'workspace_offer'
WHERE product_id IS NOT NULL
  AND catalog_source_type = 'manual';

-- Internal Platform Ops capabilities used to be represented as sellable products.
-- Archive only untouched canonical seed rows. Historical line item references remain valid.
UPDATE products
SET is_active = FALSE,
    updated_at = NOW()
WHERE workspace_id = 1
  AND is_active = TRUE
  AND COALESCE(unit_price, 0) = 0
  AND pricing_info = 'Internal service'
  AND name IN (
      'Workspace Provisioning and Recovery',
      'Tenant Billing and AI Credit Operations',
      'Platform Dashboard and Reporting',
      'Onboarding Lifecycle Management',
      'Channel Health and Deliverability',
      'Automation Governance and Security Review',
      'Workspace Deletion Review and Compliance',
      'Platform Support Escalation Desk'
  )
  AND (
      seed_metadata_json IS NULL
      OR JSON_EXTRACT(seed_metadata_json, '$.customized_at') IS NULL
  );
