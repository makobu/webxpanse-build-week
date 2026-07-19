-- Marketing live execution: scheduled outcome reconciliation worker.
-- Keeps live queue outcomes current without requiring an operator to open the web UI.

CREATE TABLE IF NOT EXISTS marketing_live_outcome_sync_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    source ENUM('cli','cron','api','manual','test') NOT NULL DEFAULT 'cli',
    status ENUM('running','completed','completed_with_errors','blocked','failed') NOT NULL DEFAULT 'running',
    limit_count INT NOT NULL DEFAULT 250,
    processed_count INT NOT NULL DEFAULT 0,
    delivered_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    blocked_count INT NOT NULL DEFAULT 0,
    queued_count INT NOT NULL DEFAULT 0,
    unknown_count INT NOT NULL DEFAULT 0,
    result_json JSON NULL,
    error_message TEXT NULL,
    requested_by INT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_outcome_sync_runs_uuid (uuid),
    KEY idx_marketing_live_outcome_sync_runs_workspace_status (workspace_id, status, started_at),
    KEY idx_marketing_live_outcome_sync_runs_workspace_source (workspace_id, source, started_at),
    CONSTRAINT fk_marketing_live_outcome_sync_runs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_outcome_sync_runs_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketing_live_worker_schedules
    (workspace_id, uuid, worker_key, status, schedule_label, command, expected_interval_minutes, max_stale_minutes,
     max_handoffs_per_run, min_seconds_between_sends, hourly_send_cap, daily_send_cap, next_check_at, metadata_json)
SELECT w.id,
       UUID(),
       'marketing_live_outcome_sync',
       'planned',
       'Marketing live outcome sync',
       'php scripts/sync_marketing_live_outcomes.php all 250',
       5,
       30,
       250,
       0,
       0,
       0,
       DATE_ADD(NOW(), INTERVAL 5 MINUTE),
       JSON_OBJECT('source', 'migration_394', 'secret_safe', true)
FROM workspaces w
WHERE NOT EXISTS (
    SELECT 1
    FROM marketing_live_worker_schedules s
    WHERE s.workspace_id = w.id
      AND s.worker_key = 'marketing_live_outcome_sync'
);
