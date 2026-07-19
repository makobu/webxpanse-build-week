-- Marketing Phase 96: encrypted live connector secrets.
-- Stores workspace-scoped connector credentials separately from visible connector diagnostics.

CREATE TABLE IF NOT EXISTS marketing_live_connector_secrets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    connector_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    secret_reference VARCHAR(190) NOT NULL,
    secret_label VARCHAR(190) NULL,
    secret_type ENUM('bearer_token','api_key_header','custom_header') NOT NULL DEFAULT 'bearer_token',
    header_name VARCHAR(120) NULL,
    header_prefix VARCHAR(80) NULL,
    encrypted_value MEDIUMTEXT NOT NULL,
    status ENUM('active','rotated','revoked') NOT NULL DEFAULT 'active',
    last_verified_at DATETIME NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_connector_secrets_uuid (uuid),
    UNIQUE KEY uniq_marketing_live_connector_secret_reference (workspace_id, secret_reference),
    KEY idx_marketing_live_connector_secrets_connector (workspace_id, connector_id, status),
    KEY idx_marketing_live_connector_secrets_status (workspace_id, status, updated_at),
    CONSTRAINT fk_marketing_live_connector_secret_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_connector_secret_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_connector_secret_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_live_connector_secret_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
