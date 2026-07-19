CREATE TABLE IF NOT EXISTS hr_analytics_settings (
    id INT PRIMARY KEY,
    ai_enabled TINYINT(1) NOT NULL DEFAULT 1,
    scoring_weights_json JSON NULL,
    thresholds_json JSON NULL,
    department_mappings_json JSON NULL,
    prompt_config_json JSON NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_hr_analytics_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('hr.analytics.view', 'HR Analytics View', 'View HR analytics dashboards and insights', TRUE),
('hr.analytics.manage', 'HR Analytics Manage', 'Create coaching actions and manage HR analytics controls', TRUE),
('hr.analytics.settings', 'HR Analytics Settings', 'Manage HR analytics scoring, thresholds, and AI settings', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO roles (name, slug, description, is_system, is_active) VALUES
('HR Manager', 'hr_manager', 'Privileged HR analytics and coaching access', TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system),
    is_active = VALUES(is_active);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('hr.analytics.view', 'hr.analytics.manage', 'hr.analytics.settings')
WHERE r.slug = 'admin'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('hr.analytics.view', 'hr.analytics.manage', 'hr.analytics.settings')
WHERE r.slug = 'hr_manager'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO hr_analytics_settings (
    id,
    ai_enabled,
    scoring_weights_json,
    thresholds_json,
    department_mappings_json,
    prompt_config_json
)
VALUES (
    1,
    1,
    JSON_OBJECT(
        'marketing', JSON_OBJECT(
            'task_completion', 0.18,
            'timeliness', 0.12,
            'activity_consistency', 0.18,
            'outcome_impact', 0.17,
            'pipeline_movement', 0.05,
            'campaign_output', 0.20,
            'workload_balance', 0.10
        ),
        'sales', JSON_OBJECT(
            'task_completion', 0.18,
            'timeliness', 0.14,
            'activity_consistency', 0.13,
            'outcome_impact', 0.20,
            'pipeline_movement', 0.22,
            'campaign_output', 0.03,
            'workload_balance', 0.10
        ),
        'general', JSON_OBJECT(
            'task_completion', 0.26,
            'timeliness', 0.19,
            'activity_consistency', 0.14,
            'outcome_impact', 0.12,
            'pipeline_movement', 0.04,
            'campaign_output', 0.03,
            'workload_balance', 0.22
        )
    ),
    JSON_OBJECT(
        'high_performer', 75,
        'at_risk', 45,
        'needs_coaching', 55,
        'overloaded_task_count', 7,
        'inactive_days', 10
    ),
    JSON_OBJECT(
        'admin', 'Leadership',
        'owner', 'Leadership',
        'marketing', 'Marketing',
        'sales', 'Sales',
        'viewer', 'Operations'
    ),
    JSON_OBJECT(
        'manager_focus', 'Keep recommendations concrete, explainable, and tied to observable team behaviour.',
        'swot_focus', 'Evaluate people execution, coordination, role fit, workload balance, and momentum.'
    )
)
ON DUPLICATE KEY UPDATE
    ai_enabled = VALUES(ai_enabled),
    scoring_weights_json = VALUES(scoring_weights_json),
    thresholds_json = VALUES(thresholds_json),
    department_mappings_json = VALUES(department_mappings_json),
    prompt_config_json = VALUES(prompt_config_json);
