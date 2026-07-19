CREATE TABLE IF NOT EXISTS task_completion_scan_queue (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    status ENUM('queued', 'running', 'completed', 'failed') NOT NULL DEFAULT 'queued',
    requested_by INT NULL,
    request_source ENUM('cron', 'tasks_page', 'manual') NOT NULL DEFAULT 'manual',
    queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    last_error TEXT NULL,
    results_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_task_scan_user_status (user_id, status, queued_at),
    INDEX idx_task_scan_status_queued (status, queued_at),
    INDEX idx_task_scan_finished (user_id, finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
