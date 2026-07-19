INSERT INTO workspace_skill_definitions (
    skill_key,
    label,
    summary,
    category,
    module_type,
    version,
    capabilities_json,
    onboarding_fields_json,
    settings_schema_json,
    plugin_metadata_json,
    ai_context_provider,
    navigation_json,
    permissions_json,
    is_active
) VALUES (
    'hr_analytics_setup',
    'HR Analytics Setup',
    'Required Marketplace setup home for HR analytics settings, departments, and staff department readiness.',
    'people',
    'plugin',
    '1.0.0',
    JSON_OBJECT(
        'runtime_plugin', TRUE,
        'required_core', TRUE,
        'protected_install', TRUE,
        'hr_analytics_gate', TRUE,
        'hr_settings_setup', TRUE,
        'department_setup', TRUE,
        'department_assignment_setup', TRUE
    ),
    JSON_ARRAY(),
    JSON_OBJECT('requires_configuration', TRUE, 'settings_url', 'workspace_skills.php?module=hr_analytics_setup'),
    JSON_OBJECT(
        'readiness_provider', 'hr_analytics_setup',
        'runtime_provider', 'WorkspaceHRAnalyticsGateService',
        'setup_url', 'workspace_skills.php?module=hr_analytics_setup',
        'required_core', TRUE,
        'protected_install', TRUE,
        'marketplace_profile', JSON_OBJECT(
            'thumbnail_url', 'images/clarity-logo-256.png',
            'thumbnail_alt', 'Required HR analytics setup with department and staff readiness checks.',
            'pitch', 'Prepare HR Analytics from one owner-facing Marketplace plugin. Owners review scoring settings, workspace departments, and staff department assignments before the analytics dashboard unlocks.',
            'tags', JSON_ARRAY('Setup required', 'People ops', 'Required core'),
            'recommendations', JSON_ARRAY(
                'Complete this before using HR Analytics to coach people or compare departments.',
                'Create departments that match how the workspace actually operates.',
                'Assign at least the first active staff member before opening the dashboard.'
            ),
            'prerequisites', JSON_ARRAY(
                'Workspace owner or admin access.',
                'At least one active workspace member.',
                'Department structure for the workspace.'
            ),
            'setup_guide', JSON_ARRAY(
                'Review HR analytics settings in this Marketplace plugin.',
                'Create or confirm active workspace departments.',
                'Assign departments to active workspace members from Users.',
                'Open HR Analytics after readiness passes.'
            )
        )
    ),
    'hr_analytics_setup',
    JSON_OBJECT('label', 'HR Analytics Setup', 'url', 'workspace_skills.php?module=hr_analytics_setup'),
    JSON_ARRAY('workspace.skills.view'),
    1
)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    summary = VALUES(summary),
    category = VALUES(category),
    module_type = VALUES(module_type),
    version = VALUES(version),
    capabilities_json = VALUES(capabilities_json),
    onboarding_fields_json = VALUES(onboarding_fields_json),
    settings_schema_json = VALUES(settings_schema_json),
    plugin_metadata_json = VALUES(plugin_metadata_json),
    ai_context_provider = VALUES(ai_context_provider),
    navigation_json = VALUES(navigation_json),
    permissions_json = VALUES(permissions_json),
    is_active = VALUES(is_active),
    updated_at = NOW();

INSERT INTO workspace_skill_installs (
    workspace_id,
    skill_key,
    status,
    config_json,
    installed_by_user_id,
    updated_by_user_id,
    installed_at,
    disabled_at,
    uninstalled_at
)
SELECT
    w.id,
    'hr_analytics_setup',
    'installed',
    JSON_OBJECT('source', 'required_core_plugin_migration'),
    owner.user_id,
    owner.user_id,
    NOW(),
    NULL,
    NULL
FROM workspaces w
LEFT JOIN (
    SELECT workspace_id, MIN(user_id) AS user_id
    FROM workspace_memberships
    WHERE is_owner = TRUE
      AND membership_status = 'active'
    GROUP BY workspace_id
) owner ON owner.workspace_id = w.id
ON DUPLICATE KEY UPDATE
    status = 'installed',
    config_json = COALESCE(workspace_skill_installs.config_json, VALUES(config_json)),
    updated_by_user_id = VALUES(updated_by_user_id),
    disabled_at = NULL,
    uninstalled_at = NULL,
    updated_at = NOW();
