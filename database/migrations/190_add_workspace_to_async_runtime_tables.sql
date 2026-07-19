ALTER TABLE emails ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE emails ADD KEY idx_emails_workspace_status (workspace_id, status, created_at);

ALTER TABLE email_queue ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE email_queue ADD KEY idx_email_queue_workspace_status (workspace_id, status, priority, scheduled_at);

ALTER TABLE whatsapp_messages ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE whatsapp_messages ADD KEY idx_whatsapp_messages_workspace_status (workspace_id, status, created_at);

ALTER TABLE sms_messages ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE sms_messages ADD KEY idx_sms_messages_workspace_status (workspace_id, status, created_at);

ALTER TABLE campaign_enrollments ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE campaign_enrollments ADD KEY idx_campaign_enrollments_workspace_status (workspace_id, status, next_run_at);

ALTER TABLE campaign_step_executions ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE campaign_step_executions ADD KEY idx_campaign_step_exec_workspace_status (workspace_id, status, created_at);

ALTER TABLE campaign_queue ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE campaign_queue ADD KEY idx_campaign_queue_workspace_status (workspace_id, status, execute_at);

ALTER TABLE ai_autoresponder_queue ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE ai_autoresponder_queue ADD KEY idx_ai_autoresponder_workspace_status (workspace_id, status, available_at);

ALTER TABLE scheduled_reports ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE scheduled_reports ADD KEY idx_scheduled_reports_workspace_status (workspace_id, is_active, next_run_at);

ALTER TABLE scheduled_report_runs ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE scheduled_report_runs ADD KEY idx_scheduled_report_runs_workspace (workspace_id, executed_at);

ALTER TABLE calendar_integrations ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE calendar_integrations ADD KEY idx_calendar_integrations_workspace_sync (workspace_id, sync_enabled);

ALTER TABLE email_integrations ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE email_integrations ADD KEY idx_email_integrations_workspace_scope (workspace_id, scope, is_active);

ALTER TABLE workflow_executions ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE workflow_executions ADD KEY idx_workflow_executions_workspace_status (workspace_id, workflow_id, executed_at);

ALTER TABLE scheduled_workflow_actions ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE scheduled_workflow_actions ADD KEY idx_scheduled_workflow_actions_workspace_due (workspace_id, status, scheduled_for);

ALTER TABLE report_executions ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE report_executions ADD KEY idx_report_executions_workspace_report (workspace_id, report_id, executed_at);

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emails'),
    'UPDATE emails e INNER JOIN contacts c ON c.id = e.contact_id SET e.workspace_id = c.workspace_id WHERE e.workspace_id IS NULL AND c.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_queue')
    AND EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emails'),
    'UPDATE email_queue eq INNER JOIN emails e ON e.id = eq.email_id SET eq.workspace_id = e.workspace_id WHERE eq.workspace_id IS NULL AND e.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_messages'),
    'UPDATE whatsapp_messages wm INNER JOIN contacts c ON c.id = wm.contact_id SET wm.workspace_id = c.workspace_id WHERE wm.workspace_id IS NULL AND c.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_messages'),
    'UPDATE sms_messages sm INNER JOIN contacts c ON c.id = sm.contact_id SET sm.workspace_id = c.workspace_id WHERE sm.workspace_id IS NULL AND c.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_enrollments'),
    'UPDATE campaign_enrollments ce INNER JOIN campaigns c ON c.id = ce.campaign_id SET ce.workspace_id = c.workspace_id WHERE ce.workspace_id IS NULL AND c.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_step_executions'),
    'UPDATE campaign_step_executions cse INNER JOIN campaigns c ON c.id = cse.campaign_id SET cse.workspace_id = c.workspace_id WHERE cse.workspace_id IS NULL AND c.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_queue'),
    'UPDATE campaign_queue cq INNER JOIN campaigns c ON c.id = cq.campaign_id SET cq.workspace_id = c.workspace_id WHERE cq.workspace_id IS NULL AND c.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_autoresponder_queue'),
    'UPDATE ai_autoresponder_queue q INNER JOIN communications c ON c.id = q.communication_id SET q.workspace_id = c.workspace_id WHERE q.workspace_id IS NULL AND c.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduled_reports'),
    'UPDATE scheduled_reports sr INNER JOIN reports r ON r.id = sr.report_id SET sr.workspace_id = r.workspace_id WHERE sr.workspace_id IS NULL AND r.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduled_report_runs')
    AND EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduled_reports'),
    'UPDATE scheduled_report_runs srr INNER JOIN scheduled_reports sr ON sr.id = srr.scheduled_report_id SET srr.workspace_id = sr.workspace_id WHERE srr.workspace_id IS NULL AND sr.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_executions'),
    'UPDATE workflow_executions we INNER JOIN workflows w ON w.id = we.workflow_id SET we.workspace_id = w.workspace_id WHERE we.workspace_id IS NULL AND w.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduled_workflow_actions')
    AND EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_executions'),
    'UPDATE scheduled_workflow_actions swa INNER JOIN workflow_executions we ON we.id = swa.execution_id SET swa.workspace_id = we.workspace_id WHERE swa.workspace_id IS NULL AND we.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_executions'),
    'UPDATE report_executions re INNER JOIN reports r ON r.id = re.report_id SET re.workspace_id = r.workspace_id WHERE re.workspace_id IS NULL AND r.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_integrations'),
    'UPDATE calendar_integrations ci INNER JOIN (SELECT wm.user_id, MIN(wm.workspace_id) AS workspace_id FROM workspace_memberships wm WHERE wm.membership_status = ''active'' GROUP BY wm.user_id HAVING COUNT(*) = 1) single_workspace ON single_workspace.user_id = ci.user_id SET ci.workspace_id = single_workspace.workspace_id WHERE ci.workspace_id IS NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_integrations'),
    'UPDATE email_integrations ei INNER JOIN (SELECT wm.user_id, MIN(wm.workspace_id) AS workspace_id FROM workspace_memberships wm WHERE wm.membership_status = ''active'' GROUP BY wm.user_id HAVING COUNT(*) = 1) single_workspace ON single_workspace.user_id = ei.connected_by_user_id SET ei.workspace_id = single_workspace.workspace_id WHERE ei.workspace_id IS NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
