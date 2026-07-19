CREATE TABLE IF NOT EXISTS workspace_onboarding_nudges (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    owner_user_id INT NULL,
    created_by_user_id INT NULL,
    trigger_key VARCHAR(80) NOT NULL,
    channel ENUM('in_app','email','whatsapp','push','setup_link') NOT NULL DEFAULT 'in_app',
    status ENUM('draft','queued','sent','failed','skipped','dismissed') NOT NULL DEFAULT 'draft',
    subject VARCHAR(255) NULL,
    body TEXT NOT NULL,
    action_url VARCHAR(500) NULL,
    metadata_json JSON NULL,
    scheduled_at DATETIME NULL,
    sent_at DATETIME NULL,
    dismissed_at DATETIME NULL,
    delivery_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_workspace_onboarding_nudges_workspace (workspace_id, created_at),
    KEY idx_workspace_onboarding_nudges_owner (owner_user_id, status, created_at),
    KEY idx_workspace_onboarding_nudges_due (status, scheduled_at),
    KEY idx_workspace_onboarding_nudges_trigger (workspace_id, trigger_key, channel, status),
    CONSTRAINT fk_workspace_onboarding_nudges_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_onboarding_nudges_owner
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_workspace_onboarding_nudges_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
