CREATE TABLE IF NOT EXISTS workspace_ai_provider_configs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    provider_key VARCHAR(64) NOT NULL DEFAULT 'openai',
    mode ENUM('disabled', 'enabled') NOT NULL DEFAULT 'enabled',
    api_url VARCHAR(500) NULL,
    model VARCHAR(120) NULL,
    encrypted_api_key MEDIUMTEXT NULL,
    api_key_fingerprint VARCHAR(64) NULL,
    shared_daily_token_cap INT NOT NULL DEFAULT 0,
    last_verified_at DATETIME NULL,
    last_error TEXT NULL,
    settings_json JSON NULL,
    created_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_ai_provider_config_workspace (workspace_id),
    KEY idx_workspace_ai_provider_configs_mode (mode, updated_at),
    CONSTRAINT fk_workspace_ai_provider_configs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_ai_provider_configs_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_workspace_ai_provider_configs_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE workspace_ai_usage
    ADD COLUMN IF NOT EXISTS provider_source ENUM('env', 'default_workspace', 'workspace_api', 'local_fallback') NOT NULL DEFAULT 'env' AFTER provider,
    ADD COLUMN IF NOT EXISTS provider_config_workspace_id INT NULL AFTER provider_source,
    ADD KEY IF NOT EXISTS idx_workspace_ai_usage_source_today (workspace_id, provider_source, created_at),
    ADD KEY IF NOT EXISTS idx_workspace_ai_usage_provider_config (provider_config_workspace_id, created_at);

ALTER TABLE billing_checkout_sessions
    MODIFY COLUMN checkout_type ENUM('subscription', 'token_pack', 'mixed', 'donation') NOT NULL DEFAULT 'subscription',
    ADD KEY IF NOT EXISTS idx_billing_checkout_donation (workspace_id, checkout_type, status, created_at);

ALTER TABLE billing_transactions
    MODIFY COLUMN transaction_type ENUM('subscription_charge', 'token_pack_purchase', 'donation', 'refund', 'manual_adjustment') NOT NULL,
    ADD KEY IF NOT EXISTS idx_billing_transactions_donation (workspace_id, transaction_type, transaction_status, created_at);
