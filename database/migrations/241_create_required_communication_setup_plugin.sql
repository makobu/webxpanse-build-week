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
    'communication_setup',
    'Communication Setup',
    'Required Marketplace setup home for connecting email or WhatsApp before Inbox and sending tools are available.',
    'communication',
    'plugin',
    '1.0.0',
    JSON_OBJECT(
        'runtime_plugin', TRUE,
        'required_core', TRUE,
        'protected_install', TRUE,
        'communication_gate', TRUE,
        'email_channel_setup', TRUE,
        'whatsapp_channel_setup', TRUE
    ),
    JSON_ARRAY(),
    JSON_OBJECT('requires_configuration', TRUE, 'settings_url', 'workspace_skills.php?module=communication_setup'),
    JSON_OBJECT(
        'readiness_provider', 'communication_setup',
        'runtime_provider', 'WorkspaceCommunicationGateService',
        'setup_url', 'workspace_skills.php?module=communication_setup',
        'required_core', TRUE,
        'protected_install', TRUE,
        'marketplace_profile', JSON_OBJECT(
            'thumbnail_url', 'images/marketplace/email-assistant.webp',
            'thumbnail_alt', 'Required communication setup with email and WhatsApp channel readiness.',
            'pitch', 'Set up the workspace communication channels in one required Marketplace plugin. Owners connect email or WhatsApp here before Inbox, email sending, WhatsApp sending, and bulk messaging are available.',
            'recommendations', JSON_ARRAY(
                'Complete this before inviting users into Inbox or outbound communication workflows.',
                'Start with either email or WhatsApp, then add the second channel when needed.',
                'Use channel health checks after setup to verify runtime readiness.'
            ),
            'prerequisites', JSON_ARRAY(
                'Workspace owner or admin access.',
                'Email provider or SMTP details, or a WhatsApp Business number.',
                'Platform OAuth or Meta app credentials when using managed connect flows.'
            ),
            'setup_guide', JSON_ARRAY(
                'Review email and WhatsApp health in this Marketplace plugin.',
                'Connect email or WhatsApp using the setup actions shown.',
                'Return to Inbox or sending tools after one channel is ready.'
            )
        )
    ),
    'communication_setup',
    JSON_OBJECT('label', 'Communication Setup', 'url', 'workspace_skills.php?module=communication_setup'),
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
    'communication_setup',
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
