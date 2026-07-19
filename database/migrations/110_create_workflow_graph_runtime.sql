-- Migration 110: Workflow graph runtime foundation

ALTER TABLE workflows
    ADD COLUMN IF NOT EXISTS builder_version INT DEFAULT 2 AFTER version,
    ADD COLUMN IF NOT EXISTS workflow_mode ENUM('crm','journey','mixed') DEFAULT 'mixed' AFTER builder_version,
    ADD COLUMN IF NOT EXISTS graph_json JSON NULL AFTER workflow_mode,
    ADD COLUMN IF NOT EXISTS migration_source VARCHAR(50) NULL AFTER graph_json,
    ADD COLUMN IF NOT EXISTS migration_status ENUM('pending','migrated','failed') DEFAULT 'pending' AFTER migration_source,
    ADD COLUMN IF NOT EXISTS last_migrated_at DATETIME NULL AFTER migration_status,
    ADD COLUMN IF NOT EXISTS last_validated_at DATETIME NULL AFTER last_migrated_at;

CREATE TABLE IF NOT EXISTS workflow_node_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_execution_id INT NOT NULL,
    workflow_id INT NOT NULL,
    node_id VARCHAR(100) NOT NULL,
    node_type VARCHAR(50) NOT NULL,
    node_label VARCHAR(255) NULL,
    status ENUM('pending','running','completed','failed','skipped','waiting') DEFAULT 'pending',
    attempt_count INT DEFAULT 0,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    duration_ms INT NULL,
    input_snapshot_json JSON NULL,
    output_snapshot_json JSON NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_workflow_execution_node (workflow_execution_id, node_id),
    INDEX idx_workflow_node_status (workflow_id, status),
    FOREIGN KEY (workflow_execution_id) REFERENCES workflow_executions(id) ON DELETE CASCADE,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_retry_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_queue_id INT NULL,
    workflow_execution_id INT NOT NULL,
    workflow_id INT NOT NULL,
    node_id VARCHAR(100) NOT NULL,
    action_index INT NULL,
    retry_after DATETIME NOT NULL,
    retry_count INT DEFAULT 0,
    last_error TEXT NULL,
    payload_json JSON NOT NULL,
    status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    processed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_retry_due (status, retry_after),
    INDEX idx_retry_workflow (workflow_id, workflow_execution_id),
    INDEX idx_retry_queue_id (workflow_queue_id),
    FOREIGN KEY (workflow_execution_id) REFERENCES workflow_executions(id) ON DELETE CASCADE,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_migration_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_id INT NOT NULL,
    source_format VARCHAR(50) NOT NULL,
    target_format VARCHAR(50) NOT NULL,
    status ENUM('pending','migrated','failed') DEFAULT 'pending',
    notes TEXT NULL,
    before_snapshot_json JSON NULL,
    after_snapshot_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_workflow_migration (workflow_id, created_at DESC),
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE workflow_queue
    ADD COLUMN IF NOT EXISTS started_at DATETIME NULL AFTER processed_at,
    ADD COLUMN IF NOT EXISTS queue_latency_ms INT NULL AFTER started_at;
