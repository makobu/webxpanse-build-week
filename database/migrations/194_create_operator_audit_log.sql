CREATE TABLE IF NOT EXISTS operator_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    actor_user_id INT NULL,
    target_workspace_id INT NULL,
    target_user_id INT NULL,
    action_type VARCHAR(100) NOT NULL,
    reason VARCHAR(255) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_operator_audit_actor (actor_user_id, created_at),
    KEY idx_operator_audit_workspace (target_workspace_id, created_at),
    KEY idx_operator_audit_action (action_type, created_at),
    CONSTRAINT fk_operator_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_operator_audit_workspace FOREIGN KEY (target_workspace_id) REFERENCES workspaces(id) ON DELETE SET NULL,
    CONSTRAINT fk_operator_audit_target_user FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
