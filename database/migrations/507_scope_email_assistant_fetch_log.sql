-- Ensure assistant inbox fetch history is tracked per workspace.

CREATE TABLE IF NOT EXISTS email_assistant_fetch_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL DEFAULT 1,
    last_uid INT DEFAULT NULL,
    last_fetch_at DATETIME DEFAULT NULL,
    emails_fetched INT NOT NULL DEFAULT 0,
    emails_total_found INT NOT NULL DEFAULT 0,
    processed_count INT NOT NULL DEFAULT 0,
    skipped_count INT NOT NULL DEFAULT 0,
    auto_created_count INT NOT NULL DEFAULT 0,
    error_count INT NOT NULL DEFAULT 0,
    status ENUM('running','success','partial','failed') NOT NULL DEFAULT 'success',
    error_message TEXT DEFAULT NULL,
    initiated_by_user_id INT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'assistant',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_assistant_fetch_log_workspace_fetch (workspace_id, last_fetch_at),
    INDEX idx_email_assistant_fetch_log_workspace_status (workspace_id, status, last_fetch_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE email_assistant_fetch_log
    ADD COLUMN IF NOT EXISTS workspace_id INT NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE email_assistant_fetch_log
    ADD KEY IF NOT EXISTS idx_email_assistant_fetch_log_workspace_fetch (workspace_id, last_fetch_at),
    ADD KEY IF NOT EXISTS idx_email_assistant_fetch_log_workspace_status (workspace_id, status, last_fetch_at);
