CREATE TABLE IF NOT EXISTS whatsapp_assistant_authorized_numbers (
    id INT NOT NULL AUTO_INCREMENT,
    phone_number VARCHAR(32) NOT NULL,
    user_id INT NOT NULL,
    label VARCHAR(120) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    digest_enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_whatsapp_assistant_phone_number (phone_number),
    KEY idx_whatsapp_assistant_user_id (user_id),
    KEY idx_whatsapp_assistant_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_assistant_messages (
    id INT NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    user_id INT DEFAULT NULL,
    authorized_number_id INT DEFAULT NULL,
    phone_number VARCHAR(32) NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    message_type VARCHAR(32) NOT NULL DEFAULT 'text',
    message_body TEXT NOT NULL,
    intent VARCHAR(80) DEFAULT NULL,
    command_result_json LONGTEXT DEFAULT NULL,
    whatsapp_message_id VARCHAR(191) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'received',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_whatsapp_assistant_uuid (uuid),
    KEY idx_whatsapp_assistant_messages_user_id (user_id),
    KEY idx_whatsapp_assistant_messages_phone (phone_number),
    KEY idx_whatsapp_assistant_messages_direction (direction),
    KEY idx_whatsapp_assistant_messages_status (status),
    KEY idx_whatsapp_assistant_messages_whatsapp_id (whatsapp_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_assistant_digest_log (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    phone_number VARCHAR(32) NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL,
    error_message TEXT DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_whatsapp_assistant_digest_user_day (user_id, sent_at),
    KEY idx_whatsapp_assistant_digest_phone (phone_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
