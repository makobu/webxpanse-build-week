-- Manual-first WhatsApp Marketplace setup and workspace-scoped migration log.

CREATE TABLE IF NOT EXISTS whatsapp_migration_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NULL,
    user_id INT NULL,
    phone_number_id VARCHAR(191) NULL,
    whatsapp_business_account_id VARCHAR(191) NULL,
    step VARCHAR(50) NOT NULL,
    metadata_hash VARCHAR(255),
    api_status VARCHAR(50),
    api_version VARCHAR(20),
    success BOOLEAN DEFAULT FALSE,
    error_message TEXT,
    data_localization_region VARCHAR(2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_whatsapp_migration_workspace (workspace_id, created_at),
    INDEX idx_whatsapp_migration_user (user_id),
    INDEX idx_whatsapp_migration_phone (phone_number_id),
    INDEX idx_step (step),
    INDEX idx_success (success),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @workspace_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'whatsapp_migration_log'
      AND column_name = 'workspace_id'
);
SET @sql := IF(
    @workspace_id_exists = 0,
    'ALTER TABLE whatsapp_migration_log ADD COLUMN workspace_id INT NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @user_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'whatsapp_migration_log'
      AND column_name = 'user_id'
);
SET @sql := IF(
    @user_id_exists = 0,
    'ALTER TABLE whatsapp_migration_log ADD COLUMN user_id INT NULL AFTER workspace_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @phone_number_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'whatsapp_migration_log'
      AND column_name = 'phone_number_id'
);
SET @sql := IF(
    @phone_number_id_exists = 0,
    'ALTER TABLE whatsapp_migration_log ADD COLUMN phone_number_id VARCHAR(191) NULL AFTER user_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @waba_id_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'whatsapp_migration_log'
      AND column_name = 'whatsapp_business_account_id'
);
SET @sql := IF(
    @waba_id_exists = 0,
    'ALTER TABLE whatsapp_migration_log ADD COLUMN whatsapp_business_account_id VARCHAR(191) NULL AFTER phone_number_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_workspace_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'whatsapp_migration_log'
      AND index_name = 'idx_whatsapp_migration_workspace'
);
SET @sql := IF(
    @idx_workspace_exists = 0,
    'CREATE INDEX idx_whatsapp_migration_workspace ON whatsapp_migration_log (workspace_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_user_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'whatsapp_migration_log'
      AND index_name = 'idx_whatsapp_migration_user'
);
SET @sql := IF(
    @idx_user_exists = 0,
    'CREATE INDEX idx_whatsapp_migration_user ON whatsapp_migration_log (user_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_phone_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'whatsapp_migration_log'
      AND index_name = 'idx_whatsapp_migration_phone'
);
SET @sql := IF(
    @idx_phone_exists = 0,
    'CREATE INDEX idx_whatsapp_migration_phone ON whatsapp_migration_log (phone_number_id)',
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
            'Set up WhatsApp as its own CRM channel. Manual Cloud API credentials come first, with a workspace webhook URL, number registration or On-Prem migration, and optional Meta embedded signup when available.',
        '$.marketplace_profile.recommendations',
            JSON_ARRAY(
                'Complete this when customers respond fastest on WhatsApp.',
                'Save manual phone number ID and access token before using runtime pages.',
                'Configure webhooks and use migration tools before live traffic when needed.',
                'Pair with WhatsApp Assistant only after the WhatsApp Business channel is ready.'
            ),
        '$.marketplace_profile.prerequisites',
            JSON_ARRAY(
                'Workspace owner or admin access.',
                'A WhatsApp Business account and phone number.',
                'Phone number ID and access token for the workspace number.',
                'Workspace webhook URL and verify token generated from manual setup.'
            ),
        '$.marketplace_profile.setup_guide',
            JSON_ARRAY(
                'Save the workspace phone number ID, display number, WABA/business details, and access token.',
                'Copy this workspace webhook callback and verify token into Meta.',
                'Register pending Cloud API numbers or migrate On-Prem numbers from the Migration tab when needed.',
                'Use embedded signup only when Meta signup is configured.'
            )
    ),
    updated_at = NOW()
WHERE skill_key = 'whatsapp';
