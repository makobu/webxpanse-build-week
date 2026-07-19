-- Marketing live execution: operator recovery event ledger.
-- Records retry, reschedule, cancellation, preflight, suppression, and operator
-- note actions without deleting previous attempts or exposing secrets.

CREATE TABLE IF NOT EXISTS marketing_live_recovery_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    queue_id INT NOT NULL,
    connector_id INT NULL,
    action_type ENUM('retry','reschedule','cancel','pause_connector','rerun_preflight','recheck_suppression','operator_note','request_recovery') NOT NULL,
    status ENUM('requested','completed','blocked','failed') NOT NULL DEFAULT 'completed',
    previous_queue_status VARCHAR(40) NULL,
    previous_dispatch_status VARCHAR(40) NULL,
    next_queue_status VARCHAR(40) NULL,
    next_dispatch_status VARCHAR(40) NULL,
    scheduled_at DATETIME NULL,
    reason TEXT NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_recovery_event_uuid (uuid),
    KEY idx_marketing_live_recovery_workspace_queue (workspace_id, queue_id, created_at),
    KEY idx_marketing_live_recovery_workspace_action (workspace_id, action_type, status, created_at),
    KEY idx_marketing_live_recovery_connector (workspace_id, connector_id, created_at),
    CONSTRAINT fk_marketing_live_recovery_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_recovery_queue FOREIGN KEY (queue_id) REFERENCES marketing_execution_queue(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_recovery_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_live_recovery_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_execution_queue
    ADD COLUMN IF NOT EXISTS live_recovery_status ENUM('none','requested','retry_scheduled','rescheduled','cancelled','blocked','permanent_failed') NOT NULL DEFAULT 'none' AFTER live_retry_json,
    ADD COLUMN IF NOT EXISTS live_recovery_reason TEXT NULL AFTER live_recovery_status,
    ADD COLUMN IF NOT EXISTS live_recovery_json JSON NULL AFTER live_recovery_reason,
    ADD INDEX IF NOT EXISTS idx_marketing_execution_queue_live_recovery (workspace_id, execution_mode, live_recovery_status, updated_at);
