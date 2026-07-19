-- Marketing Phase 55: content production pipeline, dependencies, and production events.

ALTER TABLE marketing_content_items
    ADD COLUMN IF NOT EXISTS production_stage ENUM('idea','briefing','drafting','design','review','approved','scheduled','published','blocked','archived') NOT NULL DEFAULT 'idea' AFTER status,
    ADD COLUMN IF NOT EXISTS production_due_at DATETIME NULL AFTER production_stage,
    ADD COLUMN IF NOT EXISTS production_started_at DATETIME NULL AFTER production_due_at,
    ADD COLUMN IF NOT EXISTS production_completed_at DATETIME NULL AFTER production_started_at,
    ADD COLUMN IF NOT EXISTS dependency_status ENUM('clear','waiting','blocked') NOT NULL DEFAULT 'clear' AFTER production_completed_at,
    ADD COLUMN IF NOT EXISTS dependency_notes TEXT NULL AFTER dependency_status,
    ADD COLUMN IF NOT EXISTS production_checklist_json JSON NULL AFTER dependency_notes,
    ADD COLUMN IF NOT EXISTS production_score INT NOT NULL DEFAULT 0 AFTER production_checklist_json,
    ADD COLUMN IF NOT EXISTS next_action VARCHAR(255) NULL AFTER production_score,
    ADD INDEX IF NOT EXISTS idx_marketing_content_workspace_production_stage (workspace_id, production_stage, production_due_at),
    ADD INDEX IF NOT EXISTS idx_marketing_content_workspace_production_owner (workspace_id, owner_user_id, production_stage),
    ADD INDEX IF NOT EXISTS idx_marketing_content_workspace_dependency_status (workspace_id, dependency_status, production_due_at);

CREATE TABLE IF NOT EXISTS marketing_content_dependencies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    content_item_id INT NOT NULL,
    depends_on_content_item_id INT NULL,
    dependency_type ENUM('content','asset','approval','landing_page','brief','external','other') NOT NULL DEFAULT 'other',
    title VARCHAR(180) NOT NULL,
    status ENUM('waiting','ready','blocked','done') NOT NULL DEFAULT 'waiting',
    due_at DATETIME NULL,
    owner_user_id INT NULL,
    notes TEXT NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_content_dependencies_uuid (uuid),
    KEY idx_marketing_content_dependencies_workspace_content (workspace_id, content_item_id, status),
    KEY idx_marketing_content_dependencies_workspace_status_due (workspace_id, status, due_at),
    KEY idx_marketing_content_dependencies_workspace_owner (workspace_id, owner_user_id, status),
    CONSTRAINT fk_marketing_content_dependencies_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_content_dependencies_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_content_dependencies_depends_content FOREIGN KEY (depends_on_content_item_id) REFERENCES marketing_content_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_content_dependencies_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_content_dependencies_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_content_production_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    content_item_id INT NOT NULL,
    event_type ENUM('stage_changed','dependency_added','dependency_updated','dependency_deleted','checklist_updated','blocked','unblocked','bulk_updated','production_updated') NOT NULL DEFAULT 'production_updated',
    from_stage VARCHAR(40) NULL,
    to_stage VARCHAR(40) NULL,
    summary VARCHAR(255) NOT NULL,
    metadata_json JSON NULL,
    actor_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_content_production_events_uuid (uuid),
    KEY idx_marketing_content_production_events_workspace_content (workspace_id, content_item_id, created_at),
    KEY idx_marketing_content_production_events_workspace_type (workspace_id, event_type, created_at),
    CONSTRAINT fk_marketing_content_production_events_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_content_production_events_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_content_production_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
