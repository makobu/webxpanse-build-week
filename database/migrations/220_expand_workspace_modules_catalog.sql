SET @module_type_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_definitions'
      AND column_name = 'module_type'
);
SET @add_module_type_sql := IF(
    @module_type_exists = 0,
    'ALTER TABLE workspace_skill_definitions ADD COLUMN module_type ENUM(''skill'',''plugin'') NOT NULL DEFAULT ''skill'' AFTER category',
    'SELECT 1'
);
PREPARE stmt FROM @add_module_type_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @plugin_metadata_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_definitions'
      AND column_name = 'plugin_metadata_json'
);
SET @add_plugin_metadata_sql := IF(
    @plugin_metadata_exists = 0,
    'ALTER TABLE workspace_skill_definitions ADD COLUMN plugin_metadata_json JSON NULL AFTER settings_schema_json',
    'SELECT 1'
);
PREPARE stmt FROM @add_plugin_metadata_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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
    'email_assistant',
    'Email Assistant',
    'Installs the workspace email assistant plugin for inbound instructions, outbound drafts, customer replies, and daily digests.',
    'assistant',
    'plugin',
    '1.0.0',
    JSON_OBJECT('runtime_plugin', true, 'email_assistant', true, 'ai_context', true, 'background_jobs', true),
    JSON_ARRAY(),
    JSON_OBJECT('requires_configuration', true, 'settings_url', 'settings.php?tab=email_assistant'),
    JSON_OBJECT(
        'readiness_provider', 'email_assistant',
        'runtime_provider', 'WorkspaceAssistantConfigService',
        'setup_url', 'settings.php?tab=email_assistant',
        'job_hints', JSON_ARRAY('cli/daily_digest_worker.php', 'assistant IMAP fetch worker')
    ),
    'email_assistant',
    JSON_OBJECT('label', 'Email Assistant', 'url', 'settings.php?tab=email_assistant'),
    JSON_ARRAY('workspace.skills.view', 'settings.email_assistant'),
    1
),
(
    'whatsapp_assistant',
    'WhatsApp Assistant',
    'Installs the workspace WhatsApp assistant plugin for internal WhatsApp instructions, short digests, and session keepalive support.',
    'assistant',
    'plugin',
    '1.0.0',
    JSON_OBJECT('runtime_plugin', true, 'whatsapp_assistant', true, 'ai_context', true, 'background_jobs', true),
    JSON_ARRAY(),
    JSON_OBJECT('requires_configuration', true, 'settings_url', 'settings.php?tab=email_assistant'),
    JSON_OBJECT(
        'readiness_provider', 'whatsapp_assistant',
        'runtime_provider', 'WorkspaceAssistantConfigService',
        'setup_url', 'settings.php?tab=email_assistant',
        'job_hints', JSON_ARRAY('cli/whatsapp_assistant_digest_worker.php', 'cli/whatsapp_assistant_session_worker.php', 'api/webhooks/whatsapp.php')
    ),
    'whatsapp_assistant',
    JSON_OBJECT('label', 'WhatsApp Assistant', 'url', 'settings.php?tab=email_assistant'),
    JSON_ARRAY('workspace.skills.view', 'settings.whatsapp'),
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
