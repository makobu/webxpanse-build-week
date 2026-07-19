CREATE TABLE IF NOT EXISTS mobile_auth_challenges (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    challenge_hash CHAR(64) NOT NULL,
    device_id VARCHAR(191) NULL,
    device_name VARCHAR(191) NULL,
    platform VARCHAR(32) NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mobile_auth_challenge_hash (challenge_hash),
    KEY idx_mobile_auth_challenges_user_id (user_id),
    KEY idx_mobile_auth_challenges_expires_at (expires_at),
    CONSTRAINT fk_mobile_auth_challenges_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mobile_auth_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    device_name VARCHAR(191) NULL,
    platform VARCHAR(32) NULL,
    access_token_hash CHAR(64) NOT NULL,
    refresh_token_hash CHAR(64) NOT NULL,
    access_expires_at DATETIME NOT NULL,
    refresh_expires_at DATETIME NOT NULL,
    push_token VARCHAR(255) NULL,
    app_version VARCHAR(64) NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mobile_auth_access_hash (access_token_hash),
    UNIQUE KEY uniq_mobile_auth_refresh_hash (refresh_token_hash),
    KEY idx_mobile_auth_tokens_user_id (user_id),
    KEY idx_mobile_auth_tokens_device_id (device_id),
    KEY idx_mobile_auth_tokens_refresh_expires_at (refresh_expires_at),
    CONSTRAINT fk_mobile_auth_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
