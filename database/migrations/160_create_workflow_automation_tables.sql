CREATE TABLE IF NOT EXISTS workflow_automation_proposals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_id INT NULL,
    applied_workflow_id INT NULL,
    proposal_type ENUM('create', 'update') NOT NULL DEFAULT 'create',
    source_surface VARCHAR(100) NOT NULL DEFAULT 'workflow_generation',
    status ENUM('pending', 'approved', 'rejected', 'applied', 'expired') NOT NULL DEFAULT 'pending',
    requested_by_type ENUM('ai', 'system', 'user') NOT NULL DEFAULT 'ai',
    requested_by_id INT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    rejected_by INT NULL,
    rejected_at DATETIME NULL,
    target_workflow_name VARCHAR(255) NOT NULL,
    prompt_text MEDIUMTEXT NULL,
    decision_mode ENUM('suggest_only', 'auto_safe', 'full_auto') NOT NULL DEFAULT 'suggest_only',
    governance_decision VARCHAR(32) NULL,
    confidence_score DECIMAL(6,4) NULL,
    proposal_graph_json LONGTEXT NULL,
    current_graph_json LONGTEXT NULL,
    legacy_payload_json LONGTEXT NULL,
    validation_issues_json LONGTEXT NULL,
    risk_summary_json LONGTEXT NULL,
    action_summary_json LONGTEXT NULL,
    diff_summary_json LONGTEXT NULL,
    decision_snapshot_json LONGTEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_workflow_automation_proposals_status (status, created_at),
    INDEX idx_workflow_automation_proposals_workflow (workflow_id, proposal_type),
    INDEX idx_workflow_automation_proposals_requested_by (requested_by_type, requested_by_id)
);

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('settings.workflow_automation', 'Workflow Automation Settings', 'Manage workflow automation autonomy settings', TRUE),
('workflow_automation.approvals', 'Workflow Automation Approvals', 'Review and approve AI-created or AI-updated workflows', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'admin'
  AND p.permission_key IN (
    'settings.workflow_automation',
    'workflow_automation.approvals'
  )
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
