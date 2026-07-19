-- Scope legacy email assistant message and digest rows to workspaces.
-- Rows that cannot be confidently mapped remain NULL and are excluded from workspace views.

ALTER TABLE email_assistant_messages
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE email_digest_log
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

UPDATE email_assistant_messages eam
JOIN (
    SELECT
        user_id,
        MIN(workspace_id) AS workspace_id,
        COUNT(DISTINCT workspace_id) AS workspace_count
    FROM workspace_memberships
    WHERE membership_status = 'active'
    GROUP BY user_id
) wm ON wm.user_id = eam.user_id
SET eam.workspace_id = wm.workspace_id
WHERE eam.workspace_id IS NULL
  AND wm.workspace_count = 1;

UPDATE email_digest_log edl
JOIN (
    SELECT
        user_id,
        MIN(workspace_id) AS workspace_id,
        COUNT(DISTINCT workspace_id) AS workspace_count
    FROM workspace_memberships
    WHERE membership_status = 'active'
    GROUP BY user_id
) wm ON wm.user_id = edl.user_id
SET edl.workspace_id = wm.workspace_id
WHERE edl.workspace_id IS NULL
  AND wm.workspace_count = 1;

ALTER TABLE email_assistant_messages
    ADD KEY IF NOT EXISTS idx_email_assistant_messages_workspace_message (workspace_id, message_id, direction),
    ADD KEY IF NOT EXISTS idx_email_assistant_messages_workspace_user_created (workspace_id, user_id, created_at);

ALTER TABLE email_digest_log
    ADD KEY IF NOT EXISTS idx_email_digest_log_workspace_user_sent (workspace_id, user_id, sent_at);
