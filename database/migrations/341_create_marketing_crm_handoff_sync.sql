-- Marketing Phase 50: CRM visibility for marketing handoffs.

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'marketing_lead_handoffs'
      AND column_name = 'crm_activity_id'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE marketing_lead_handoffs ADD COLUMN crm_activity_id INT NULL AFTER task_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'marketing_lead_handoffs'
      AND column_name = 'notification_id'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE marketing_lead_handoffs ADD COLUMN notification_id INT NULL AFTER crm_activity_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'marketing_lead_handoffs'
      AND column_name = 'crm_sync_status'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE marketing_lead_handoffs ADD COLUMN crm_sync_status ENUM(''pending'',''synced'',''partial'',''skipped'',''failed'') NOT NULL DEFAULT ''pending'' AFTER notification_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'marketing_lead_handoffs'
      AND column_name = 'crm_synced_at'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE marketing_lead_handoffs ADD COLUMN crm_synced_at DATETIME NULL AFTER crm_sync_status',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS marketing_crm_sync_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    lead_handoff_id INT NOT NULL,
    activity_id INT NULL,
    notification_id INT NULL,
    sync_type ENUM('activity','notification','assignment','task','status') NOT NULL,
    status ENUM('synced','skipped','failed') NOT NULL DEFAULT 'synced',
    message VARCHAR(255) NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_crm_sync_events_uuid (uuid),
    KEY idx_marketing_crm_sync_workspace_handoff (workspace_id, lead_handoff_id, created_at),
    KEY idx_marketing_crm_sync_workspace_type (workspace_id, sync_type, status, created_at),
    CONSTRAINT fk_marketing_crm_sync_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_crm_sync_handoff FOREIGN KEY (lead_handoff_id) REFERENCES marketing_lead_handoffs(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_crm_sync_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'marketing_lead_handoffs'
      AND index_name = 'idx_marketing_handoffs_workspace_crm_sync'
);
SET @sql := IF(
    @index_exists = 0,
    'CREATE INDEX idx_marketing_handoffs_workspace_crm_sync ON marketing_lead_handoffs (workspace_id, crm_sync_status, crm_synced_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
