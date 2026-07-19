-- Migration 013: Workflow Branches and Visual Data
-- Adds support for branching logic and visual workflow builder data

CREATE TABLE IF NOT EXISTS workflow_branches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_id INT NOT NULL,
    source_node_id VARCHAR(100) NOT NULL,
    target_node_id VARCHAR(100) NOT NULL,
    branch_type ENUM('success','failure','conditional','default') DEFAULT 'success',
    condition_value VARCHAR(255),
    branch_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    INDEX idx_workflow (workflow_id),
    INDEX idx_source (source_node_id),
    INDEX idx_target (target_node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE workflows 
ADD COLUMN visual_data JSON NULL AFTER actions,
ADD COLUMN version INT DEFAULT 1 AFTER visual_data,
ADD COLUMN last_saved_at DATETIME NULL AFTER version;
