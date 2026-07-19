-- Marketing Phase 56: editorial calendar planning, templates, and conflict signals.

ALTER TABLE marketing_calendar_milestones
    ADD COLUMN IF NOT EXISTS channel VARCHAR(60) NULL AFTER milestone_type,
    ADD COLUMN IF NOT EXISTS capacity_weight INT NOT NULL DEFAULT 1 AFTER milestone_date,
    ADD COLUMN IF NOT EXISTS conflict_status ENUM('clear','warning','conflict') NOT NULL DEFAULT 'clear' AFTER capacity_weight,
    ADD COLUMN IF NOT EXISTS conflict_notes TEXT NULL AFTER conflict_status,
    ADD COLUMN IF NOT EXISTS template_key VARCHAR(120) NULL AFTER conflict_notes,
    ADD INDEX IF NOT EXISTS idx_marketing_calendar_workspace_channel_date (workspace_id, channel, milestone_date),
    ADD INDEX IF NOT EXISTS idx_marketing_calendar_workspace_conflict (workspace_id, conflict_status, milestone_date);

CREATE TABLE IF NOT EXISTS marketing_calendar_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(180) NOT NULL,
    template_key VARCHAR(120) NOT NULL,
    milestone_type ENUM('launch','review','publish','deadline','event','other') NOT NULL DEFAULT 'publish',
    channel VARCHAR(60) NULL,
    offset_days INT NOT NULL DEFAULT 0,
    default_title VARCHAR(180) NOT NULL,
    checklist_json JSON NULL,
    metadata_json JSON NULL,
    owner_user_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_calendar_templates_uuid (uuid),
    UNIQUE KEY uniq_marketing_calendar_templates_workspace_key (workspace_id, template_key),
    KEY idx_marketing_calendar_templates_workspace_type (workspace_id, milestone_type, channel),
    CONSTRAINT fk_marketing_calendar_templates_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_calendar_templates_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_calendar_templates_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
