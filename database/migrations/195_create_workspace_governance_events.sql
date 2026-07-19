CREATE TABLE IF NOT EXISTS workspace_governance_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    actor_user_id INT NULL,
    target_user_id INT NULL,
    invite_id INT NULL,
    event_type VARCHAR(100) NOT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_workspace_governance_events_workspace (workspace_id, created_at),
    KEY idx_workspace_governance_events_event (event_type, created_at),
    KEY idx_workspace_governance_events_target_user (target_user_id, created_at),
    KEY idx_workspace_governance_events_invite (invite_id, created_at),
    CONSTRAINT fk_workspace_governance_events_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_governance_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_workspace_governance_events_target_user FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_workspace_governance_events_invite FOREIGN KEY (invite_id) REFERENCES workspace_invites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
