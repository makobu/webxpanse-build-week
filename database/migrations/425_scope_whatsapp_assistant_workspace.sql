-- Scope WhatsApp Assistant runtime tables to workspaces and preserve legacy rows.

SET @default_workspace_id := (
    SELECT COALESCE(
        (SELECT id FROM workspaces WHERE slug = 'default' ORDER BY id ASC LIMIT 1),
        (SELECT MIN(id) FROM workspaces)
    )
);

ALTER TABLE whatsapp_assistant_authorized_numbers
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE whatsapp_assistant_messages
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE whatsapp_assistant_digest_log
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS provider_message_ids_json LONGTEXT NULL AFTER error_message,
    ADD COLUMN IF NOT EXISTS message_count INT NOT NULL DEFAULT 0 AFTER provider_message_ids_json,
    ADD COLUMN IF NOT EXISTS sent_message_count INT NOT NULL DEFAULT 0 AFTER message_count;

ALTER TABLE whatsapp_assistant_sessions
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE whatsapp_assistant_keepalive_log
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

UPDATE whatsapp_assistant_authorized_numbers wan
LEFT JOIN (
    SELECT
        user_id,
        MIN(workspace_id) AS workspace_id,
        COUNT(DISTINCT workspace_id) AS workspace_count
    FROM workspace_memberships
    WHERE membership_status = 'active'
    GROUP BY user_id
) wm ON wm.user_id = wan.user_id
SET wan.workspace_id = COALESCE(
    CASE WHEN wm.workspace_count = 1 THEN wm.workspace_id ELSE NULL END,
    @default_workspace_id
)
WHERE wan.workspace_id IS NULL;

UPDATE whatsapp_assistant_messages wam
LEFT JOIN whatsapp_assistant_authorized_numbers wan ON wan.id = wam.authorized_number_id
LEFT JOIN (
    SELECT
        user_id,
        MIN(workspace_id) AS workspace_id,
        COUNT(DISTINCT workspace_id) AS workspace_count
    FROM workspace_memberships
    WHERE membership_status = 'active'
    GROUP BY user_id
) wm ON wm.user_id = wam.user_id
SET wam.workspace_id = COALESCE(
    wan.workspace_id,
    CASE WHEN wm.workspace_count = 1 THEN wm.workspace_id ELSE NULL END,
    @default_workspace_id
)
WHERE wam.workspace_id IS NULL;

UPDATE whatsapp_assistant_digest_log wdl
LEFT JOIN (
    SELECT
        user_id,
        MIN(workspace_id) AS workspace_id,
        COUNT(DISTINCT workspace_id) AS workspace_count
    FROM workspace_memberships
    WHERE membership_status = 'active'
    GROUP BY user_id
) wm ON wm.user_id = wdl.user_id
SET wdl.workspace_id = COALESCE(
    CASE WHEN wm.workspace_count = 1 THEN wm.workspace_id ELSE NULL END,
    @default_workspace_id
)
WHERE wdl.workspace_id IS NULL;

UPDATE whatsapp_assistant_sessions was
LEFT JOIN whatsapp_assistant_authorized_numbers wan ON wan.id = was.authorized_number_id
LEFT JOIN (
    SELECT
        user_id,
        MIN(workspace_id) AS workspace_id,
        COUNT(DISTINCT workspace_id) AS workspace_count
    FROM workspace_memberships
    WHERE membership_status = 'active'
    GROUP BY user_id
) wm ON wm.user_id = was.user_id
SET was.workspace_id = COALESCE(
    wan.workspace_id,
    CASE WHEN wm.workspace_count = 1 THEN wm.workspace_id ELSE NULL END,
    @default_workspace_id
)
WHERE was.workspace_id IS NULL;

UPDATE whatsapp_assistant_keepalive_log wkl
LEFT JOIN whatsapp_assistant_authorized_numbers wan ON wan.id = wkl.authorized_number_id
LEFT JOIN (
    SELECT
        user_id,
        MIN(workspace_id) AS workspace_id,
        COUNT(DISTINCT workspace_id) AS workspace_count
    FROM workspace_memberships
    WHERE membership_status = 'active'
    GROUP BY user_id
) wm ON wm.user_id = wkl.user_id
SET wkl.workspace_id = COALESCE(
    wan.workspace_id,
    CASE WHEN wm.workspace_count = 1 THEN wm.workspace_id ELSE NULL END,
    @default_workspace_id
)
WHERE wkl.workspace_id IS NULL;

SET @old_whatsapp_assistant_phone_unique_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'whatsapp_assistant_authorized_numbers'
      AND index_name = 'uniq_whatsapp_assistant_phone_number'
);
SET @sql := IF(
    @old_whatsapp_assistant_phone_unique_exists > 0,
    'ALTER TABLE whatsapp_assistant_authorized_numbers DROP INDEX uniq_whatsapp_assistant_phone_number',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE whatsapp_assistant_authorized_numbers
    ADD UNIQUE KEY IF NOT EXISTS uniq_whatsapp_assistant_workspace_phone (workspace_id, phone_number),
    ADD KEY IF NOT EXISTS idx_whatsapp_assistant_workspace_user (workspace_id, user_id),
    ADD KEY IF NOT EXISTS idx_whatsapp_assistant_workspace_active_digest (workspace_id, is_active, digest_enabled);

ALTER TABLE whatsapp_assistant_messages
    ADD KEY IF NOT EXISTS idx_whatsapp_assistant_messages_workspace_phone (workspace_id, phone_number),
    ADD KEY IF NOT EXISTS idx_whatsapp_assistant_messages_workspace_created (workspace_id, created_at),
    ADD KEY IF NOT EXISTS idx_whatsapp_assistant_messages_workspace_status (workspace_id, status);

ALTER TABLE whatsapp_assistant_digest_log
    ADD KEY IF NOT EXISTS idx_whatsapp_assistant_digest_workspace_user_sent (workspace_id, user_id, sent_at),
    ADD KEY IF NOT EXISTS idx_whatsapp_assistant_digest_workspace_phone_sent (workspace_id, phone_number, sent_at),
    ADD KEY IF NOT EXISTS idx_whatsapp_assistant_digest_workspace_status (workspace_id, status);

ALTER TABLE whatsapp_assistant_sessions
    ADD KEY IF NOT EXISTS idx_whatsapp_assistant_sessions_workspace_state (workspace_id, session_state),
    ADD KEY IF NOT EXISTS idx_whatsapp_assistant_sessions_workspace_phone (workspace_id, phone_number);

ALTER TABLE whatsapp_assistant_keepalive_log
    ADD KEY IF NOT EXISTS idx_whatsapp_assistant_keepalive_workspace_created (workspace_id, created_at),
    ADD KEY IF NOT EXISTS idx_whatsapp_assistant_keepalive_workspace_event (workspace_id, event_type);
