-- Marketing Phase 81: live email operator controls and worker-side suppression safety.

ALTER TABLE marketing_live_email_handoffs
    MODIFY COLUMN status ENUM('queued','processing','sent','cancelled','skipped','failed','blocked') NOT NULL DEFAULT 'queued',
    ADD COLUMN IF NOT EXISTS operator_status ENUM('queued','reviewed','retry_requested','cancelled','blocked','completed') NOT NULL DEFAULT 'queued' AFTER status,
    ADD COLUMN IF NOT EXISTS last_email_status VARCHAR(40) NULL AFTER operator_status,
    ADD COLUMN IF NOT EXISTS last_queue_status VARCHAR(40) NULL AFTER last_email_status,
    ADD COLUMN IF NOT EXISTS last_checked_at DATETIME NULL AFTER last_queue_status,
    ADD COLUMN IF NOT EXISTS suppression_rechecked_at DATETIME NULL AFTER last_checked_at,
    ADD COLUMN IF NOT EXISTS operator_note TEXT NULL AFTER suppression_rechecked_at,
    ADD COLUMN IF NOT EXISTS controlled_by INT NULL AFTER operator_note,
    ADD COLUMN IF NOT EXISTS controlled_at DATETIME NULL AFTER controlled_by,
    ADD INDEX IF NOT EXISTS idx_marketing_live_email_handoffs_operator (workspace_id, operator_status, status),
    ADD INDEX IF NOT EXISTS idx_marketing_live_email_handoffs_email_queue (workspace_id, email_queue_id, status),
    ADD CONSTRAINT fk_marketing_live_email_handoff_controlled_by FOREIGN KEY (controlled_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS marketing_live_email_handoff_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    handoff_id INT NOT NULL,
    queue_id INT NULL,
    email_run_id INT NULL,
    event_type ENUM('status_synced','suppression_rechecked','retry_requested','cancelled','blocked','sent','failed','operator_note') NOT NULL DEFAULT 'status_synced',
    status ENUM('info','success','blocked','failed') NOT NULL DEFAULT 'info',
    message VARCHAR(500) NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_email_handoff_events_uuid (uuid),
    KEY idx_marketing_live_email_handoff_events_workspace_handoff (workspace_id, handoff_id, created_at),
    KEY idx_marketing_live_email_handoff_events_workspace_type (workspace_id, event_type, created_at),
    CONSTRAINT fk_marketing_live_email_handoff_events_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_email_handoff_events_handoff FOREIGN KEY (handoff_id) REFERENCES marketing_live_email_handoffs(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_email_handoff_events_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

