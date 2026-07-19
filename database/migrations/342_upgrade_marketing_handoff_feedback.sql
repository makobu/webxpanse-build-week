-- Marketing Phase 52: sales feedback loop for lead handoffs.

ALTER TABLE marketing_lead_handoffs
    MODIFY status ENUM('new','assigned','accepted','contacted','qualified','rejected','lost','converted','archived') NOT NULL DEFAULT 'new';

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'marketing_lead_handoffs'
      AND column_name = 'accepted_at'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE marketing_lead_handoffs ADD COLUMN accepted_at DATETIME NULL AFTER assigned_at',
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
      AND column_name = 'lost_at'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE marketing_lead_handoffs ADD COLUMN lost_at DATETIME NULL AFTER converted_at',
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
      AND column_name = 'sales_outcome'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE marketing_lead_handoffs ADD COLUMN sales_outcome ENUM(''accepted'',''rejected'',''contacted'',''qualified'',''converted'',''lost'') NULL AFTER lost_at',
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
      AND column_name = 'feedback_reason'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE marketing_lead_handoffs ADD COLUMN feedback_reason VARCHAR(255) NULL AFTER sales_outcome',
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
      AND column_name = 'feedback_note'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE marketing_lead_handoffs ADD COLUMN feedback_note TEXT NULL AFTER feedback_reason',
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
      AND column_name = 'feedback_by'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE marketing_lead_handoffs ADD COLUMN feedback_by INT NULL AFTER feedback_note',
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
      AND column_name = 'feedback_at'
);
SET @sql := IF(
    @column_exists = 0,
    'ALTER TABLE marketing_lead_handoffs ADD COLUMN feedback_at DATETIME NULL AFTER feedback_by',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS marketing_handoff_feedback_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    lead_handoff_id INT NOT NULL,
    previous_status ENUM('new','assigned','accepted','contacted','qualified','rejected','lost','converted','archived') NULL,
    new_status ENUM('new','assigned','accepted','contacted','qualified','rejected','lost','converted','archived') NOT NULL,
    outcome ENUM('accepted','rejected','contacted','qualified','converted','lost') NOT NULL,
    reason VARCHAR(255) NULL,
    note TEXT NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_handoff_feedback_uuid (uuid),
    KEY idx_marketing_handoff_feedback_workspace_handoff (workspace_id, lead_handoff_id, created_at),
    KEY idx_marketing_handoff_feedback_workspace_outcome (workspace_id, outcome, created_at),
    CONSTRAINT fk_marketing_handoff_feedback_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_handoff_feedback_handoff FOREIGN KEY (lead_handoff_id) REFERENCES marketing_lead_handoffs(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_handoff_feedback_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'marketing_lead_handoffs'
      AND index_name = 'idx_marketing_handoffs_workspace_outcome'
);
SET @sql := IF(
    @index_exists = 0,
    'CREATE INDEX idx_marketing_handoffs_workspace_outcome ON marketing_lead_handoffs (workspace_id, sales_outcome, feedback_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
