SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'workspace_marketplace_recommendation_events'
      AND INDEX_NAME = 'idx_marketplace_rec_events_workspace_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_marketplace_rec_events_workspace_created ON workspace_marketplace_recommendation_events (workspace_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'workspace_marketplace_recommendation_events'
      AND INDEX_NAME = 'idx_marketplace_rec_events_workspace_skill_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_marketplace_rec_events_workspace_skill_created ON workspace_marketplace_recommendation_events (workspace_id, skill_key, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'workspace_marketplace_setup_journey_events'
      AND INDEX_NAME = 'idx_marketplace_setup_events_workspace_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_marketplace_setup_events_workspace_created ON workspace_marketplace_setup_journey_events (workspace_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'workspace_marketplace_setup_journey_events'
      AND INDEX_NAME = 'idx_marketplace_setup_events_workspace_skill_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_marketplace_setup_events_workspace_skill_created ON workspace_marketplace_setup_journey_events (workspace_id, skill_key, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'workspace_marketplace_activation_bundle_events'
      AND INDEX_NAME = 'idx_marketplace_bundle_events_workspace_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_marketplace_bundle_events_workspace_created ON workspace_marketplace_activation_bundle_events (workspace_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'workspace_marketplace_activation_bundle_events'
      AND INDEX_NAME = 'idx_marketplace_bundle_events_workspace_bundle_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_marketplace_bundle_events_workspace_bundle_created ON workspace_marketplace_activation_bundle_events (workspace_id, bundle_key, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
