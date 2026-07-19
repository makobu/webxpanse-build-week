-- Marketing onboarding state and opt-in starter pack tracking.

CREATE TABLE IF NOT EXISTS marketing_onboarding_state (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    readiness_score INT NOT NULL DEFAULT 0,
    completed_steps_json JSON NULL,
    dismissed_steps_json JSON NULL,
    starter_pack_created_at DATETIME NULL,
    starter_pack_created_by INT NULL,
    starter_pack_archived_at DATETIME NULL,
    starter_pack_archived_by INT NULL,
    starter_pack_metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_onboarding_workspace (workspace_id),
    KEY idx_marketing_onboarding_readiness (readiness_score),
    KEY idx_marketing_onboarding_starter_created_by (starter_pack_created_by),
    KEY idx_marketing_onboarding_starter_archived_by (starter_pack_archived_by),
    CONSTRAINT fk_marketing_onboarding_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_onboarding_starter_creator FOREIGN KEY (starter_pack_created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_onboarding_starter_archiver FOREIGN KEY (starter_pack_archived_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
