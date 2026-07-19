ALTER TABLE email_fetch_log
    MODIFY COLUMN status ENUM('running','success','partial','failed') DEFAULT 'running';

SET @email_fetch_log_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
);

SET @emails_total_found_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
      AND column_name = 'emails_total_found'
);

SET @sql := IF(
    @email_fetch_log_exists > 0 AND @emails_total_found_exists = 0,
    'ALTER TABLE email_fetch_log ADD COLUMN emails_total_found INT NOT NULL DEFAULT 0 AFTER emails_fetched',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @processed_count_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
      AND column_name = 'processed_count'
);

SET @sql := IF(
    @email_fetch_log_exists > 0 AND @processed_count_exists = 0,
    'ALTER TABLE email_fetch_log ADD COLUMN processed_count INT NOT NULL DEFAULT 0 AFTER emails_total_found',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @skipped_count_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
      AND column_name = 'skipped_count'
);

SET @sql := IF(
    @email_fetch_log_exists > 0 AND @skipped_count_exists = 0,
    'ALTER TABLE email_fetch_log ADD COLUMN skipped_count INT NOT NULL DEFAULT 0 AFTER processed_count',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @auto_created_count_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
      AND column_name = 'auto_created_count'
);

SET @sql := IF(
    @email_fetch_log_exists > 0 AND @auto_created_count_exists = 0,
    'ALTER TABLE email_fetch_log ADD COLUMN auto_created_count INT NOT NULL DEFAULT 0 AFTER skipped_count',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @error_count_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
      AND column_name = 'error_count'
);

SET @sql := IF(
    @email_fetch_log_exists > 0 AND @error_count_exists = 0,
    'ALTER TABLE email_fetch_log ADD COLUMN error_count INT NOT NULL DEFAULT 0 AFTER auto_created_count',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @initiated_by_user_id_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
      AND column_name = 'initiated_by_user_id'
);

SET @sql := IF(
    @email_fetch_log_exists > 0 AND @initiated_by_user_id_exists = 0,
    'ALTER TABLE email_fetch_log ADD COLUMN initiated_by_user_id INT NULL AFTER error_count',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @source_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
      AND column_name = 'source'
);

SET @sql := IF(
    @email_fetch_log_exists > 0 AND @source_exists = 0,
    'ALTER TABLE email_fetch_log ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT ''manual'' AFTER initiated_by_user_id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_email_fetch_log_source_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
      AND index_name = 'idx_email_fetch_log_source'
);

SET @sql := IF(
    @email_fetch_log_exists > 0 AND @idx_email_fetch_log_source_exists = 0,
    'CREATE INDEX idx_email_fetch_log_source ON email_fetch_log (source)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_email_fetch_log_initiated_by_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
      AND index_name = 'idx_email_fetch_log_initiated_by'
);

SET @sql := IF(
    @email_fetch_log_exists > 0 AND @idx_email_fetch_log_initiated_by_exists = 0,
    'CREATE INDEX idx_email_fetch_log_initiated_by ON email_fetch_log (initiated_by_user_id)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS email_fetch_log_details (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fetch_log_id INT NOT NULL,
    message_uid BIGINT NULL,
    message_id VARCHAR(255) NULL,
    sender_email VARCHAR(255) NULL,
    sender_name VARCHAR(255) NULL,
    subject_preview VARCHAR(255) NULL,
    outcome ENUM('processed','skipped','auto_created','error') NOT NULL,
    reason_code VARCHAR(100) NULL,
    reason_message TEXT NULL,
    contact_id INT NULL,
    communication_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_fetch_log_details_contact
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_email_fetch_log_details_communication
        FOREIGN KEY (communication_id) REFERENCES communications(id) ON DELETE SET NULL,
    INDEX idx_email_fetch_log_details_fetch_log (fetch_log_id),
    INDEX idx_email_fetch_log_details_outcome_created (outcome, created_at),
    INDEX idx_email_fetch_log_details_sender_email (sender_email),
    INDEX idx_email_fetch_log_details_reason_code (reason_code),
    INDEX idx_email_fetch_log_details_contact (contact_id),
    INDEX idx_email_fetch_log_details_communication (communication_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
