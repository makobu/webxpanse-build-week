-- Marketing Phase 97: live connector credential control plane.
-- Adds local verification, revocation, and audit evidence for encrypted connector secrets.

ALTER TABLE marketing_live_connector_secrets
    ADD COLUMN IF NOT EXISTS last_verification_status VARCHAR(40) NULL AFTER last_verified_at,
    ADD COLUMN IF NOT EXISTS rotated_at DATETIME NULL AFTER last_verification_status,
    ADD COLUMN IF NOT EXISTS revoked_at DATETIME NULL AFTER rotated_at,
    ADD COLUMN IF NOT EXISTS revoked_by INT NULL AFTER revoked_at,
    ADD COLUMN IF NOT EXISTS verified_by INT NULL AFTER revoked_by,
    ADD INDEX IF NOT EXISTS idx_marketing_live_connector_secrets_verified (workspace_id, connector_id, last_verification_status, last_verified_at),
    ADD INDEX IF NOT EXISTS idx_marketing_live_connector_secrets_revoked (workspace_id, connector_id, revoked_at);

CREATE TABLE IF NOT EXISTS marketing_live_connector_secret_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    connector_id INT NOT NULL,
    secret_id INT NULL,
    uuid CHAR(36) NOT NULL,
    event_type ENUM('saved','rotated','verified','verification_failed','revoked') NOT NULL,
    status ENUM('info','passed','failed','blocked') NOT NULL DEFAULT 'info',
    message VARCHAR(500) NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_connector_secret_events_uuid (uuid),
    KEY idx_marketing_live_connector_secret_events_connector (workspace_id, connector_id, created_at),
    KEY idx_marketing_live_connector_secret_events_secret (workspace_id, secret_id, event_type),
    CONSTRAINT fk_marketing_live_connector_secret_event_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_connector_secret_event_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_connector_secret_event_secret FOREIGN KEY (secret_id) REFERENCES marketing_live_connector_secrets(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_live_connector_secret_event_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
