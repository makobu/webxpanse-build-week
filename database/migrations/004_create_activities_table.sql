-- Activity Timeline
CREATE TABLE IF NOT EXISTS activities (
    id INT PRIMARY KEY AUTO_INCREMENT,
    contact_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    activity_type ENUM('email','call','note','meeting','status_change','form_submit','email_opened','link_clicked','page_visited') NOT NULL,
    description TEXT,
    metadata JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contact_activities (contact_id, created_at DESC),
    INDEX idx_user_activities (user_id, created_at DESC),
    INDEX idx_activity_type (activity_type),
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
