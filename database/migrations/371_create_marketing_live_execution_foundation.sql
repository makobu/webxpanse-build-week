-- Marketing Phase 79: controlled live execution foundation.
-- Live execution remains opt-in, policy-gated, approval-gated, and adapter-gated.

CREATE TABLE IF NOT EXISTS marketing_live_execution_policies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    status ENUM('disabled','test_only','live_ready','blocked') NOT NULL DEFAULT 'disabled',
    live_execution_enabled TINYINT(1) NOT NULL DEFAULT 0,
    require_manage_approval TINYINT(1) NOT NULL DEFAULT 1,
    allowed_connector_types_json JSON NULL,
    allowed_adapter_keys_json JSON NULL,
    daily_live_cap INT NOT NULL DEFAULT 0,
    safety_checklist_json JSON NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_execution_policies_uuid (uuid),
    UNIQUE KEY uniq_marketing_live_execution_policies_workspace (workspace_id),
    KEY idx_marketing_live_execution_policies_status (workspace_id, status),
    CONSTRAINT fk_marketing_live_execution_policies_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_execution_policies_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_live_execution_policies_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_channel_connectors
    ADD COLUMN IF NOT EXISTS live_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER execution_mode,
    ADD COLUMN IF NOT EXISTS live_adapter_key VARCHAR(120) NULL AFTER live_enabled,
    ADD COLUMN IF NOT EXISTS secret_reference VARCHAR(190) NULL AFTER live_adapter_key,
    ADD COLUMN IF NOT EXISTS secret_status ENUM('not_configured','referenced','verified','expired','blocked') NOT NULL DEFAULT 'not_configured' AFTER secret_reference,
    ADD COLUMN IF NOT EXISTS last_live_check_at DATETIME NULL AFTER last_tested_at;

ALTER TABLE marketing_execution_queue
    ADD COLUMN IF NOT EXISTS live_policy_snapshot_json JSON NULL AFTER readiness_json,
    ADD COLUMN IF NOT EXISTS live_blocked_reason TEXT NULL AFTER live_policy_snapshot_json,
    ADD COLUMN IF NOT EXISTS live_approved_at DATETIME NULL AFTER live_blocked_reason;

CREATE TABLE IF NOT EXISTS marketing_live_execution_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    connector_id INT NULL,
    queue_id INT NULL,
    event_type ENUM('policy_updated','readiness_checked','live_blocked','live_attempt_recorded','adapter_missing','adapter_ready','live_succeeded','live_failed') NOT NULL DEFAULT 'readiness_checked',
    status ENUM('info','ready','blocked','success','failed') NOT NULL DEFAULT 'info',
    execution_mode ENUM('test','dry_run','live') NOT NULL DEFAULT 'live',
    adapter_key VARCHAR(120) NULL,
    payload_json JSON NULL,
    result_json JSON NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_execution_events_uuid (uuid),
    KEY idx_marketing_live_execution_events_workspace_type (workspace_id, event_type, created_at),
    KEY idx_marketing_live_execution_events_workspace_connector (workspace_id, connector_id, created_at),
    KEY idx_marketing_live_execution_events_workspace_queue (workspace_id, queue_id, created_at),
    CONSTRAINT fk_marketing_live_execution_events_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_execution_events_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_live_execution_events_queue FOREIGN KEY (queue_id) REFERENCES marketing_execution_queue(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_live_execution_events_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
