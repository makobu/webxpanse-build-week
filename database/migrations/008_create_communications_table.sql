-- Unified Communications Table
CREATE TABLE IF NOT EXISTS communications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(36) UNIQUE NOT NULL,
    contact_id INT NOT NULL,
    channel ENUM('email','whatsapp','sms','web_chat') NOT NULL,
    direction ENUM('inbound','outbound') NOT NULL,
    subject VARCHAR(500),
    body TEXT NOT NULL,
    metadata JSON,
    status ENUM('sent','delivered','read','failed') DEFAULT 'sent',
    read_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contact_id (contact_id),
    INDEX idx_contact_comms (contact_id, created_at DESC),
    INDEX idx_unread (contact_id, read_at),
    INDEX idx_channel (channel),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
