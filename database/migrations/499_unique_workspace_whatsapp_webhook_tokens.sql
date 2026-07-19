-- Enforce one workspace per WhatsApp webhook token.

UPDATE workspace_whatsapp_integrations dup
JOIN workspace_whatsapp_integrations keeper
  ON keeper.webhook_token = dup.webhook_token
 AND keeper.id < dup.id
SET dup.webhook_token = CONCAT(REPLACE(UUID(), '-', ''), LEFT(MD5(dup.id), 8)),
    dup.webhook_last_status = COALESCE(dup.webhook_last_status, 'rotated_duplicate_token'),
    dup.webhook_last_error = 'Webhook token was rotated because another workspace already used it.',
    dup.updated_at = NOW()
WHERE dup.webhook_token IS NOT NULL
  AND dup.webhook_token <> '';

SET @idx_workspace_whatsapp_webhook_token_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_whatsapp_integrations'
      AND index_name = 'idx_workspace_whatsapp_webhook_token'
);
SET @sql := IF(
    @idx_workspace_whatsapp_webhook_token_exists > 0,
    'DROP INDEX idx_workspace_whatsapp_webhook_token ON workspace_whatsapp_integrations',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @uq_workspace_whatsapp_webhook_token_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_whatsapp_integrations'
      AND index_name = 'uq_workspace_whatsapp_webhook_token'
);
SET @sql := IF(
    @uq_workspace_whatsapp_webhook_token_exists = 0,
    'CREATE UNIQUE INDEX uq_workspace_whatsapp_webhook_token ON workspace_whatsapp_integrations (webhook_token)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
