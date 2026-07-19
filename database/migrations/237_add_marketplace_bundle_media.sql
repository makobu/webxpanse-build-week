SET @bundle_thumbnail_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_marketplace_activation_bundle_definitions'
      AND column_name = 'thumbnail_url'
);
SET @sql := IF(
    @bundle_thumbnail_exists = 0,
    'ALTER TABLE workspace_marketplace_activation_bundle_definitions ADD COLUMN thumbnail_url VARCHAR(500) NULL AFTER summary',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @bundle_banner_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_marketplace_activation_bundle_definitions'
      AND column_name = 'banner_url'
);
SET @sql := IF(
    @bundle_banner_exists = 0,
    'ALTER TABLE workspace_marketplace_activation_bundle_definitions ADD COLUMN banner_url VARCHAR(500) NULL AFTER thumbnail_url',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @bundle_video_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_marketplace_activation_bundle_definitions'
      AND column_name = 'explainer_video_url'
);
SET @sql := IF(
    @bundle_video_exists = 0,
    'ALTER TABLE workspace_marketplace_activation_bundle_definitions ADD COLUMN explainer_video_url VARCHAR(500) NULL AFTER banner_url',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
