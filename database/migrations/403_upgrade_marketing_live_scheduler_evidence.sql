-- Marketing live execution: production scheduler validation evidence.
-- Captures the scheduler state for launcher, orchestrator, dispatcher,
-- channel/email workers, webhook evidence, and outcome sync without storing
-- secrets or raw payloads.

CREATE TABLE IF NOT EXISTS marketing_live_scheduler_validations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    worker_key VARCHAR(120) NOT NULL,
    label VARCHAR(160) NOT NULL,
    status ENUM('ready','action_needed','stale','blocked','unknown') NOT NULL DEFAULT 'unknown',
    expected_schedule VARCHAR(190) NULL,
    last_observed_run_at DATETIME NULL,
    stale_threshold_minutes INT NOT NULL DEFAULT 30,
    lease_state VARCHAR(80) NULL,
    heartbeat_age_seconds INT NULL,
    command_hint VARCHAR(255) NULL,
    recommended_action VARCHAR(255) NULL,
    evidence_json JSON NULL,
    validated_by INT NULL,
    validated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_scheduler_validation_uuid (uuid),
    UNIQUE KEY uniq_marketing_live_scheduler_validation_workspace_worker (workspace_id, worker_key),
    KEY idx_marketing_live_scheduler_validation_status (workspace_id, status, validated_at),
    CONSTRAINT fk_marketing_live_scheduler_validation_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_scheduler_validation_user FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
