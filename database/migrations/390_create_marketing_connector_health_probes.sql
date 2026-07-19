-- Marketing Phase 99: connector health probes and live adapter readiness.
-- Health probes are local diagnostics and do not call external publishing/sending APIs.

ALTER TABLE marketing_channel_connectors
    ADD COLUMN IF NOT EXISTS health_status ENUM('unknown','healthy','degraded','blocked') NOT NULL DEFAULT 'unknown' AFTER readiness_score,
    ADD COLUMN IF NOT EXISTS health_score INT NOT NULL DEFAULT 0 AFTER health_status,
    ADD COLUMN IF NOT EXISTS last_health_probe_at DATETIME NULL AFTER health_score,
    ADD COLUMN IF NOT EXISTS health_probe_json JSON NULL AFTER last_health_probe_at,
    ADD INDEX IF NOT EXISTS idx_marketing_channel_connectors_health (workspace_id, health_status, health_score, last_health_probe_at);

CREATE TABLE IF NOT EXISTS marketing_channel_connector_health_probes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    connector_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    probe_mode ENUM('diagnostics','local_adapter','live_ready') NOT NULL DEFAULT 'diagnostics',
    status ENUM('healthy','degraded','blocked') NOT NULL DEFAULT 'degraded',
    health_score INT NOT NULL DEFAULT 0,
    adapter_key VARCHAR(120) NULL,
    required_checks_json JSON NULL,
    passed_checks_json JSON NULL,
    warnings_json JSON NULL,
    blockers_json JSON NULL,
    diagnostics_json JSON NULL,
    external_api_called TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_connector_health_probes_uuid (uuid),
    KEY idx_marketing_connector_health_workspace_connector (workspace_id, connector_id, created_at),
    KEY idx_marketing_connector_health_workspace_status (workspace_id, status, health_score),
    CONSTRAINT fk_marketing_connector_health_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_connector_health_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_connector_health_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
