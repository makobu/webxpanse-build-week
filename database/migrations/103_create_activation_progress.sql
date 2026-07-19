-- Outcome Layer: Activation milestone snapshots

CREATE TABLE IF NOT EXISTS activation_progress (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    first_login_at DATETIME NULL,
    connected_channel_at DATETIME NULL,
    first_contact_at DATETIME NULL,
    first_inbound_at DATETIME NULL,
    first_followup_task_completed_at DATETIME NULL,
    first_deal_created_at DATETIME NULL,
    first_deal_advanced_at DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_activation_user (user_id),
    INDEX idx_activation_followup (first_followup_task_completed_at),
    CONSTRAINT fk_activation_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
