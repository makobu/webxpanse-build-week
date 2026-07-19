CREATE TABLE IF NOT EXISTS email_integrations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider VARCHAR(64) NOT NULL,
    scope VARCHAR(64) NOT NULL,
    access_token MEDIUMTEXT NULL,
    refresh_token MEDIUMTEXT NULL,
    token_expires_at DATETIME NULL,
    email_address VARCHAR(191) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    connected_by_user_id INT NULL,
    settings_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_integrations_provider_scope (provider, scope),
    KEY idx_email_integrations_scope_active (scope, is_active),
    KEY idx_email_integrations_connected_by_user (connected_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
