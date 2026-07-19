-- Marketing Phase 37: journey planner foundation.

CREATE TABLE IF NOT EXISTS marketing_journeys (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(180) NOT NULL,
    description TEXT NULL,
    journey_goal VARCHAR(255) NULL,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    audience_segment_id INT NULL,
    campaign_id INT NULL,
    owner_user_id INT NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_journeys_uuid (uuid),
    KEY idx_marketing_journeys_workspace_status (workspace_id, status, updated_at),
    KEY idx_marketing_journeys_workspace_segment (workspace_id, audience_segment_id),
    KEY idx_marketing_journeys_workspace_campaign (workspace_id, campaign_id),
    KEY idx_marketing_journeys_workspace_owner (workspace_id, owner_user_id),
    CONSTRAINT fk_marketing_journeys_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_journeys_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_journey_steps (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    journey_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    step_order INT NOT NULL DEFAULT 1,
    step_type ENUM('email','whatsapp','sms','task','wait','condition','branch') NOT NULL DEFAULT 'email',
    title VARCHAR(180) NOT NULL,
    instructions TEXT NULL,
    wait_days INT NULL,
    content_item_id INT NULL,
    email_run_id INT NULL,
    task_id INT NULL,
    condition_json JSON NULL,
    branch_json JSON NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_journey_steps_uuid (uuid),
    KEY idx_marketing_journey_steps_workspace_journey (workspace_id, journey_id, step_order),
    KEY idx_marketing_journey_steps_workspace_type (workspace_id, step_type),
    CONSTRAINT fk_marketing_journey_steps_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_journey_steps_journey FOREIGN KEY (journey_id) REFERENCES marketing_journeys(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_journey_steps_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_journey_drafts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    journey_id INT NULL,
    uuid CHAR(36) NOT NULL,
    draft_type ENUM('manual','ai') NOT NULL DEFAULT 'manual',
    prompt TEXT NULL,
    draft_json JSON NULL,
    status ENUM('draft','applied','archived') NOT NULL DEFAULT 'draft',
    provider_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_journey_drafts_uuid (uuid),
    KEY idx_marketing_journey_drafts_workspace_journey (workspace_id, journey_id, created_at),
    KEY idx_marketing_journey_drafts_workspace_status (workspace_id, status, created_at),
    CONSTRAINT fk_marketing_journey_drafts_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_journey_drafts_journey FOREIGN KEY (journey_id) REFERENCES marketing_journeys(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_journey_drafts_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES
    ('marketing_journeys', 'Marketing Journeys page guide', NULL, 0),
    ('marketing_journey_edit', 'Marketing Journey Editor page guide', NULL, 0),
    ('marketing_journey_view', 'Marketing Journey Detail page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
