-- Marketing Phase 40: live channel connector framework.
-- Stores connector readiness and dry-run diagnostics only. Secrets must stay outside visible diagnostics.

CREATE TABLE IF NOT EXISTS marketing_channel_connectors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(180) NOT NULL,
    connector_type ENUM('email','whatsapp','sms','linkedin','facebook_instagram','x','youtube','ads','website_webhook','other') NOT NULL DEFAULT 'other',
    status ENUM('draft','configured','ready','blocked','archived') NOT NULL DEFAULT 'draft',
    execution_mode ENUM('test','dry_run','live_disabled') NOT NULL DEFAULT 'dry_run',
    capabilities_json JSON NULL,
    config_json JSON NULL,
    diagnostics_json JSON NULL,
    last_tested_at DATETIME NULL,
    owner_user_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_channel_connectors_uuid (uuid),
    KEY idx_marketing_channel_connectors_workspace_type (workspace_id, connector_type, status),
    KEY idx_marketing_channel_connectors_workspace_status (workspace_id, status, updated_at),
    KEY idx_marketing_channel_connectors_workspace_owner (workspace_id, owner_user_id),
    CONSTRAINT fk_marketing_channel_connectors_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_channel_connectors_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_channel_connectors_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_channel_connector_test_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    connector_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    status ENUM('pending','passed','failed','blocked') NOT NULL DEFAULT 'pending',
    test_mode ENUM('diagnostics','dry_run','capability_check') NOT NULL DEFAULT 'diagnostics',
    input_json JSON NULL,
    result_json JSON NULL,
    diagnostics_json JSON NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_channel_connector_test_runs_uuid (uuid),
    KEY idx_marketing_connector_test_runs_workspace_connector (workspace_id, connector_id, created_at),
    KEY idx_marketing_connector_test_runs_workspace_status (workspace_id, status, created_at),
    CONSTRAINT fk_marketing_connector_test_runs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_connector_test_runs_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_connector_test_runs_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
