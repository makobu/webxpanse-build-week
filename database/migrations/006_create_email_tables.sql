-- Email Tables
CREATE TABLE IF NOT EXISTS emails (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(36) UNIQUE NOT NULL,
    contact_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    to_email VARCHAR(255) NOT NULL,
    from_email VARCHAR(255) NOT NULL,
    from_name VARCHAR(255),
    subject VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    body_html TEXT,
    status ENUM('pending','sent','delivered','opened','clicked','bounced','failed') DEFAULT 'pending',
    sent_at DATETIME DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,
    opened_at DATETIME DEFAULT NULL,
    clicked_at DATETIME DEFAULT NULL,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contact_id (contact_id),
    INDEX idx_user_id (user_id),
    INDEX idx_contact_emails (contact_id, created_at DESC),
    INDEX idx_status (status),
    INDEX idx_to_email (to_email),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_tracking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email_id INT NOT NULL,
    tracking_type ENUM('open','click') NOT NULL,
    clicked_url VARCHAR(500),
    ip_address VARCHAR(45),
    user_agent TEXT,
    tracked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_id (email_id),
    INDEX idx_email_tracking (email_id, tracked_at DESC),
    FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email_id INT NOT NULL,
    priority INT DEFAULT 0,
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    scheduled_at DATETIME DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_id_fk (email_id),
    INDEX idx_queue_status (status, priority DESC, scheduled_at),
    INDEX idx_email_queue (email_id),
    FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
