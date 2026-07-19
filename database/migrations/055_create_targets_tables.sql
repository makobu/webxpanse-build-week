-- Targets System Tables
-- Allows users to commit to targets, track progress, receive reminders, and get advice

-- Main targets table
CREATE TABLE IF NOT EXISTS targets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    target_type ENUM('sales', 'personal', 'performance', 'custom') DEFAULT 'custom',
    target_value DECIMAL(15, 2) NOT NULL COMMENT 'The goal value to achieve',
    current_value DECIMAL(15, 2) DEFAULT 0 COMMENT 'Current progress toward goal',
    unit VARCHAR(50) DEFAULT '' COMMENT 'Unit of measurement (e.g., $, deals, %, items)',
    start_date DATE DEFAULT NULL,
    target_date DATE NOT NULL COMMENT 'Deadline for the target',
    completed_at DATETIME DEFAULT NULL,
    status ENUM('active', 'completed', 'cancelled', 'missed') DEFAULT 'active',
    reminder_frequency ENUM('daily', 'weekly', 'deadline', 'custom') DEFAULT 'weekly',
    custom_reminder_days INT DEFAULT NULL COMMENT 'For custom frequency: days between reminders',
    last_reminder_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_target_date (target_date),
    INDEX idx_target_type (target_type),
    INDEX idx_user_status (user_id, status),
    INDEX idx_active_targets (status, target_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Target reminders tracking table
CREATE TABLE IF NOT EXISTS target_reminders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    target_id INT NOT NULL,
    reminder_type ENUM('daily', 'weekly', 'deadline_7', 'deadline_3', 'deadline_1', 'custom') NOT NULL,
    scheduled_at DATETIME NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    status ENUM('pending', 'sent', 'cancelled') DEFAULT 'pending',
    notification_id INT DEFAULT NULL COMMENT 'Reference to notifications table if sent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_target_id (target_id),
    INDEX idx_scheduled_at (scheduled_at),
    INDEX idx_status (status),
    INDEX idx_pending_reminders (status, scheduled_at),
    FOREIGN KEY (target_id) REFERENCES targets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Target advice table for AI-generated advice
CREATE TABLE IF NOT EXISTS target_advice (
    id INT PRIMARY KEY AUTO_INCREMENT,
    target_id INT NOT NULL,
    advice_text TEXT NOT NULL,
    advice_type VARCHAR(50) DEFAULT 'general' COMMENT 'Type of advice (motivational, tactical, warning, etc.)',
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_target_id (target_id),
    INDEX idx_generated_at (generated_at DESC),
    FOREIGN KEY (target_id) REFERENCES targets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
