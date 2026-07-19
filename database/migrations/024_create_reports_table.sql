CREATE TABLE IF NOT EXISTS reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    report_type ENUM('contacts','activities','sales','emails','tasks','events','custom') DEFAULT 'custom',
    query_config JSON NOT NULL,
    chart_config JSON DEFAULT NULL,
    filters JSON DEFAULT NULL,
    created_by INT NOT NULL,
    is_public BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_created_by (created_by),
    INDEX idx_report_type (report_type),
    INDEX idx_is_public (is_public),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_executions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_id INT NOT NULL,
    executed_by INT NOT NULL,
    parameters JSON DEFAULT NULL,
    result_count INT DEFAULT 0,
    execution_time DECIMAL(10,3) DEFAULT 0,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_id (report_id),
    INDEX idx_executed_by (executed_by),
    INDEX idx_executed_at (executed_at),
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (executed_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
