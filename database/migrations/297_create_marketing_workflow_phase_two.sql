-- Marketing workflow phase two: briefs, calendar planning, review workflow, comments, and versions.

CREATE TABLE IF NOT EXISTS marketing_campaign_briefs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    objective VARCHAR(255) NULL,
    audience VARCHAR(255) NULL,
    offer_text VARCHAR(255) NULL,
    key_message TEXT NULL,
    channels_json JSON NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    campaign_id INT NULL,
    owner_user_id INT NULL,
    status ENUM('draft','active','paused','completed','archived') NOT NULL DEFAULT 'draft',
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_briefs_workspace_status (workspace_id, status, updated_at),
    INDEX idx_marketing_briefs_workspace_dates (workspace_id, start_date, end_date),
    INDEX idx_marketing_briefs_workspace_campaign (workspace_id, campaign_id),
    INDEX idx_marketing_briefs_workspace_owner (workspace_id, owner_user_id),
    CONSTRAINT fk_marketing_briefs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_briefs_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_briefs_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_briefs_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_content_items ADD COLUMN campaign_brief_id INT NULL AFTER target_audience;
ALTER TABLE marketing_content_items ADD INDEX idx_marketing_content_workspace_brief (workspace_id, campaign_brief_id);
ALTER TABLE marketing_content_items ADD CONSTRAINT fk_marketing_content_brief FOREIGN KEY (campaign_brief_id) REFERENCES marketing_campaign_briefs(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS marketing_content_versions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    content_item_id INT NOT NULL,
    version_number INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    draft_body MEDIUMTEXT NULL,
    status VARCHAR(40) NOT NULL,
    change_summary VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marketing_content_versions_item_number (content_item_id, version_number),
    INDEX idx_marketing_content_versions_workspace_item (workspace_id, content_item_id, created_at),
    CONSTRAINT fk_marketing_versions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_versions_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_versions_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_content_comments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    content_item_id INT NOT NULL,
    body TEXT NOT NULL,
    created_by INT NULL,
    resolved_at DATETIME NULL,
    resolved_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_content_comments_workspace_item (workspace_id, content_item_id, resolved_at, created_at),
    CONSTRAINT fk_marketing_comments_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_comments_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_comments_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_comments_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_content_approvals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    content_item_id INT NOT NULL,
    requested_by INT NULL,
    approved_by INT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    decision_note VARCHAR(255) NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_approvals_workspace_status (workspace_id, status, requested_at),
    INDEX idx_marketing_approvals_workspace_item (workspace_id, content_item_id, status),
    CONSTRAINT fk_marketing_approvals_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_approvals_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_approvals_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_approvals_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_calendar_milestones (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    milestone_type ENUM('launch','review','publish','deadline','event','other') NOT NULL DEFAULT 'other',
    milestone_date DATE NOT NULL,
    campaign_id INT NULL,
    content_item_id INT NULL,
    campaign_brief_id INT NULL,
    owner_user_id INT NULL,
    status ENUM('planned','done','cancelled') NOT NULL DEFAULT 'planned',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_calendar_workspace_date (workspace_id, milestone_date, status),
    INDEX idx_marketing_calendar_workspace_campaign (workspace_id, campaign_id),
    INDEX idx_marketing_calendar_workspace_content (workspace_id, content_item_id),
    INDEX idx_marketing_calendar_workspace_brief (workspace_id, campaign_brief_id),
    CONSTRAINT fk_marketing_calendar_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_calendar_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_calendar_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_calendar_brief FOREIGN KEY (campaign_brief_id) REFERENCES marketing_campaign_briefs(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_calendar_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_calendar_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
