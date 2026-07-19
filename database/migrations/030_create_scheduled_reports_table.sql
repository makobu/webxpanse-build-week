-- Scheduled Reports Table
CREATE TABLE IF NOT EXISTS scheduled_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_id INT NOT NULL,
    schedule_name VARCHAR(255) NOT NULL,
    schedule_type ENUM('daily','weekly','monthly','custom') NOT NULL,
    schedule_config JSON NOT NULL,
    recipients JSON NOT NULL,
    format ENUM('csv','pdf','excel') DEFAULT 'csv',
    is_active BOOLEAN DEFAULT TRUE,
    last_run_at DATETIME DEFAULT NULL,
    next_run_at DATETIME NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_report_id (report_id),
    INDEX idx_is_active (is_active),
    INDEX idx_next_run_at (next_run_at),
    INDEX idx_created_by (created_by),
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scheduled Report Runs Table
CREATE TABLE IF NOT EXISTS scheduled_report_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scheduled_report_id INT NOT NULL,
    executed_at DATETIME NOT NULL,
    status ENUM('success','failed','pending') DEFAULT 'pending',
    result_count INT DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    sent_to JSON DEFAULT NULL,
    execution_time DECIMAL(10,3) DEFAULT 0,
    INDEX idx_scheduled_report_id (scheduled_report_id),
    INDEX idx_executed_at (executed_at),
    INDEX idx_status (status),
    FOREIGN KEY (scheduled_report_id) REFERENCES scheduled_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
