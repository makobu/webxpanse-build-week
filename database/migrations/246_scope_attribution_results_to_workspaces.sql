-- Denormalize attribution result workspace scope for safer tenant filtering and faster reporting.

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE attribution_results ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'attribution_results'
      AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE attribution_results ar
JOIN contacts c ON c.id = ar.contact_id
SET ar.workspace_id = c.workspace_id
WHERE ar.workspace_id IS NULL;

ALTER TABLE attribution_results MODIFY workspace_id INT NOT NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE attribution_results ADD KEY idx_attr_workspace_model_conversion (workspace_id, model_id, conversion_at)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'attribution_results'
      AND INDEX_NAME = 'idx_attr_workspace_model_conversion'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE attribution_results ADD KEY idx_attr_workspace_campaign (workspace_id, campaign_id, conversion_at)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'attribution_results'
      AND INDEX_NAME = 'idx_attr_workspace_campaign'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE attribution_results ADD KEY idx_attr_workspace_deal_model (workspace_id, deal_id, model_id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'attribution_results'
      AND INDEX_NAME = 'idx_attr_workspace_deal_model'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE attribution_results ADD CONSTRAINT fk_attribution_results_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE',
        'SELECT 1'
    )
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'attribution_results'
      AND CONSTRAINT_NAME = 'fk_attribution_results_workspace'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
