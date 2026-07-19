-- Marketing Phase 61: task hub and operator queue preferences.

CREATE TABLE IF NOT EXISTS marketing_task_hub_preferences (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    default_queue VARCHAR(80) NOT NULL DEFAULT 'my_work',
    include_team_items TINYINT(1) NOT NULL DEFAULT 0,
    filters_json JSON NULL,
    sort_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_task_hub_pref_user (workspace_id, user_id),
    KEY idx_marketing_task_hub_pref_workspace_queue (workspace_id, default_queue),
    CONSTRAINT fk_marketing_task_hub_pref_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_task_hub_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_task_hub_action_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    item_type VARCHAR(80) NOT NULL,
    item_id INT NULL,
    action_type VARCHAR(80) NOT NULL,
    old_state_json JSON NULL,
    new_state_json JSON NULL,
    note TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_marketing_task_hub_action_workspace_item (workspace_id, item_type, item_id),
    KEY idx_marketing_task_hub_action_workspace_action (workspace_id, action_type, created_at),
    CONSTRAINT fk_marketing_task_hub_action_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_task_hub_action_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES
    ('marketing_task_hub', 'Marketing Task Hub page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
