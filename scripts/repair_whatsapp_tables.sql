-- Repair: Recreate whatsapp_messages and whatsapp_queue if missing
-- Run this if the table was accidentally dropped (e.g. after bulk delete)

-- Drop queue first (it has FK to messages)
DROP TABLE IF EXISTS whatsapp_queue;

-- Recreate whatsapp_messages with full schema (007 + 061 + 091)
CREATE TABLE IF NOT EXISTS whatsapp_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(36) UNIQUE NOT NULL,
    contact_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    to_number VARCHAR(20) NOT NULL,
    from_number VARCHAR(20),
    message_type ENUM('text','template','image','document','audio','video','sticker','reaction') DEFAULT 'text',
    message_body TEXT,
    template_name VARCHAR(100),
    template_params JSON,
    whatsapp_message_id VARCHAR(100),
    status ENUM('pending','sent','delivered','read','failed') DEFAULT 'pending',
    sent_at DATETIME DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,
    read_at DATETIME DEFAULT NULL,
    error_message TEXT,
    direction ENUM('outbound','inbound') DEFAULT 'outbound',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    campaign_id INT NULL,
    INDEX idx_contact_id (contact_id),
    INDEX idx_user_id (user_id),
    INDEX idx_contact_messages (contact_id, created_at DESC),
    INDEX idx_status (status),
    INDEX idx_whatsapp_id (whatsapp_message_id),
    INDEX idx_whatsapp_messages_campaign (campaign_id),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recreate whatsapp_queue
CREATE TABLE IF NOT EXISTS whatsapp_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    message_id INT NOT NULL,
    priority INT DEFAULT 0,
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    scheduled_at DATETIME DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message_id (message_id),
    INDEX idx_queue_status (status, priority DESC, scheduled_at),
    FOREIGN KEY (message_id) REFERENCES whatsapp_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
