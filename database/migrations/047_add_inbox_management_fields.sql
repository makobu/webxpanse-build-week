-- Add inbox management fields to communications table
-- Adding columns without AFTER clause to avoid dependency issues

ALTER TABLE communications ADD COLUMN archived_at DATETIME NULL;

ALTER TABLE communications ADD COLUMN deleted_at DATETIME NULL;

ALTER TABLE communications ADD COLUMN email_id INT NULL;

ALTER TABLE communications ADD COLUMN message_id VARCHAR(255) NULL;

ALTER TABLE communications ADD COLUMN in_reply_to VARCHAR(255) NULL;

ALTER TABLE communications ADD COLUMN from_email VARCHAR(255) NULL;

ALTER TABLE communications ADD COLUMN to_email VARCHAR(255) NULL;

-- Add indexes
CREATE INDEX idx_archived_at ON communications(archived_at);

CREATE INDEX idx_deleted_at ON communications(deleted_at);

CREATE INDEX idx_message_id ON communications(message_id);

CREATE INDEX idx_email_id ON communications(email_id);

CREATE INDEX idx_from_email ON communications(from_email);

-- Foreign key communications.email_id -> emails.id is added by database/migrations/verify_inbox_columns.php
-- (after cleaning orphaned refs and aligning column type) to avoid errno 150 on some MySQL configs.

-- Create email_fetch_log table
CREATE TABLE IF NOT EXISTS email_fetch_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    last_uid INT DEFAULT NULL,
    last_fetch_at DATETIME DEFAULT NULL,
    emails_fetched INT DEFAULT 0,
    status ENUM('success','failed') DEFAULT 'success',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_last_fetch_at (last_fetch_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
