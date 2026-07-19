-- Add reply-thread metadata to outbound emails and persist reply delivery audit attempts

ALTER TABLE emails
    ADD COLUMN message_id VARCHAR(255) NULL AFTER body_html;

ALTER TABLE emails
    ADD COLUMN in_reply_to VARCHAR(255) NULL AFTER message_id;

ALTER TABLE emails
    ADD COLUMN references_header TEXT NULL AFTER in_reply_to;

CREATE INDEX idx_emails_message_id ON emails(message_id);
CREATE INDEX idx_emails_in_reply_to ON emails(in_reply_to);

CREATE TABLE IF NOT EXISTS email_reply_delivery_audit (
    id INT PRIMARY KEY AUTO_INCREMENT,
    source_communication_id INT NOT NULL,
    email_id INT NULL,
    contact_id INT NULL,
    user_id INT NULL,
    reply_surface VARCHAR(50) NOT NULL DEFAULT 'unknown',
    smtp_profile VARCHAR(50) NOT NULL DEFAULT 'default',
    smtp_method VARCHAR(100) NULL,
    to_email VARCHAR(255) NOT NULL,
    from_email VARCHAR(255) NULL,
    subject VARCHAR(500) NOT NULL,
    generated_message_id VARCHAR(255) NULL,
    in_reply_to VARCHAR(255) NULL,
    references_header TEXT NULL,
    status ENUM('attempted', 'sent', 'failed', 'sync_failed') NOT NULL DEFAULT 'attempted',
    transport_error TEXT NULL,
    details_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_reply_delivery_audit_comm (source_communication_id, created_at),
    INDEX idx_email_reply_delivery_audit_email (email_id, created_at),
    INDEX idx_email_reply_delivery_audit_status (status, created_at),
    INDEX idx_email_reply_delivery_audit_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
