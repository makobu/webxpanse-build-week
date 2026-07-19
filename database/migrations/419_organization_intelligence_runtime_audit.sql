-- Organization Intelligence runtime and time-accounting audit support.

CREATE TABLE IF NOT EXISTS user_system_sessions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NULL,
    user_id INT NOT NULL,
    session_id_hash CHAR(64) NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NULL,
    ended_at DATETIME NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    end_reason VARCHAR(40) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user_system_sessions_workspace_started (workspace_id, started_at),
    KEY idx_user_system_sessions_user_started (user_id, started_at),
    KEY idx_user_system_sessions_hash_open (session_id_hash, ended_at),
    CONSTRAINT fk_user_system_sessions_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE SET NULL,
    CONSTRAINT fk_user_system_sessions_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE workspace_skill_definitions
SET label = 'Organization Intelligence Setup',
    summary = 'Required Marketplace setup home for Organization Intelligence settings, business functions, and staff function ownership.',
    capabilities_json = JSON_MERGE_PATCH(
        COALESCE(capabilities_json, JSON_OBJECT()),
        JSON_OBJECT(
            'runtime_plugin', TRUE,
            'hr_analytics_setup', TRUE,
            'organization_intelligence', TRUE,
            'function_setup', TRUE,
            'function_assignment_setup', TRUE,
            'runtime_provider', 'WorkspaceHRAnalyticsGateService',
            'setup_url', 'organization_intelligence_setup.php',
            'runtime_url', 'hr_analytics.php'
        )
    ),
    navigation_json = JSON_OBJECT('label', 'Organization Intelligence Setup', 'url', 'workspace_skills.php?module=hr_analytics_setup'),
    updated_at = NOW()
WHERE skill_key = 'hr_analytics_setup';
