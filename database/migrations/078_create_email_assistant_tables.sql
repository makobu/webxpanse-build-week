-- Email Assistant Tables
-- Stores admin-to-system email threads and digest send log

CREATE TABLE IF NOT EXISTS email_assistant_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(36) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    from_email VARCHAR(255) NOT NULL,
    to_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) DEFAULT NULL,
    body TEXT NOT NULL,
    body_html TEXT DEFAULT NULL,
    message_id VARCHAR(255) DEFAULT NULL,
    in_reply_to VARCHAR(255) DEFAULT NULL,
    direction ENUM('inbound','outbound') NOT NULL,
    intent VARCHAR(50) DEFAULT NULL,
    command_result JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_message_id (message_id),
    INDEX idx_in_reply_to (in_reply_to),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_digest_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    sent_at DATETIME NOT NULL,
    status ENUM('success','failed') DEFAULT 'success',
    error_message TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_sent_at (sent_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
