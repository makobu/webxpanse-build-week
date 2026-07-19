-- Owner Helpline Support Center V1.
-- Adds owner-visible support case fields to Platform Ops events.

ALTER TABLE default_workspace_ops_events
    ADD COLUMN IF NOT EXISTS owner_visible TINYINT(1) NOT NULL DEFAULT 0 AFTER assigned_user_id,
    ADD COLUMN IF NOT EXISTS owner_subject VARCHAR(255) NULL AFTER owner_visible,
    ADD COLUMN IF NOT EXISTS owner_category VARCHAR(80) NULL AFTER owner_subject,
    ADD COLUMN IF NOT EXISTS conversation_thread_id INT NULL AFTER owner_category,
    ADD COLUMN IF NOT EXISTS last_owner_message_at DATETIME NULL AFTER conversation_thread_id,
    ADD COLUMN IF NOT EXISTS last_operator_message_at DATETIME NULL AFTER last_owner_message_at;

ALTER TABLE default_workspace_ops_events
    ADD KEY IF NOT EXISTS idx_default_ops_owner_visible_workspace (owner_workspace_id, owner_visible, status, last_seen_at),
    ADD KEY IF NOT EXISTS idx_default_ops_owner_cases_queue (default_workspace_id, owner_visible, status, priority, last_seen_at),
    ADD KEY IF NOT EXISTS idx_default_ops_owner_thread (conversation_thread_id);
