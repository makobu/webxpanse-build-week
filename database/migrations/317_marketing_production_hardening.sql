-- Marketing Phase 23: production hardening, audit history, diagnostics, cleanup tracking, and performance indexes.

CREATE TABLE IF NOT EXISTS marketing_audit_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    target_type VARCHAR(120) NULL,
    target_id INT NULL,
    summary VARCHAR(255) NOT NULL,
    metadata_json JSON NULL,
    actor_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_audit_uuid (uuid),
    KEY idx_marketing_audit_workspace_created (workspace_id, created_at),
    KEY idx_marketing_audit_workspace_type (workspace_id, event_type, created_at),
    KEY idx_marketing_audit_workspace_target (workspace_id, target_type, target_id),
    CONSTRAINT fk_marketing_audit_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_cleanup_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    cleanup_type VARCHAR(120) NOT NULL,
    status ENUM('dry_run','completed','failed') NOT NULL DEFAULT 'dry_run',
    scope_json JSON NULL,
    result_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_cleanup_uuid (uuid),
    KEY idx_marketing_cleanup_workspace_type (workspace_id, cleanup_type, created_at),
    CONSTRAINT fk_marketing_cleanup_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_cleanup_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_content_items
    ADD INDEX IF NOT EXISTS idx_marketing_content_workspace_updated (workspace_id, updated_at),
    ADD INDEX IF NOT EXISTS idx_marketing_content_workspace_readiness (workspace_id, readiness_score, status);

ALTER TABLE marketing_content_approvals
    ADD INDEX IF NOT EXISTS idx_marketing_approvals_workspace_status_due (workspace_id, status, review_due_at);

ALTER TABLE marketing_content_comments
    ADD INDEX IF NOT EXISTS idx_marketing_comments_workspace_resolved (workspace_id, resolved_at, created_at);

ALTER TABLE marketing_planning_queue_items
    ADD INDEX IF NOT EXISTS idx_marketing_queue_workspace_status_due (workspace_id, status, due_at);
