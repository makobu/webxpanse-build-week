-- Marketing Phase 84: live worker lease and heartbeat safety.

CREATE TABLE IF NOT EXISTS marketing_live_worker_leases (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    worker_key VARCHAR(120) NOT NULL,
    run_id INT NULL,
    lease_token CHAR(36) NULL,
    source ENUM('cli','cron','api','manual','test') NOT NULL DEFAULT 'cli',
    status ENUM('running','released','expired','blocked') NOT NULL DEFAULT 'released',
    acquired_at DATETIME NULL,
    heartbeat_at DATETIME NULL,
    expires_at DATETIME NULL,
    released_at DATETIME NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_worker_lease_uuid (uuid),
    UNIQUE KEY uniq_marketing_live_worker_lease_workspace_worker (workspace_id, worker_key),
    KEY idx_marketing_live_worker_lease_workspace_status (workspace_id, status, expires_at),
    KEY idx_marketing_live_worker_lease_run (run_id),
    CONSTRAINT fk_marketing_live_worker_lease_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_worker_lease_run FOREIGN KEY (run_id) REFERENCES marketing_live_email_worker_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
