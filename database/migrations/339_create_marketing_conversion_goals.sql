-- Marketing Phase 48: conversion goal management.

CREATE TABLE IF NOT EXISTS marketing_conversion_goals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    title VARCHAR(180) NOT NULL,
    goal_key VARCHAR(120) NOT NULL,
    goal_type ENUM('lead','booking','quote_request','purchase','signup','download','manual_event','demo_request','newsletter_signup','contact_request','other') NOT NULL DEFAULT 'lead',
    description TEXT NULL,
    success_metric VARCHAR(120) NULL,
    target_count INT NULL,
    target_value DECIMAL(12,2) NULL,
    tracking_source ENUM('landing_page','form','content','campaign','manual','custom') NOT NULL DEFAULT 'manual',
    status ENUM('draft','active','paused','archived') NOT NULL DEFAULT 'draft',
    readiness_score INT NOT NULL DEFAULT 0,
    readiness_warnings_json JSON NULL,
    metadata_json JSON NULL,
    owner_user_id INT NULL,
    created_by INT NULL,
    starts_at DATE NULL,
    ends_at DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_conversion_goals_uuid (uuid),
    UNIQUE KEY uniq_marketing_conversion_goals_workspace_key (workspace_id, goal_key),
    KEY idx_marketing_conversion_goals_workspace_status (workspace_id, status, updated_at),
    KEY idx_marketing_conversion_goals_workspace_type (workspace_id, goal_type, status),
    KEY idx_marketing_conversion_goals_workspace_owner (workspace_id, owner_user_id, status),
    CONSTRAINT fk_marketing_conversion_goals_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_conversion_goals_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_conversion_goals_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_conversion_goal_links (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    goal_id INT NOT NULL,
    linked_type ENUM('campaign','landing_page','form','content_item','email_run','channel_export','manual') NOT NULL DEFAULT 'manual',
    linked_id INT NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_goal_links_target (workspace_id, goal_id, linked_type, linked_id),
    KEY idx_marketing_goal_links_workspace_type (workspace_id, linked_type, linked_id),
    CONSTRAINT fk_marketing_goal_links_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_goal_links_goal FOREIGN KEY (goal_id) REFERENCES marketing_conversion_goals(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_goal_links_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_landing_pages
    ADD COLUMN IF NOT EXISTS conversion_goal_id INT NULL AFTER conversion_goal,
    ADD INDEX IF NOT EXISTS idx_marketing_landing_workspace_goal_id (workspace_id, conversion_goal_id);

ALTER TABLE marketing_content_items
    ADD COLUMN IF NOT EXISTS conversion_goal_id INT NULL AFTER objective,
    ADD INDEX IF NOT EXISTS idx_marketing_content_workspace_goal_id (workspace_id, conversion_goal_id);

ALTER TABLE marketing_campaign_briefs
    ADD COLUMN IF NOT EXISTS conversion_goal_id INT NULL AFTER objective,
    ADD INDEX IF NOT EXISTS idx_marketing_briefs_workspace_goal_id (workspace_id, conversion_goal_id);
