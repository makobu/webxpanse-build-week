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
) VALUES
(
    'email',
    'Email',
    'Owns workspace email channel setup, email health, send readiness, inbox readiness, templates, signatures, and email runtime links.',
    'communication',
    'plugin',
    '1.0.0',
    JSON_OBJECT(
        'runtime_plugin', TRUE,
        'required_core', TRUE,
        'protected_install', TRUE,
        'communication_channel', TRUE,
        'email_channel_setup', TRUE
    ),
    JSON_ARRAY(),
    JSON_OBJECT('requires_configuration', TRUE, 'settings_url', 'workspace_skills.php?module=email&setup_tab=outreach_email#setup'),
    JSON_OBJECT(
        'readiness_provider', 'email',
        'runtime_provider', 'EmailIntegrationService',
        'setup_url', 'workspace_skills.php?module=email&setup_tab=outreach_email#setup',
        'required_core', TRUE,
        'protected_install', TRUE,
        'marketplace_profile', JSON_OBJECT(
            'thumbnail_url', 'images/marketplace/email-assistant.webp',
            'thumbnail_alt', 'Email channel setup and readiness.',
            'pitch', 'Connect and monitor workspace email sending and inbox readiness separately from automation assistants.',
            'tags', JSON_ARRAY('Setup required', 'Connect channels', 'Communication', 'Required core'),
            'recommendations', JSON_ARRAY(
                'Connect outreach or nurture email before relying on email sending tools.',
                'Verify channel health after saving SMTP or OAuth details.',
                'Use Email Assistant only after this email channel is ready.'
            ),
            'prerequisites', JSON_ARRAY(
                'Workspace owner or admin access.',
                'SMTP, OAuth, or provider email details.'
            ),
            'setup_guide', JSON_ARRAY(
                'Open Email setup from Marketplace.',
                'Save outreach or nurture email credentials.',
                'Run the email readiness test and return to Email or Inbox after ready.'
            )
        )
    ),
    'email',
    JSON_OBJECT('label', 'Email', 'url', 'workspace_skills.php?module=email'),
    JSON_ARRAY('workspace.skills.view'),
    1
),
(
    'whatsapp',
    'WhatsApp',
    'Owns WhatsApp Business setup, webhook and account readiness, send health, queue visibility, and WhatsApp runtime links.',
    'communication',
    'plugin',
    '1.0.0',
    JSON_OBJECT(
        'runtime_plugin', TRUE,
        'required_core', TRUE,
        'protected_install', TRUE,
        'communication_channel', TRUE,
        'whatsapp_channel_setup', TRUE
    ),
    JSON_ARRAY(),
    JSON_OBJECT('requires_configuration', TRUE, 'settings_url', 'workspace_skills.php?module=whatsapp&setup_tab=whatsapp#setup'),
    JSON_OBJECT(
        'readiness_provider', 'whatsapp',
        'runtime_provider', 'WorkspaceConnectService, WhatsAppService',
        'setup_url', 'workspace_skills.php?module=whatsapp&setup_tab=whatsapp#setup',
        'required_core', TRUE,
        'protected_install', TRUE,
        'marketplace_profile', JSON_OBJECT(
            'thumbnail_url', 'images/marketplace/whatsapp-assistant.webp',
            'thumbnail_alt', 'WhatsApp Business channel setup and readiness.',
            'pitch', 'Connect and monitor WhatsApp Business messaging separately from WhatsApp Assistant automation.',
            'tags', JSON_ARRAY('Setup required', 'Connect channels', 'Communication', 'Required core'),
            'recommendations', JSON_ARRAY(
                'Connect WhatsApp Business before using WhatsApp runtime pages or WhatsApp Assistant.',
                'Keep webhook and Meta account readiness visible in one module.',
                'Run the WhatsApp readiness test after setup changes.'
            ),
            'prerequisites', JSON_ARRAY(
                'Workspace owner or admin access.',
                'WhatsApp Business number and Meta app credentials.'
            ),
            'setup_guide', JSON_ARRAY(
                'Open WhatsApp setup from Marketplace.',
                'Save WhatsApp Business connection details.',
                'Run the readiness test and return to WhatsApp messages or Inbox after ready.'
            )
        )
    ),
    'whatsapp',
    JSON_OBJECT('label', 'WhatsApp', 'url', 'workspace_skills.php?module=whatsapp'),
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

UPDATE workspace_skill_definitions
SET
    summary = 'Legacy compatibility layer for workspaces that installed Communication Setup before Email and WhatsApp were split into separate Marketplace modules.',
    settings_schema_json = JSON_SET(COALESCE(settings_schema_json, JSON_OBJECT()), '$.settings_url', 'workspace_skills.php?module=email&setup_tab=outreach_email#setup'),
    plugin_metadata_json = JSON_SET(
        COALESCE(plugin_metadata_json, JSON_OBJECT()),
        '$.legacy_hidden_from_marketplace', TRUE,
        '$.setup_url', 'workspace_skills.php?module=email&setup_tab=outreach_email#setup'
    ),
    navigation_json = JSON_SET(COALESCE(navigation_json, JSON_OBJECT()), '$.url', 'workspace_skills.php?module=email&setup_tab=outreach_email#setup'),
    updated_at = NOW()
WHERE skill_key = 'communication_setup';

INSERT INTO workspace_skill_catalog_overrides (
    skill_key,
    catalog_status,
    marketplace_profile_json
) VALUES (
    'communication_setup',
    'hidden',
    JSON_OBJECT('legacy_hidden_from_marketplace', TRUE)
)
ON DUPLICATE KEY UPDATE
    catalog_status = 'hidden',
    marketplace_profile_json = JSON_SET(COALESCE(workspace_skill_catalog_overrides.marketplace_profile_json, JSON_OBJECT()), '$.legacy_hidden_from_marketplace', TRUE),
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
    'email',
    'installed',
    JSON_OBJECT('source', 'split_communication_modules_migration'),
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
    'whatsapp',
    'installed',
    JSON_OBJECT('source', 'split_communication_modules_migration'),
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
