CREATE TABLE IF NOT EXISTS workspace_sms_channel_configs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    provider VARCHAR(50) NOT NULL DEFAULT 'twilio',
    account_sid VARCHAR(255) NULL,
    encrypted_auth_token MEDIUMTEXT NULL,
    auth_token_fingerprint VARCHAR(64) NULL,
    from_number VARCHAR(32) NULL,
    webhook_enabled TINYINT(1) NOT NULL DEFAULT 0,
    status_callbacks_enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_verified_at DATETIME NULL,
    last_error TEXT NULL,
    settings_json JSON NULL,
    created_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_sms_channel_config_workspace (workspace_id),
    KEY idx_workspace_sms_channel_configs_enabled (enabled, updated_at),
    KEY idx_workspace_sms_channel_configs_provider (provider, updated_at),
    CONSTRAINT fk_workspace_sms_channel_configs_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_sms_channel_configs_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_workspace_sms_channel_configs_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
