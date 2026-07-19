-- Enhance Workflows Schema
-- Add analytics and scheduling support

ALTER TABLE workflows ADD COLUMN (
    description TEXT,
    category VARCHAR(100),
    execution_count INT DEFAULT 0,
    success_count INT DEFAULT 0,
    failure_count INT DEFAULT 0,
    avg_execution_time DECIMAL(10,2),
    last_executed_at DATETIME NULL
);

-- Create scheduled workflow actions table
CREATE TABLE IF NOT EXISTS scheduled_workflow_actions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_id INT NOT NULL,
    execution_id INT NOT NULL,
    action_index INT NOT NULL,
    scheduled_for DATETIME NOT NULL,
    timezone VARCHAR(50),
    status ENUM('pending','executed','failed','cancelled') DEFAULT 'pending',
    retry_count INT DEFAULT 0,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    FOREIGN KEY (execution_id) REFERENCES workflow_executions(id) ON DELETE CASCADE,
    INDEX idx_scheduled (scheduled_for, status),
    INDEX idx_workflow_exec (workflow_id, execution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enhance workflow_executions table
ALTER TABLE workflow_executions ADD COLUMN (
    execution_time_ms INT,
    actions_completed INT DEFAULT 0,
    actions_failed INT DEFAULT 0,
    error_details JSON
);
