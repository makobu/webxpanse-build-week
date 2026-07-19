-- Migration 193: make email integrations unique per workspace instead of globally

SET @email_integrations_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'email_integrations'
);

SET @legacy_unique_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'email_integrations'
      AND index_name = 'uq_email_integrations_provider_scope'
);

SET @sql := IF(
    @email_integrations_exists > 0 AND @legacy_unique_exists > 0,
    'ALTER TABLE email_integrations DROP INDEX uq_email_integrations_provider_scope',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @workspace_unique_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'email_integrations'
      AND index_name = 'uq_email_integrations_workspace_provider_scope'
);

SET @sql := IF(
    @email_integrations_exists > 0 AND @workspace_unique_exists = 0,
    'ALTER TABLE email_integrations
        ADD UNIQUE KEY uq_email_integrations_workspace_provider_scope (workspace_id, provider, scope)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
