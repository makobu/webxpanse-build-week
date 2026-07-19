-- Migration 072: Workflow Exclusions
-- Allows contacts to be excluded from specific workflows (e.g. via remove_from_workflow action)

CREATE TABLE IF NOT EXISTS workflow_exclusions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_id INT NOT NULL,
    contact_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_workflow_contact (workflow_id, contact_id),
    INDEX idx_workflow (workflow_id),
    INDEX idx_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
