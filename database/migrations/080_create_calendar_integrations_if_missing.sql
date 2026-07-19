-- Fix: Create calendar_integrations if missing (045 may have been marked executed without applying)
CREATE TABLE IF NOT EXISTS calendar_integrations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    provider ENUM('google', 'outlook', 'ical') NOT NULL,
    access_token TEXT NULL,
    refresh_token TEXT NULL,
    token_expires_at TIMESTAMP NULL,
    calendar_id VARCHAR(255) NULL,
    calendar_name VARCHAR(255) NULL,
    sync_enabled TINYINT(1) DEFAULT 1,
    sync_direction ENUM('both', 'to_crm', 'from_crm') DEFAULT 'both',
    last_sync_at TIMESTAMP NULL,
    settings JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_provider (provider),
    INDEX idx_sync_enabled (sync_enabled),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
