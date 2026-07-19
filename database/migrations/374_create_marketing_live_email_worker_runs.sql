-- Marketing Phase 82: live email handoff worker run audit.
-- Processes remain worker/CLI-owned. Authenticated pages only show readiness and evidence.

CREATE TABLE IF NOT EXISTS marketing_live_email_worker_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    source ENUM('cli','cron','api','manual','test') NOT NULL DEFAULT 'cli',
    status ENUM('running','completed','completed_with_errors','blocked','failed') NOT NULL DEFAULT 'running',
    limit_count INT NOT NULL DEFAULT 25,
    processed_count INT NOT NULL DEFAULT 0,
    sent_count INT NOT NULL DEFAULT 0,
    blocked_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    skipped_count INT NOT NULL DEFAULT 0,
    result_json JSON NULL,
    error_message TEXT NULL,
    requested_by INT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_email_worker_runs_uuid (uuid),
    KEY idx_marketing_live_email_worker_runs_workspace_status (workspace_id, status, started_at),
    KEY idx_marketing_live_email_worker_runs_workspace_source (workspace_id, source, started_at),
    CONSTRAINT fk_marketing_live_email_worker_runs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_email_worker_runs_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_live_email_handoffs
    ADD COLUMN IF NOT EXISTS worker_run_id INT NULL AFTER controlled_at,
    ADD INDEX IF NOT EXISTS idx_marketing_live_email_handoffs_worker_run (workspace_id, worker_run_id, status),
    ADD CONSTRAINT fk_marketing_live_email_handoff_worker_run FOREIGN KEY (worker_run_id) REFERENCES marketing_live_email_worker_runs(id) ON DELETE SET NULL;
