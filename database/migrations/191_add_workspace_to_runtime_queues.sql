-- Migration 191: add workspace_id to runtime queue and log tables

SET @workflow_queue_alter_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'workflow_queue'
    ),
    'ALTER TABLE workflow_queue
        ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER contact_id,
        ADD INDEX IF NOT EXISTS idx_workflow_queue_workspace_status (workspace_id, status, created_at)',
    'SELECT 1'
);
PREPARE workflow_queue_alter_stmt FROM @workflow_queue_alter_sql;
EXECUTE workflow_queue_alter_stmt;
DEALLOCATE PREPARE workflow_queue_alter_stmt;

SET @workflow_retry_queue_alter_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'workflow_retry_queue'
    ),
    'ALTER TABLE workflow_retry_queue
        ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER workflow_id,
        ADD INDEX IF NOT EXISTS idx_workflow_retry_workspace_due (workspace_id, status, retry_after)',
    'SELECT 1'
);
PREPARE workflow_retry_queue_alter_stmt FROM @workflow_retry_queue_alter_sql;
EXECUTE workflow_retry_queue_alter_stmt;
DEALLOCATE PREPARE workflow_retry_queue_alter_stmt;

SET @sms_queue_alter_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'sms_queue'
    ),
    'ALTER TABLE sms_queue
        ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER message_id,
        ADD INDEX IF NOT EXISTS idx_sms_queue_workspace_status (workspace_id, status, scheduled_at)',
    'SELECT 1'
);
PREPARE sms_queue_alter_stmt FROM @sms_queue_alter_sql;
EXECUTE sms_queue_alter_stmt;
DEALLOCATE PREPARE sms_queue_alter_stmt;

SET @whatsapp_queue_alter_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'whatsapp_queue'
    ),
    'ALTER TABLE whatsapp_queue
        ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER message_id,
        ADD INDEX IF NOT EXISTS idx_whatsapp_queue_workspace_status (workspace_id, status, scheduled_at)',
    'SELECT 1'
);
PREPARE whatsapp_queue_alter_stmt FROM @whatsapp_queue_alter_sql;
EXECUTE whatsapp_queue_alter_stmt;
DEALLOCATE PREPARE whatsapp_queue_alter_stmt;

SET @ai_autoresponder_logs_alter_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'ai_autoresponder_logs'
    ),
    'ALTER TABLE ai_autoresponder_logs
        ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER queue_id,
        ADD INDEX IF NOT EXISTS idx_ai_auto_logs_workspace_created (workspace_id, created_at)',
    'SELECT 1'
);
PREPARE ai_autoresponder_logs_alter_stmt FROM @ai_autoresponder_logs_alter_sql;
EXECUTE ai_autoresponder_logs_alter_stmt;
DEALLOCATE PREPARE ai_autoresponder_logs_alter_stmt;

SET @workflow_queue_backfill_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'workflow_queue'
    ),
    'UPDATE workflow_queue wq JOIN contacts c ON c.id = wq.contact_id
     SET wq.workspace_id = c.workspace_id
     WHERE wq.workspace_id IS NULL',
    'SELECT 1'
);
PREPARE workflow_queue_backfill_stmt FROM @workflow_queue_backfill_sql;
EXECUTE workflow_queue_backfill_stmt;
DEALLOCATE PREPARE workflow_queue_backfill_stmt;

SET @workflow_retry_queue_backfill_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'workflow_retry_queue'
    ),
    'UPDATE workflow_retry_queue wrq JOIN workflow_executions we ON we.id = wrq.workflow_execution_id
     SET wrq.workspace_id = we.workspace_id
     WHERE wrq.workspace_id IS NULL',
    'SELECT 1'
);
PREPARE workflow_retry_queue_backfill_stmt FROM @workflow_retry_queue_backfill_sql;
EXECUTE workflow_retry_queue_backfill_stmt;
DEALLOCATE PREPARE workflow_retry_queue_backfill_stmt;

SET @sms_queue_backfill_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'sms_messages'
    ),
    'UPDATE sms_queue sq JOIN sms_messages sm ON sm.id = sq.message_id SET sq.workspace_id = sm.workspace_id WHERE sq.workspace_id IS NULL',
    'SELECT 1'
);
PREPARE sms_queue_backfill_stmt FROM @sms_queue_backfill_sql;
EXECUTE sms_queue_backfill_stmt;
DEALLOCATE PREPARE sms_queue_backfill_stmt;

SET @whatsapp_queue_backfill_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'whatsapp_queue'
    ),
    'UPDATE whatsapp_queue wq JOIN whatsapp_messages wm ON wm.id = wq.message_id
     SET wq.workspace_id = wm.workspace_id
     WHERE wq.workspace_id IS NULL',
    'SELECT 1'
);
PREPARE whatsapp_queue_backfill_stmt FROM @whatsapp_queue_backfill_sql;
EXECUTE whatsapp_queue_backfill_stmt;
DEALLOCATE PREPARE whatsapp_queue_backfill_stmt;

SET @ai_autoresponder_logs_backfill_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'ai_autoresponder_logs'
    ),
    'UPDATE ai_autoresponder_logs aal
     LEFT JOIN ai_autoresponder_queue aaq ON aaq.id = aal.queue_id
     LEFT JOIN communications comm ON comm.id = aal.communication_id
     LEFT JOIN contacts c ON c.id = aal.contact_id
     SET aal.workspace_id = COALESCE(aaq.workspace_id, comm.workspace_id, c.workspace_id)
     WHERE aal.workspace_id IS NULL',
    'SELECT 1'
);
PREPARE ai_autoresponder_logs_backfill_stmt FROM @ai_autoresponder_logs_backfill_sql;
EXECUTE ai_autoresponder_logs_backfill_stmt;
DEALLOCATE PREPARE ai_autoresponder_logs_backfill_stmt;
