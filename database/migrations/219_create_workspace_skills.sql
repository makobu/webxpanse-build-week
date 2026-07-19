CREATE TABLE IF NOT EXISTS workspace_skill_definitions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    skill_key VARCHAR(80) NOT NULL,
    label VARCHAR(160) NOT NULL,
    summary TEXT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'strategy',
    version VARCHAR(40) NOT NULL DEFAULT '1.0.0',
    capabilities_json JSON NULL,
    onboarding_fields_json JSON NULL,
    settings_schema_json JSON NULL,
    ai_context_provider VARCHAR(160) NULL,
    navigation_json JSON NULL,
    permissions_json JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workspace_skill_definitions_key (skill_key),
    KEY idx_workspace_skill_definitions_category (category),
    KEY idx_workspace_skill_definitions_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_skill_installs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    skill_key VARCHAR(80) NOT NULL,
    status ENUM('installed', 'disabled') NOT NULL DEFAULT 'installed',
    config_json JSON NULL,
    installed_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    installed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    disabled_at DATETIME NULL,
    uninstalled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workspace_skill_installs_workspace_skill (workspace_id, skill_key),
    KEY idx_workspace_skill_installs_workspace (workspace_id),
    KEY idx_workspace_skill_installs_skill (skill_key),
    KEY idx_workspace_skill_installs_status (status),
    CONSTRAINT fk_workspace_skill_installs_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_skill_installs_installed_by
        FOREIGN KEY (installed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_workspace_skill_installs_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_skill_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    skill_key VARCHAR(80) NOT NULL,
    event_type ENUM('installed', 'uninstalled', 'enabled', 'disabled', 'configured') NOT NULL,
    actor_user_id INT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_workspace_skill_events_workspace (workspace_id),
    KEY idx_workspace_skill_events_skill (skill_key),
    KEY idx_workspace_skill_events_created (created_at),
    CONSTRAINT fk_workspace_skill_events_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_skill_events_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_skill_definitions (
    skill_key,
    label,
    summary,
    category,
    version,
    capabilities_json,
    onboarding_fields_json,
    settings_schema_json,
    ai_context_provider,
    navigation_json,
    permissions_json,
    is_active
) VALUES
(
    'lean_canvas',
    'Lean Canvas',
    'Adds lightweight business-model context so Clarity can reason about the problem, customer, promise, channels, money, and metrics.',
    'strategy',
    '1.0.0',
    JSON_OBJECT('ai_context', true, 'onboarding_fields', true, 'strategy_guidance', true),
    JSON_ARRAY('lean_problem', 'lean_customer_segments', 'lean_unique_value_proposition', 'lean_solution', 'lean_channels', 'lean_revenue_streams', 'lean_cost_structure', 'lean_key_metrics', 'lean_unfair_advantage'),
    JSON_OBJECT('requires_configuration', false),
    'lean_canvas',
    JSON_OBJECT('label', 'Lean Canvas', 'url', 'onboarding.php?mode=full_setup&step=5'),
    JSON_ARRAY('workspace.skills.view'),
    1
),
(
    'professional_marketer',
    'Professional Marketer',
    'Adds marketing strategy context for sharper positioning, campaign ideas, content angles, and message review.',
    'marketing',
    '1.0.0',
    JSON_OBJECT('ai_context', true, 'campaign_suggestions', true, 'messaging_review', true),
    JSON_ARRAY('target_market_focus', 'segment_focus', 'outreach_posture', 'positioning_notes'),
    JSON_OBJECT('requires_configuration', false),
    'professional_marketer',
    JSON_OBJECT('label', 'Marketing strategy', 'url', 'workspace_skills.php'),
    JSON_ARRAY('workspace.skills.view'),
    1
)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    summary = VALUES(summary),
    category = VALUES(category),
    version = VALUES(version),
    capabilities_json = VALUES(capabilities_json),
    onboarding_fields_json = VALUES(onboarding_fields_json),
    settings_schema_json = VALUES(settings_schema_json),
    ai_context_provider = VALUES(ai_context_provider),
    navigation_json = VALUES(navigation_json),
    permissions_json = VALUES(permissions_json),
    is_active = VALUES(is_active),
    updated_at = NOW();

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('workspace.skills.view', 'View Workspace Skills', 'View installed workspace skills and module catalog', FALSE),
('workspace.skills.manage', 'Manage Workspace Skills', 'Install, disable, and configure workspace skills', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);
