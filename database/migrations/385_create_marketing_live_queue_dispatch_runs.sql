-- Marketing Phase 94: controlled live queue dispatcher.
-- Lets managers pre-confirm approved live queue items for a due-item dispatcher while preserving all live safety gates.

CREATE TABLE IF NOT EXISTS marketing_live_queue_dispatch_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    source ENUM('cli','cron','api','manual','test') NOT NULL DEFAULT 'manual',
    status ENUM('running','completed','completed_with_errors','blocked','failed') NOT NULL DEFAULT 'running',
    limit_count INT NOT NULL DEFAULT 25,
    processed_count INT NOT NULL DEFAULT 0,
    succeeded_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    blocked_count INT NOT NULL DEFAULT 0,
    skipped_count INT NOT NULL DEFAULT 0,
    result_json JSON NULL,
    error_message TEXT NULL,
    requested_by INT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_queue_dispatch_runs_uuid (uuid),
    KEY idx_marketing_live_queue_dispatch_workspace_status (workspace_id, status, created_at),
    KEY idx_marketing_live_queue_dispatch_workspace_source (workspace_id, source, created_at),
    CONSTRAINT fk_marketing_live_queue_dispatch_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_queue_dispatch_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_execution_queue
    ADD COLUMN IF NOT EXISTS live_dispatch_confirmed_at DATETIME NULL AFTER live_last_confirmation_text,
    ADD COLUMN IF NOT EXISTS live_dispatch_confirmed_by INT NULL AFTER live_dispatch_confirmed_at,
    ADD COLUMN IF NOT EXISTS live_dispatch_status ENUM('not_ready','confirmed','dispatched','completed','failed','blocked','cancelled') NOT NULL DEFAULT 'not_ready' AFTER live_dispatch_confirmed_by,
    ADD COLUMN IF NOT EXISTS live_dispatch_run_id INT NULL AFTER live_dispatch_status,
    ADD INDEX IF NOT EXISTS idx_marketing_execution_queue_live_dispatch (workspace_id, execution_mode, live_dispatch_status, scheduled_at),
    ADD INDEX IF NOT EXISTS idx_marketing_execution_queue_live_dispatch_run (workspace_id, live_dispatch_run_id);
