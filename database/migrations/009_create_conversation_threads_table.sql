-- Conversation Threading
CREATE TABLE IF NOT EXISTS conversation_threads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    contact_id INT NOT NULL,
    channel ENUM('email','whatsapp','sms','web_chat') NOT NULL,
    last_message_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    message_count INT DEFAULT 1,
    is_resolved BOOLEAN DEFAULT FALSE,
    resolved_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contact_id (contact_id),
    UNIQUE KEY unique_thread (contact_id, channel),
    INDEX idx_last_message (last_message_at DESC),
    INDEX idx_resolved (is_resolved),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
