-- Point existing catalog rows at Marketplace-owned plugin setup surfaces.

UPDATE workspace_skill_definitions
SET settings_schema_json = JSON_SET(COALESCE(settings_schema_json, JSON_OBJECT()), '$.requires_configuration', true, '$.settings_url', 'workspace_skills.php?module=communication_setup&setup_tab=channels#setup'),
    plugin_metadata_json = JSON_SET(COALESCE(plugin_metadata_json, JSON_OBJECT()), '$.setup_url', 'workspace_skills.php?module=communication_setup&setup_tab=channels#setup'),
    navigation_json = JSON_SET(COALESCE(navigation_json, JSON_OBJECT()), '$.url', 'workspace_skills.php?module=communication_setup&setup_tab=channels#setup'),
    permissions_json = JSON_ARRAY('workspace.skills.view')
WHERE skill_key = 'communication_setup';

UPDATE workspace_skill_definitions
SET settings_schema_json = JSON_SET(COALESCE(settings_schema_json, JSON_OBJECT()), '$.requires_configuration', true, '$.settings_url', 'workspace_skills.php?module=email_assistant&setup_tab=identity#setup'),
    plugin_metadata_json = JSON_SET(COALESCE(plugin_metadata_json, JSON_OBJECT()), '$.setup_url', 'workspace_skills.php?module=email_assistant&setup_tab=identity#setup'),
    navigation_json = JSON_SET(COALESCE(navigation_json, JSON_OBJECT()), '$.url', 'workspace_skills.php?module=email_assistant&setup_tab=identity#setup'),
    permissions_json = JSON_ARRAY('workspace.skills.view')
WHERE skill_key = 'email_assistant';

UPDATE workspace_skill_definitions
SET settings_schema_json = JSON_SET(COALESCE(settings_schema_json, JSON_OBJECT()), '$.requires_configuration', true, '$.settings_url', 'workspace_skills.php?module=whatsapp_assistant#setup'),
    plugin_metadata_json = JSON_SET(COALESCE(plugin_metadata_json, JSON_OBJECT()), '$.setup_url', 'workspace_skills.php?module=whatsapp_assistant#setup'),
    navigation_json = JSON_SET(COALESCE(navigation_json, JSON_OBJECT()), '$.url', 'workspace_skills.php?module=whatsapp_assistant#setup'),
    permissions_json = JSON_ARRAY('workspace.skills.view')
WHERE skill_key = 'whatsapp_assistant';

UPDATE workspace_skill_definitions
SET settings_schema_json = JSON_SET(COALESCE(settings_schema_json, JSON_OBJECT()), '$.requires_configuration', true, '$.settings_url', 'workspace_skills.php?module=sms_channel#setup'),
    plugin_metadata_json = JSON_SET(COALESCE(plugin_metadata_json, JSON_OBJECT()), '$.setup_url', 'workspace_skills.php?module=sms_channel#setup'),
    navigation_json = JSON_SET(COALESCE(navigation_json, JSON_OBJECT()), '$.url', 'workspace_skills.php?module=sms_channel#setup'),
    permissions_json = JSON_ARRAY('workspace.skills.view')
WHERE skill_key = 'sms_channel';

UPDATE workspace_skill_definitions
SET settings_schema_json = JSON_SET(COALESCE(settings_schema_json, JSON_OBJECT()), '$.requires_configuration', true, '$.settings_url', 'workspace_skills.php?module=calendar_meetings#setup'),
    plugin_metadata_json = JSON_SET(COALESCE(plugin_metadata_json, JSON_OBJECT()), '$.setup_url', 'workspace_skills.php?module=calendar_meetings#setup'),
    navigation_json = JSON_SET(COALESCE(navigation_json, JSON_OBJECT()), '$.url', 'workspace_skills.php?module=calendar_meetings#setup'),
    permissions_json = JSON_ARRAY('workspace.skills.view')
WHERE skill_key = 'calendar_meetings';

UPDATE workspace_skill_definitions
SET settings_schema_json = JSON_SET(COALESCE(settings_schema_json, JSON_OBJECT()), '$.requires_configuration', true, '$.settings_url', 'workspace_skills.php?module=finance#setup'),
    plugin_metadata_json = JSON_SET(COALESCE(plugin_metadata_json, JSON_OBJECT()), '$.setup_url', 'workspace_skills.php?module=finance#setup'),
    navigation_json = JSON_SET(COALESCE(navigation_json, JSON_OBJECT()), '$.url', 'workspace_skills.php?module=finance#setup'),
    permissions_json = JSON_ARRAY('workspace.skills.view')
WHERE skill_key = 'finance';

UPDATE workspace_skill_definitions
SET settings_schema_json = JSON_SET(COALESCE(settings_schema_json, JSON_OBJECT()), '$.requires_configuration', true, '$.settings_url', 'workspace_skills.php?module=ai_coach&setup_tab=readiness#setup'),
    plugin_metadata_json = JSON_SET(COALESCE(plugin_metadata_json, JSON_OBJECT()), '$.setup_url', 'workspace_skills.php?module=ai_coach&setup_tab=readiness#setup'),
    navigation_json = JSON_SET(COALESCE(navigation_json, JSON_OBJECT()), '$.url', 'workspace_skills.php?module=ai_coach&setup_tab=readiness#setup'),
    permissions_json = JSON_ARRAY('workspace.skills.view')
WHERE skill_key = 'ai_coach';
