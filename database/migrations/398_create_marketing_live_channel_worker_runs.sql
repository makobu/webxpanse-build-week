-- Marketing live execution: controlled SMS/WhatsApp channel handoff worker.
-- The worker processes only Marketing-created CRM channel queue handoffs under
-- schedule, lease, policy, and outcome-proof controls.

CREATE TABLE IF NOT EXISTS marketing_live_channel_worker_runs (
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
    UNIQUE KEY uniq_marketing_live_channel_worker_runs_uuid (uuid),
    KEY idx_marketing_live_channel_worker_runs_workspace_status (workspace_id, status, started_at),
    KEY idx_marketing_live_channel_worker_runs_workspace_source (workspace_id, source, started_at),
    CONSTRAINT fk_marketing_live_channel_worker_runs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_channel_worker_runs_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_live_channel_handoffs
    ADD COLUMN IF NOT EXISTS worker_run_id INT NULL AFTER status,
    ADD INDEX IF NOT EXISTS idx_marketing_live_channel_handoffs_worker_run (workspace_id, worker_run_id, status),
    ADD CONSTRAINT fk_marketing_live_channel_handoff_worker_run FOREIGN KEY (worker_run_id) REFERENCES marketing_live_channel_worker_runs(id) ON DELETE SET NULL;

ALTER TABLE marketing_live_channel_delivery_proofs
    ADD COLUMN IF NOT EXISTS worker_run_id INT NULL AFTER handoff_id,
    ADD INDEX IF NOT EXISTS idx_marketing_live_channel_delivery_worker_run (workspace_id, worker_run_id, proof_status),
    ADD CONSTRAINT fk_marketing_live_channel_delivery_worker_run FOREIGN KEY (worker_run_id) REFERENCES marketing_live_channel_worker_runs(id) ON DELETE SET NULL;

ALTER TABLE marketing_live_worker_leases
    ADD COLUMN IF NOT EXISTS channel_worker_run_id INT NULL AFTER dispatch_run_id,
    ADD INDEX IF NOT EXISTS idx_marketing_live_worker_lease_channel_run (channel_worker_run_id);

INSERT INTO marketing_live_worker_schedules
    (workspace_id, uuid, worker_key, status, schedule_label, command, expected_interval_minutes,
     max_stale_minutes, max_handoffs_per_run, min_seconds_between_sends, hourly_send_cap, daily_send_cap,
     next_check_at, metadata_json)
SELECT w.id,
       UUID(),
       'marketing_live_channel_handoffs',
       'planned',
       'Marketing live channel worker',
       'php cli/process_marketing_live_channel_handoffs.php all 25',
       5,
       30,
       25,
       0,
       0,
       0,
       DATE_ADD(NOW(), INTERVAL 5 MINUTE),
       JSON_OBJECT('source', 'migration_398', 'worker_type', 'live_channel_worker', 'secret_safe', true, 'raw_secret_values_visible', false)
FROM workspaces w
ON DUPLICATE KEY UPDATE
    command = IF(command IS NULL OR command = 'php cli/process_marketing_live_email_handoffs.php all 25', VALUES(command), command),
    schedule_label = IF(schedule_label IS NULL OR schedule_label = 'Marketing live email worker', VALUES(schedule_label), schedule_label),
    updated_at = NOW();
