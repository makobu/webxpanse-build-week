-- Marketing Phase 83: live worker health checks and operations diagnostics.

CREATE TABLE IF NOT EXISTS marketing_live_worker_health_checks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    worker_key VARCHAR(120) NOT NULL,
    status ENUM('healthy','attention','stale','blocked','unknown') NOT NULL DEFAULT 'unknown',
    last_run_id INT NULL,
    last_run_at DATETIME NULL,
    queued_count INT NOT NULL DEFAULT 0,
    stale_count INT NOT NULL DEFAULT 0,
    blocked_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    diagnostics_json JSON NULL,
    recommended_actions_json JSON NULL,
    checked_by INT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_worker_health_uuid (uuid),
    UNIQUE KEY uniq_marketing_live_worker_health_workspace_worker (workspace_id, worker_key),
    KEY idx_marketing_live_worker_health_workspace_status (workspace_id, status, checked_at),
    CONSTRAINT fk_marketing_live_worker_health_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_worker_health_last_run FOREIGN KEY (last_run_id) REFERENCES marketing_live_email_worker_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_live_worker_health_checked_by FOREIGN KEY (checked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
