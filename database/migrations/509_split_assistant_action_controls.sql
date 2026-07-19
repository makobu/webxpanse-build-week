ALTER TABLE email_assistant_runs
    ADD COLUMN IF NOT EXISTS assistant_type ENUM('email', 'whatsapp') NOT NULL DEFAULT 'email' AFTER workspace_id;

ALTER TABLE email_assistant_runs
    MODIFY COLUMN source ENUM('email_assistant_inbound', 'whatsapp_assistant_inbound', 'conversation_ui', 'commercial_automation', 'manual') NOT NULL DEFAULT 'manual',
    MODIFY COLUMN thread_type ENUM('email_assistant', 'whatsapp_assistant', 'customer_email') DEFAULT NULL;

UPDATE email_assistant_runs
SET assistant_type = 'email'
WHERE assistant_type IS NULL OR assistant_type = '';

ALTER TABLE email_assistant_runs
    ADD KEY IF NOT EXISTS idx_email_assistant_runs_workspace_assistant_created (workspace_id, assistant_type, created_at),
    ADD KEY IF NOT EXISTS idx_email_assistant_runs_workspace_assistant_source (workspace_id, assistant_type, source, created_at);

ALTER TABLE email_assistant_action_queue
    ADD COLUMN IF NOT EXISTS assistant_type ENUM('email', 'whatsapp') NOT NULL DEFAULT 'email' AFTER workspace_id;

ALTER TABLE email_assistant_action_queue
    MODIFY COLUMN channel ENUM('email', 'whatsapp') NOT NULL DEFAULT 'email';

UPDATE email_assistant_action_queue
SET assistant_type = 'email'
WHERE assistant_type IS NULL OR assistant_type = '';

ALTER TABLE email_assistant_action_queue
    ADD KEY IF NOT EXISTS idx_email_assistant_action_queue_workspace_assistant_status (workspace_id, assistant_type, status, scheduled_at),
    ADD KEY IF NOT EXISTS idx_email_assistant_action_queue_workspace_assistant_run (workspace_id, assistant_type, run_id);
