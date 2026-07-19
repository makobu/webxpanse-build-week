CREATE TABLE IF NOT EXISTS automation_job_health (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_key VARCHAR(120) NOT NULL UNIQUE,
    status ENUM('running', 'ok', 'failed') NOT NULL DEFAULT 'ok',
    last_run_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_failure_at DATETIME NULL,
    last_duration_ms INT NULL,
    last_message TEXT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_automation_job_health_status (status),
    INDEX idx_automation_job_health_last_run (last_run_at),
    INDEX idx_automation_job_health_last_success (last_success_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
