-- Split Google OAuth grants by capability and prepare token encryption metadata.

ALTER TABLE email_integrations
    ADD COLUMN IF NOT EXISTS oauth_grant_type VARCHAR(32) NOT NULL DEFAULT 'legacy_combined' AFTER scope,
    ADD COLUMN IF NOT EXISTS oauth_client_key VARCHAR(80) NULL AFTER oauth_grant_type,
    ADD COLUMN IF NOT EXISTS granted_scopes_json JSON NULL AFTER token_expires_at,
    ADD COLUMN IF NOT EXISTS required_scopes_json JSON NULL AFTER granted_scopes_json,
    ADD COLUMN IF NOT EXISTS scope_status ENUM('verified','missing_required','unknown','legacy') NOT NULL DEFAULT 'unknown' AFTER required_scopes_json,
    ADD COLUMN IF NOT EXISTS reconnect_required TINYINT(1) NOT NULL DEFAULT 0 AFTER scope_status,
    ADD COLUMN IF NOT EXISTS token_encrypted TINYINT(1) NOT NULL DEFAULT 0 AFTER reconnect_required,
    ADD COLUMN IF NOT EXISTS last_scope_verified_at DATETIME NULL AFTER token_encrypted,
    ADD KEY IF NOT EXISTS idx_email_integrations_grant_type (workspace_id, scope, oauth_grant_type, is_active),
    ADD KEY IF NOT EXISTS idx_email_integrations_scope_status (workspace_id, scope_status, reconnect_required);

UPDATE email_integrations
SET oauth_grant_type = 'legacy_combined'
WHERE oauth_grant_type IS NULL OR oauth_grant_type = '';

UPDATE email_integrations
SET scope_status = 'unknown',
    reconnect_required = 0
WHERE scope_status IS NULL OR scope_status = '';

SET @email_integrations_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'email_integrations'
);

SET @legacy_email_unique_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'email_integrations'
      AND index_name = 'uq_email_integrations_workspace_provider_scope'
);

SET @sql := IF(
    @email_integrations_exists > 0 AND @legacy_email_unique_exists > 0,
    'ALTER TABLE email_integrations DROP INDEX uq_email_integrations_workspace_provider_scope',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @grant_email_unique_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'email_integrations'
      AND index_name = 'uq_email_integrations_workspace_provider_scope_grant'
);

SET @sql := IF(
    @email_integrations_exists > 0 AND @grant_email_unique_exists = 0,
    'ALTER TABLE email_integrations ADD UNIQUE KEY uq_email_integrations_workspace_provider_scope_grant (workspace_id, provider, scope, oauth_grant_type)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE calendar_integrations
    ADD COLUMN IF NOT EXISTS oauth_grant_type VARCHAR(32) NOT NULL DEFAULT 'legacy_calendar' AFTER provider,
    ADD COLUMN IF NOT EXISTS oauth_client_key VARCHAR(80) NULL AFTER oauth_grant_type,
    ADD COLUMN IF NOT EXISTS granted_scopes_json JSON NULL AFTER token_expires_at,
    ADD COLUMN IF NOT EXISTS required_scopes_json JSON NULL AFTER granted_scopes_json,
    ADD COLUMN IF NOT EXISTS scope_status ENUM('verified','missing_required','unknown','legacy') NOT NULL DEFAULT 'unknown' AFTER required_scopes_json,
    ADD COLUMN IF NOT EXISTS reconnect_required TINYINT(1) NOT NULL DEFAULT 0 AFTER scope_status,
    ADD COLUMN IF NOT EXISTS token_encrypted TINYINT(1) NOT NULL DEFAULT 0 AFTER reconnect_required,
    ADD COLUMN IF NOT EXISTS last_scope_verified_at DATETIME NULL AFTER token_encrypted,
    ADD KEY IF NOT EXISTS idx_calendar_integrations_grant_type (workspace_id, provider, oauth_grant_type, sync_enabled),
    ADD KEY IF NOT EXISTS idx_calendar_integrations_scope_status (workspace_id, scope_status, reconnect_required);

UPDATE calendar_integrations
SET oauth_grant_type = CASE
        WHEN provider = 'google' THEN 'legacy_calendar'
        ELSE CONCAT(provider, '_calendar')
    END
WHERE oauth_grant_type IS NULL OR oauth_grant_type = '';

UPDATE calendar_integrations
SET scope_status = 'unknown',
    reconnect_required = 0
WHERE scope_status IS NULL OR scope_status = '';

CREATE TABLE IF NOT EXISTS oauth_connection_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NULL,
    user_id INT NULL,
    provider VARCHAR(64) NULL,
    surface VARCHAR(64) NOT NULL,
    oauth_grant_type VARCHAR(32) NOT NULL,
    oauth_client_key VARCHAR(80) NULL,
    status ENUM('success','warning','failed') NOT NULL DEFAULT 'success',
    operation VARCHAR(64) NOT NULL,
    message VARCHAR(500) NULL,
    requested_scopes_json JSON NULL,
    granted_scopes_json JSON NULL,
    integration_table VARCHAR(64) NULL,
    integration_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_oauth_audit_workspace_created (workspace_id, created_at),
    KEY idx_oauth_audit_provider_grant (provider, oauth_grant_type, created_at),
    KEY idx_oauth_audit_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
