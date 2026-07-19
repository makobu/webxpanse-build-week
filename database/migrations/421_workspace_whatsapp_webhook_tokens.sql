-- Workspace-owned WhatsApp webhook callback and verify tokens.

ALTER TABLE workspace_whatsapp_integrations
    ADD COLUMN IF NOT EXISTS webhook_token VARCHAR(64) NULL AFTER token_expires_at,
    ADD COLUMN IF NOT EXISTS webhook_verify_token MEDIUMTEXT NULL AFTER webhook_token,
    ADD COLUMN IF NOT EXISTS webhook_verified_at DATETIME NULL AFTER webhook_verify_token,
    ADD COLUMN IF NOT EXISTS webhook_last_status VARCHAR(50) NULL AFTER webhook_verified_at,
    ADD COLUMN IF NOT EXISTS webhook_last_error TEXT NULL AFTER webhook_last_status,
    ADD COLUMN IF NOT EXISTS webhook_last_event_at DATETIME NULL AFTER webhook_last_error;

SET @idx_whatsapp_webhook_token_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_whatsapp_integrations'
      AND index_name = 'idx_workspace_whatsapp_webhook_token'
);
SET @sql := IF(
    @idx_whatsapp_webhook_token_exists = 0,
    'CREATE INDEX idx_workspace_whatsapp_webhook_token ON workspace_whatsapp_integrations (webhook_token)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE workspace_skill_definitions
SET settings_schema_json = JSON_SET(
        COALESCE(settings_schema_json, JSON_OBJECT()),
        '$.requires_configuration', TRUE,
        '$.settings_url', 'workspace_skills.php?module=whatsapp&setup_tab=manual#setup'
    ),
    plugin_metadata_json = JSON_SET(
        COALESCE(plugin_metadata_json, JSON_OBJECT()),
        '$.setup_url', 'workspace_skills.php?module=whatsapp&setup_tab=manual#setup',
        '$.marketplace_profile.pitch',
            'Set up WhatsApp as its own CRM channel. Save the workspace Cloud API credentials, then use its dedicated webhook URL and verify token for inbound messages.',
        '$.marketplace_profile.recommendations',
            JSON_ARRAY(
                'Complete this when customers respond fastest on WhatsApp.',
                'Save manual phone number ID and access token before using runtime pages.',
                'Use the workspace webhook URL before live inbound traffic.',
                'Pair with WhatsApp Assistant only after the WhatsApp Business channel is ready.'
            ),
        '$.marketplace_profile.prerequisites',
            JSON_ARRAY(
                'Workspace owner or admin access.',
                'A WhatsApp Business account and phone number.',
                'Phone number ID and access token for the workspace number.',
                'Access to the Meta dashboard for this workspace number.'
            ),
        '$.marketplace_profile.setup_guide',
            JSON_ARRAY(
                'Save the workspace phone number ID, display number, WABA/business details, and access token.',
                'Copy the workspace webhook URL and verify token into Meta.',
                'Register pending Cloud API numbers or migrate On-Prem numbers from the Migration tab when needed.',
                'Use embedded signup only when that shortcut is available.'
            )
    ),
    updated_at = NOW()
WHERE skill_key = 'whatsapp';
