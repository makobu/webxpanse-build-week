-- Inbox Triage MVP schema

ALTER TABLE communications
    ADD COLUMN IF NOT EXISTS triage_priority ENUM('urgent','high','medium','low') NULL AFTER deleted_at,
    ADD COLUMN IF NOT EXISTS triage_score DECIMAL(5,2) NULL AFTER triage_priority,
    ADD COLUMN IF NOT EXISTS triage_confidence DECIMAL(5,2) NULL AFTER triage_score,
    ADD COLUMN IF NOT EXISTS triage_status ENUM('suggested','auto_applied','skipped','blocked') NULL AFTER triage_confidence,
    ADD COLUMN IF NOT EXISTS triage_owner_id INT NULL AFTER triage_status,
    ADD COLUMN IF NOT EXISTS triage_task_id INT NULL AFTER triage_owner_id,
    ADD COLUMN IF NOT EXISTS triage_reason_codes JSON NULL AFTER triage_task_id,
    ADD COLUMN IF NOT EXISTS triage_decided_at TIMESTAMP NULL AFTER triage_reason_codes;

ALTER TABLE communications
    ADD INDEX IF NOT EXISTS idx_comm_channel_direction_created (channel, direction, created_at),
    ADD INDEX IF NOT EXISTS idx_comm_contact_triage_status_created (contact_id, triage_status, created_at),
    ADD INDEX IF NOT EXISTS idx_comm_triage_priority (triage_priority);

CREATE TABLE IF NOT EXISTS inbox_triage_audit (
    id INT PRIMARY KEY AUTO_INCREMENT,
    communication_id INT NOT NULL,
    contact_id INT NULL,
    channel VARCHAR(30) NOT NULL,
    decision VARCHAR(40) NOT NULL,
    score DECIMAL(5,2) NULL,
    confidence DECIMAL(5,2) NULL,
    priority_before VARCHAR(20) NULL,
    priority_after VARCHAR(20) NULL,
    owner_before INT NULL,
    owner_after INT NULL,
    task_id INT NULL,
    reason_codes JSON NULL,
    features_snapshot JSON NULL,
    applied_by VARCHAR(40) DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_triage_comm_id (communication_id),
    INDEX idx_triage_contact_id (contact_id),
    INDEX idx_triage_channel_created (channel, created_at),
    INDEX idx_triage_decision_created (decision, created_at),
    CONSTRAINT fk_inbox_triage_comm FOREIGN KEY (communication_id) REFERENCES communications(id) ON DELETE CASCADE,
    CONSTRAINT fk_inbox_triage_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
