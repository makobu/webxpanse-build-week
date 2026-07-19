CREATE TABLE IF NOT EXISTS cold_outreach_warmup_config (
    channel VARCHAR(20) PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    initial_daily_cold_limit INT NOT NULL DEFAULT 10,
    current_daily_cold_limit INT NOT NULL DEFAULT 10,
    auto_admin_warmup_enabled TINYINT(1) NOT NULL DEFAULT 0,
    weekly_increment INT NOT NULL DEFAULT 5,
    max_limit INT NOT NULL DEFAULT 50,
    last_auto_adjusted_at DATETIME NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_cold_outreach_channel CHECK (channel IN ('email', 'whatsapp'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cold_outreach_warmup_config (
    channel,
    enabled,
    initial_daily_cold_limit,
    current_daily_cold_limit,
    auto_admin_warmup_enabled,
    weekly_increment,
    max_limit,
    last_auto_adjusted_at,
    updated_by
) VALUES
    ('email', 0, 10, 10, 0, 5, 50, NULL, NULL),
    ('whatsapp', 0, 10, 10, 0, 5, 50, NULL, NULL)
ON DUPLICATE KEY UPDATE
    updated_at = CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS cold_outreach_reservations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    channel VARCHAR(20) NOT NULL,
    contact_id INT NOT NULL,
    reserved_for_date DATE NOT NULL,
    scheduled_at DATETIME NOT NULL,
    reservation_status VARCHAR(20) NOT NULL DEFAULT 'reserved',
    source_type VARCHAR(50) NOT NULL DEFAULT 'manual',
    source_id INT NULL,
    source_uuid CHAR(36) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cold_outreach_channel_date (channel, reserved_for_date, reservation_status),
    INDEX idx_cold_outreach_contact_channel (contact_id, channel),
    INDEX idx_cold_outreach_source (source_type, source_id),
    INDEX idx_cold_outreach_source_uuid (source_uuid),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cold_outreach_warmup_adjustments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    channel VARCHAR(20) NOT NULL,
    action_type VARCHAR(20) NOT NULL DEFAULT 'skip',
    previous_limit INT NOT NULL DEFAULT 0,
    new_limit INT NOT NULL DEFAULT 0,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    metadata_json JSON NULL,
    acted_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cold_outreach_adjustments_channel (channel, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
