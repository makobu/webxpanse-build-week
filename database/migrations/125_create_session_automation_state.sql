CREATE TABLE IF NOT EXISTS session_automation_state (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    feature_key VARCHAR(100) NOT NULL,
    last_status ENUM('idle', 'running', 'ok', 'skipped', 'failed') NOT NULL DEFAULT 'idle',
    last_attempt_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_result_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_session_automation_user_feature (user_id, feature_key),
    INDEX idx_session_automation_user_attempt (user_id, last_attempt_at),
    INDEX idx_session_automation_feature_attempt (feature_key, last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
