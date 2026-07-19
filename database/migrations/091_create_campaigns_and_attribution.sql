-- Campaign automation and multi-touch attribution foundation

CREATE TABLE IF NOT EXISTS campaigns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(36) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    objective VARCHAR(100) DEFAULT 'nurture',
    channel_mix JSON,
    status ENUM('draft','active','paused','completed','archived') DEFAULT 'draft',
    schedule_type ENUM('immediate','scheduled') DEFAULT 'immediate',
    scheduled_start_at DATETIME NULL,
    scheduled_end_at DATETIME NULL,
    timezone VARCHAR(100) DEFAULT 'UTC',
    attribution_model_default VARCHAR(50) DEFAULT 'last_touch',
    is_active TINYINT(1) DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_campaign_status (status),
    INDEX idx_campaign_active (is_active),
    INDEX idx_campaign_start (scheduled_start_at),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_steps (
    id INT PRIMARY KEY AUTO_INCREMENT,
    campaign_id INT NOT NULL,
    step_order INT NOT NULL,
    step_name VARCHAR(255) NOT NULL,
    action_type ENUM('send_email','send_sms','send_whatsapp','wait','add_tag','remove_tag','create_task','webhook') NOT NULL,
    channel ENUM('email','sms','whatsapp','internal') DEFAULT 'email',
    template_ref VARCHAR(255) NULL,
    subject VARCHAR(500) NULL,
    content TEXT NULL,
    wait_minutes INT DEFAULT 0,
    branch_condition JSON NULL,
    settings JSON NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_campaign_step_order (campaign_id, step_order),
    INDEX idx_campaign_step_action (action_type),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_enrollments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    campaign_id INT NOT NULL,
    contact_id INT NOT NULL,
    status ENUM('active','paused','completed','exited','failed') DEFAULT 'active',
    current_step_order INT DEFAULT 1,
    current_step_id INT NULL,
    next_run_at DATETIME NULL,
    entered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    exit_reason VARCHAR(255) NULL,
    metadata JSON NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_campaign_contact (campaign_id, contact_id),
    INDEX idx_enrollment_status_next (status, next_run_at),
    INDEX idx_enrollment_contact (contact_id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (current_step_id) REFERENCES campaign_steps(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_step_executions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    campaign_id INT NOT NULL,
    enrollment_id INT NOT NULL,
    contact_id INT NOT NULL,
    step_id INT NOT NULL,
    status ENUM('pending','processing','completed','failed','skipped') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    provider_message_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_step_exec_status (status, created_at),
    INDEX idx_step_exec_campaign (campaign_id, contact_id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (enrollment_id) REFERENCES campaign_enrollments(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (step_id) REFERENCES campaign_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    campaign_id INT NOT NULL,
    enrollment_id INT NOT NULL,
    step_id INT NOT NULL,
    contact_id INT NOT NULL,
    execute_at DATETIME NOT NULL,
    status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    error_message TEXT NULL,
    locked_at DATETIME NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign_queue_status_time (status, execute_at),
    INDEX idx_campaign_queue_campaign (campaign_id, enrollment_id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (enrollment_id) REFERENCES campaign_enrollments(id) ON DELETE CASCADE,
    FOREIGN KEY (step_id) REFERENCES campaign_steps(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_audience_snapshots (
    id INT PRIMARY KEY AUTO_INCREMENT,
    campaign_id INT NOT NULL,
    snapshot_name VARCHAR(255) NOT NULL,
    filters JSON NULL,
    contact_ids JSON NULL,
    snapshot_count INT DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_snapshot_campaign (campaign_id, created_at),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_rate_limits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    campaign_id INT NULL,
    channel ENUM('email','sms','whatsapp') NOT NULL,
    domain_pattern VARCHAR(255) NULL,
    per_minute INT DEFAULT 60,
    per_hour INT DEFAULT 1000,
    per_day INT DEFAULT 10000,
    start_hour TINYINT DEFAULT 0,
    end_hour TINYINT DEFAULT 23,
    timezone VARCHAR(100) DEFAULT 'UTC',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rate_limit_channel (channel),
    INDEX idx_rate_limit_campaign (campaign_id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppression_list (
    id INT PRIMARY KEY AUTO_INCREMENT,
    channel ENUM('email','sms','whatsapp') NOT NULL,
    value VARCHAR(255) NOT NULL,
    reason VARCHAR(255) NULL,
    source VARCHAR(100) DEFAULT 'manual',
    campaign_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_suppression_channel_value (channel, value),
    INDEX idx_suppression_campaign (campaign_id),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visitor_identity_links (
    id INT PRIMARY KEY AUTO_INCREMENT,
    visitor_id VARCHAR(100) NOT NULL,
    contact_id INT NOT NULL,
    confidence ENUM('high','medium','low') DEFAULT 'high',
    linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_visitor_contact (visitor_id, contact_id),
    INDEX idx_identity_contact (contact_id),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS touchpoints (
    id INT PRIMARY KEY AUTO_INCREMENT,
    contact_id INT NOT NULL,
    visitor_id VARCHAR(100) NULL,
    campaign_id INT NULL,
    source_table VARCHAR(100) NULL,
    source_id INT NULL,
    channel ENUM('web','form','email','sms','whatsapp','deal','activity','campaign') NOT NULL,
    touch_type VARCHAR(100) NOT NULL,
    occurred_at DATETIME NOT NULL,
    utm_source VARCHAR(100) NULL,
    utm_medium VARCHAR(100) NULL,
    utm_campaign VARCHAR(100) NULL,
    utm_term VARCHAR(100) NULL,
    utm_content VARCHAR(100) NULL,
    value_amount DECIMAL(12,2) NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_touchpoint_contact_time (contact_id, occurred_at),
    INDEX idx_touchpoint_campaign (campaign_id, occurred_at),
    INDEX idx_touchpoint_channel_type (channel, touch_type),
    INDEX idx_touchpoint_utm_campaign (utm_campaign),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attribution_models (
    id INT PRIMARY KEY AUTO_INCREMENT,
    slug VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    settings JSON NULL,
    is_default TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attribution_results (
    id INT PRIMARY KEY AUTO_INCREMENT,
    model_id INT NOT NULL,
    contact_id INT NOT NULL,
    deal_id INT NULL,
    campaign_id INT NULL,
    conversion_event VARCHAR(100) NOT NULL,
    conversion_at DATETIME NOT NULL,
    touchpoint_id INT NULL,
    touch_type VARCHAR(100) NOT NULL,
    channel VARCHAR(50) NOT NULL,
    attribution_weight DECIMAL(8,6) NOT NULL,
    credited_value DECIMAL(12,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attr_contact_conversion (contact_id, conversion_at),
    INDEX idx_attr_deal_model (deal_id, model_id),
    INDEX idx_attr_campaign (campaign_id),
    FOREIGN KEY (model_id) REFERENCES attribution_models(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE SET NULL,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    FOREIGN KEY (touchpoint_id) REFERENCES touchpoints(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE contacts
    ADD COLUMN first_touch_source VARCHAR(100) NULL,
    ADD COLUMN first_touch_campaign VARCHAR(100) NULL,
    ADD COLUMN last_touch_source VARCHAR(100) NULL,
    ADD COLUMN last_touch_campaign VARCHAR(100) NULL,
    ADD INDEX idx_contacts_first_touch (first_touch_source),
    ADD INDEX idx_contacts_last_touch (last_touch_source);

ALTER TABLE page_views
    ADD COLUMN campaign_id INT NULL,
    ADD INDEX idx_page_views_campaign (campaign_id),
    ADD CONSTRAINT fk_page_views_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL;

ALTER TABLE form_submissions
    ADD COLUMN campaign_id INT NULL,
    ADD COLUMN utm_source VARCHAR(100) NULL,
    ADD COLUMN utm_medium VARCHAR(100) NULL,
    ADD COLUMN utm_campaign VARCHAR(100) NULL,
    ADD COLUMN utm_term VARCHAR(100) NULL,
    ADD COLUMN utm_content VARCHAR(100) NULL,
    ADD INDEX idx_form_submissions_campaign (campaign_id),
    ADD CONSTRAINT fk_form_submissions_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL;

ALTER TABLE emails
    ADD COLUMN campaign_id INT NULL,
    ADD INDEX idx_emails_campaign (campaign_id),
    ADD CONSTRAINT fk_emails_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL;

ALTER TABLE sms_messages
    ADD COLUMN campaign_id INT NULL,
    ADD INDEX idx_sms_messages_campaign (campaign_id),
    ADD CONSTRAINT fk_sms_messages_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL;

ALTER TABLE whatsapp_messages
    ADD COLUMN campaign_id INT NULL,
    ADD INDEX idx_whatsapp_messages_campaign (campaign_id),
    ADD CONSTRAINT fk_whatsapp_messages_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL;

ALTER TABLE deals
    ADD COLUMN campaign_id INT NULL,
    ADD INDEX idx_deals_campaign (campaign_id),
    ADD CONSTRAINT fk_deals_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL;

INSERT INTO attribution_models (slug, name, description, settings, is_default, is_active)
VALUES
    ('first_touch', 'First Touch', 'Credits 100% to earliest touchpoint in lookback window.', JSON_OBJECT('lookback_days', 90), 0, 1),
    ('last_touch', 'Last Touch', 'Credits 100% to latest touchpoint before conversion.', JSON_OBJECT('lookback_days', 90), 1, 1),
    ('linear', 'Linear', 'Splits credit equally across all eligible touchpoints.', JSON_OBJECT('lookback_days', 90), 0, 1),
    ('time_decay', 'Time Decay', 'Weights recent touchpoints higher using half-life.', JSON_OBJECT('lookback_days', 90, 'half_life_days', 7), 0, 1),
    ('position_based', 'Position Based', '40/20/40 distribution for first/middle/last touches.', JSON_OBJECT('lookback_days', 90, 'first_weight', 0.4, 'middle_weight', 0.2, 'last_weight', 0.4), 0, 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    settings = VALUES(settings),
    is_active = VALUES(is_active);
