-- Migration 074: A/B Testing for Workflows
-- Enables variant testing with traffic split and conversion tracking

CREATE TABLE IF NOT EXISTS workflow_variants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_id INT NOT NULL,
    name VARCHAR(100),
    variant_key VARCHAR(50) DEFAULT 'A',
    actions JSON NOT NULL,
    traffic_percent INT DEFAULT 50,
    is_control BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    INDEX idx_workflow (workflow_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_variant_results (
    id INT PRIMARY KEY AUTO_INCREMENT,
    execution_id INT,
    workflow_id INT NOT NULL,
    contact_id INT NOT NULL,
    variant_id INT,
    converted BOOLEAN DEFAULT FALSE,
    converted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    INDEX idx_workflow (workflow_id),
    INDEX idx_contact (contact_id),
    INDEX idx_variant (variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
