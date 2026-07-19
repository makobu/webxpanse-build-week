-- Marketing live execution: cron launcher evidence.
-- Records workspace-scoped launcher invocations so operators can verify that the
-- unified live execution command is being scheduled and monitored.

CREATE TABLE IF NOT EXISTS marketing_live_execution_launcher_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    invocation_uuid CHAR(36) NOT NULL,
    source ENUM('cli','cron','api','manual','test') NOT NULL DEFAULT 'cron',
    status ENUM('running','completed','completed_with_errors','blocked','failed','no_work') NOT NULL DEFAULT 'running',
    target_scope VARCHAR(64) NOT NULL DEFAULT 'all',
    readiness_only TINYINT(1) NOT NULL DEFAULT 0,
    limit_count INT NOT NULL DEFAULT 25,
    orchestration_run_id INT NULL,
    processed_count INT NOT NULL DEFAULT 0,
    succeeded_count INT NOT NULL DEFAULT 0,
    blocked_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    result_json JSON NULL,
    error_message TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_execution_launcher_runs_uuid (uuid),
    KEY idx_marketing_live_execution_launcher_workspace_status (workspace_id, status, started_at),
    KEY idx_marketing_live_execution_launcher_workspace_source (workspace_id, source, started_at),
    KEY idx_marketing_live_execution_launcher_invocation (invocation_uuid),
    KEY idx_marketing_live_execution_launcher_orchestration (workspace_id, orchestration_run_id),
    CONSTRAINT fk_marketing_live_execution_launcher_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_execution_launcher_orchestration FOREIGN KEY (orchestration_run_id) REFERENCES marketing_live_orchestration_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE marketing_live_worker_schedules
SET command = 'php cli/run_marketing_live_execution.php all 25 --source=cron',
    metadata_json = JSON_SET(COALESCE(metadata_json, JSON_OBJECT()), '$.launcher', 'marketing_live_execution', '$.updated_by_migration', '399'),
    updated_at = NOW()
WHERE worker_key = 'marketing_live_orchestrator'
  AND command IN ('php scripts/run_marketing_live_orchestrator.php all 25', 'php cli/process_marketing_live_email_handoffs.php all 25');

INSERT INTO marketing_live_worker_schedules
    (workspace_id, uuid, worker_key, status, schedule_label, command, expected_interval_minutes,
     max_stale_minutes, max_handoffs_per_run, min_seconds_between_sends, hourly_send_cap, daily_send_cap,
     next_check_at, metadata_json)
SELECT w.id,
       UUID(),
       'marketing_live_orchestrator',
       'planned',
       'Marketing live orchestrator',
       'php cli/run_marketing_live_execution.php all 25 --source=cron',
       5,
       30,
       25,
       0,
       0,
       0,
       DATE_ADD(NOW(), INTERVAL 5 MINUTE),
       JSON_OBJECT('source', 'migration_399', 'launcher', 'marketing_live_execution', 'secret_safe', true)
FROM workspaces w
WHERE NOT EXISTS (
    SELECT 1
    FROM marketing_live_worker_schedules s
    WHERE s.workspace_id = w.id
      AND s.worker_key = 'marketing_live_orchestrator'
);
