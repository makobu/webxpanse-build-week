ALTER TABLE billing_checkout_sessions
    ADD COLUMN IF NOT EXISTS subscription_id INT NULL;

ALTER TABLE billing_transactions
    ADD COLUMN IF NOT EXISTS subscription_id INT NULL;

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

ALTER TABLE workspace_invites
    ADD COLUMN IF NOT EXISTS delivery_status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS delivery_error VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS last_delivery_attempt_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS delivery_attempt_count INT NOT NULL DEFAULT 0;

SET @workspace_invites_delivery_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_invites'
      AND index_name = 'idx_workspace_invites_delivery'
);
SET @workspace_invites_delivery_sql := IF(
    @workspace_invites_delivery_idx_exists = 0,
    'ALTER TABLE workspace_invites ADD KEY idx_workspace_invites_delivery (workspace_id, invite_status, delivery_status)',
    'SELECT 1'
);
PREPARE stmt FROM @workspace_invites_delivery_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
