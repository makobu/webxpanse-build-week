-- Marketing Phase 80: controlled live email queue handoff.
-- This does not send synchronously. It hands approved live email executions to the existing CRM email queue.

CREATE TABLE IF NOT EXISTS marketing_live_email_handoffs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    queue_id INT NOT NULL,
    email_run_id INT NOT NULL,
    connector_id INT NULL,
    email_id INT NULL,
    email_queue_id INT NULL,
    contact_id INT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    status ENUM('queued','skipped','failed') NOT NULL DEFAULT 'queued',
    error_message TEXT NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_email_handoffs_uuid (uuid),
    KEY idx_marketing_live_email_handoffs_workspace_run (workspace_id, email_run_id, status),
    KEY idx_marketing_live_email_handoffs_workspace_queue (workspace_id, queue_id, status),
    KEY idx_marketing_live_email_handoffs_workspace_created (workspace_id, created_at),
    CONSTRAINT fk_marketing_live_email_handoff_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_email_handoff_queue FOREIGN KEY (queue_id) REFERENCES marketing_execution_queue(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_email_handoff_run FOREIGN KEY (email_run_id) REFERENCES marketing_email_campaign_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_email_handoff_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_live_email_handoff_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_email_campaign_runs
    ADD COLUMN IF NOT EXISTS live_handoff_status ENUM('not_started','queued','partially_queued','completed','failed','blocked') NOT NULL DEFAULT 'not_started' AFTER csv_exported_at,
    ADD COLUMN IF NOT EXISTS live_handoff_at DATETIME NULL AFTER live_handoff_status,
    ADD COLUMN IF NOT EXISTS live_handoff_json JSON NULL AFTER live_handoff_at,
    ADD INDEX IF NOT EXISTS idx_marketing_email_runs_live_handoff (workspace_id, live_handoff_status, live_handoff_at);
