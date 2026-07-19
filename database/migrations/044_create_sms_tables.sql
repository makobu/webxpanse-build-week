-- SMS Messages Table
CREATE TABLE IF NOT EXISTS sms_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    contact_id INT NULL,
    user_id INT NULL,
    to_number VARCHAR(20) NOT NULL,
    from_number VARCHAR(20) NOT NULL,
    message_body TEXT NOT NULL,
    message_type ENUM('text', 'media') DEFAULT 'text',
    media_url VARCHAR(500) NULL,
    status ENUM('pending', 'queued', 'sent', 'delivered', 'failed', 'undelivered') DEFAULT 'pending',
    direction ENUM('inbound', 'outbound') DEFAULT 'outbound',
    provider VARCHAR(50) DEFAULT 'twilio',
    provider_message_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    cost DECIMAL(10, 4) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contact_id (contact_id),
    INDEX idx_user_id (user_id),
    INDEX idx_to_number (to_number),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at DESC),
    INDEX idx_provider_message_id (provider_message_id),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS Queue Table
CREATE TABLE IF NOT EXISTS sms_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    message_id INT NOT NULL,
    priority INT DEFAULT 0,
    scheduled_at TIMESTAMP NULL,
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    last_attempt_at TIMESTAMP NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message_id (message_id),
    INDEX idx_status (status),
    INDEX idx_scheduled_at (scheduled_at),
    INDEX idx_priority (priority DESC),
    FOREIGN KEY (message_id) REFERENCES sms_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
