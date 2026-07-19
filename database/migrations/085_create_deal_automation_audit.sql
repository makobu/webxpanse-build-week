-- Deal Automation Audit Log
-- Tracks every AI evaluation and stage change for explainability and rollback

CREATE TABLE IF NOT EXISTS deal_automation_audit (
    id INT PRIMARY KEY AUTO_INCREMENT,
    deal_id INT NOT NULL,
    contact_id INT NULL,
    trigger_type ENUM('communication', 'proposal', 'inactivity', 'manual') NOT NULL,
    trigger_ref_id INT NULL COMMENT 'communication_id or activity_id',
    from_stage VARCHAR(50) NULL,
    to_stage VARCHAR(50) NULL,
    decision ENUM('reject', 'suggest_only', 'auto_apply') NOT NULL,
    confidence DECIMAL(4,2) NULL,
    applied BOOLEAN DEFAULT FALSE,
    reason TEXT NULL,
    evidence_summary JSON NULL,
    checklist_results JSON NULL,
    config_version INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_deal_id (deal_id),
    INDEX idx_contact_id (contact_id),
    INDEX idx_trigger_type (trigger_type),
    INDEX idx_decision (decision),
    INDEX idx_created_at (created_at DESC),
    FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
