-- Marketing Phase 15: saved workbench views, preferences, and bulk action audit.

CREATE TABLE IF NOT EXISTS marketing_saved_views (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    view_type ENUM('content','calendar','reviews','dashboard') NOT NULL DEFAULT 'content',
    name VARCHAR(120) NOT NULL,
    scope ENUM('private','workspace') NOT NULL DEFAULT 'private',
    view_mode ENUM('list','board') NOT NULL DEFAULT 'list',
    filters_json JSON NULL,
    sort_json JSON NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    user_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_saved_views_uuid (uuid),
    KEY idx_marketing_saved_views_workspace_type (workspace_id, view_type, scope, updated_at),
    KEY idx_marketing_saved_views_workspace_user (workspace_id, user_id, view_type),
    KEY idx_marketing_saved_views_workspace_default (workspace_id, view_type, is_default),
    CONSTRAINT fk_marketing_saved_views_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_saved_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_saved_views_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_workbench_preferences (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    default_view_mode ENUM('list','board') NOT NULL DEFAULT 'list',
    default_saved_view_id INT NULL,
    preferences_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_workbench_workspace_user (workspace_id, user_id),
    KEY idx_marketing_workbench_workspace_view (workspace_id, default_saved_view_id),
    CONSTRAINT fk_marketing_workbench_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_workbench_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_workbench_default_view FOREIGN KEY (default_saved_view_id) REFERENCES marketing_saved_views(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_bulk_action_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(80) NOT NULL DEFAULT 'content_items',
    target_count INT NOT NULL DEFAULT 0,
    input_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_bulk_action_uuid (uuid),
    KEY idx_marketing_bulk_workspace_created (workspace_id, created_at),
    KEY idx_marketing_bulk_workspace_action (workspace_id, action, created_at),
    CONSTRAINT fk_marketing_bulk_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_bulk_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
