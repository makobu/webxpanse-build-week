-- Marketing Phase 85: live worker schedule profile and readiness window.

CREATE TABLE IF NOT EXISTS marketing_live_worker_schedules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    worker_key VARCHAR(120) NOT NULL,
    status ENUM('disabled','planned','active','paused') NOT NULL DEFAULT 'planned',
    schedule_label VARCHAR(160) NULL,
    command VARCHAR(255) NOT NULL DEFAULT 'php cli/process_marketing_live_email_handoffs.php all 25',
    expected_interval_minutes INT NOT NULL DEFAULT 5,
    max_stale_minutes INT NOT NULL DEFAULT 30,
    last_confirmed_at DATETIME NULL,
    next_check_at DATETIME NULL,
    confirmed_by INT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_worker_schedule_uuid (uuid),
    UNIQUE KEY uniq_marketing_live_worker_schedule_workspace_worker (workspace_id, worker_key),
    KEY idx_marketing_live_worker_schedule_workspace_status (workspace_id, status, next_check_at),
    CONSTRAINT fk_marketing_live_worker_schedule_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_worker_schedule_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
