SET @catalog_status_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_catalog_overrides'
      AND column_name = 'catalog_status'
);
SET @add_catalog_status_sql := IF(
    @catalog_status_exists = 0,
    'ALTER TABLE workspace_skill_catalog_overrides ADD COLUMN catalog_status ENUM(''visible'',''hidden'',''deactivated'') NOT NULL DEFAULT ''visible'' AFTER marketplace_profile_json',
    'SELECT 1'
);
PREPARE stmt FROM @add_catalog_status_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @catalog_status_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_catalog_overrides'
      AND index_name = 'idx_workspace_skill_catalog_overrides_status'
);
SET @add_catalog_status_index_sql := IF(
    @catalog_status_index_exists = 0,
    'ALTER TABLE workspace_skill_catalog_overrides ADD KEY idx_workspace_skill_catalog_overrides_status (catalog_status)',
    'SELECT 1'
);
PREPARE stmt FROM @add_catalog_status_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
