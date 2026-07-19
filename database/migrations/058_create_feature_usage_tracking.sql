-- Feature usage tracking for better AI recommendations (optional analytics)
CREATE TABLE IF NOT EXISTS feature_usage_tracking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    feature_name VARCHAR(100) NOT NULL,
    usage_count INT DEFAULT 1,
    last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_feature (user_id, feature_name),
    INDEX idx_user_id (user_id),
    INDEX idx_feature_name (feature_name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
