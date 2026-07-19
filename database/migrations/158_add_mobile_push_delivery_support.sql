ALTER TABLE mobile_auth_tokens
    ADD COLUMN push_provider VARCHAR(32) NULL AFTER push_token,
    ADD COLUMN push_preferences_json JSON NULL AFTER push_provider,
    ADD COLUMN device_locale VARCHAR(32) NULL AFTER push_preferences_json,
    ADD COLUMN push_token_updated_at DATETIME NULL AFTER device_locale,
    ADD COLUMN push_disabled_at DATETIME NULL AFTER push_token_updated_at,
    ADD COLUMN last_push_sent_at DATETIME NULL AFTER push_disabled_at,
    ADD COLUMN last_push_error TEXT NULL AFTER last_push_sent_at,
    ADD KEY idx_mobile_auth_tokens_push_provider (push_provider),
    ADD KEY idx_mobile_auth_tokens_push_updated_at (push_token_updated_at);

CREATE TABLE IF NOT EXISTS mobile_push_deliveries (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_id INT NOT NULL,
    notification_id INT NULL,
    provider VARCHAR(32) NOT NULL DEFAULT 'fcm',
    target_route VARCHAR(255) NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    payload_json JSON NULL,
    status ENUM('pending','sent','failed','invalid_token') NOT NULL DEFAULT 'pending',
    provider_message_id VARCHAR(255) NULL,
    provider_error_code VARCHAR(128) NULL,
    provider_error_message TEXT NULL,
    attempted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mobile_push_deliveries_user_id (user_id),
    KEY idx_mobile_push_deliveries_token_id (token_id),
    KEY idx_mobile_push_deliveries_notification_id (notification_id),
    KEY idx_mobile_push_deliveries_status (status),
    CONSTRAINT fk_mobile_push_deliveries_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mobile_push_deliveries_token
        FOREIGN KEY (token_id) REFERENCES mobile_auth_tokens(id) ON DELETE CASCADE,
    CONSTRAINT fk_mobile_push_deliveries_notification
        FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
