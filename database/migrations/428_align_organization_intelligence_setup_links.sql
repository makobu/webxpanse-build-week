-- Align Organization Intelligence setup metadata with the dedicated setup center.

UPDATE workspace_skill_definitions
SET plugin_metadata_json = JSON_SET(
        COALESCE(plugin_metadata_json, JSON_OBJECT()),
        '$.setup_url', 'organization_intelligence_setup.php',
        '$.settings_url', 'workspace_skills.php?module=hr_analytics_setup#setup',
        '$.runtime_url', 'hr_analytics.php'
    ),
    settings_schema_json = JSON_SET(
        COALESCE(settings_schema_json, JSON_OBJECT()),
        '$.requires_configuration', TRUE,
        '$.settings_url', 'workspace_skills.php?module=hr_analytics_setup#setup'
    ),
    updated_at = NOW()
WHERE skill_key = 'hr_analytics_setup';
