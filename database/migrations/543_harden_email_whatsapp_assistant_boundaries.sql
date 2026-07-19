-- Close tenant, replay, and permission gaps across WhatsApp and assistant surfaces.

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('whatsapp.messages.view', 'View WhatsApp Messages', 'View workspace WhatsApp message history', FALSE),
('whatsapp.messages.send', 'Send WhatsApp Messages', 'Send WhatsApp messages from workspace channels', TRUE),
('whatsapp.queue.manage', 'Manage WhatsApp Queue', 'Process or cancel pending workspace WhatsApp messages', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('whatsapp.messages.view', 'whatsapp.messages.send', 'whatsapp.queue.manage')
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('whatsapp.messages.view', 'whatsapp.messages.send')
WHERE r.slug IN ('sales', 'marketing', 'expert')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'whatsapp.messages.view'
WHERE r.slug IN ('viewer', 'accountant')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

-- Remove mappings that can never authorize a request and retain stale phone data.
DELETE wan
FROM whatsapp_assistant_authorized_numbers wan
LEFT JOIN workspaces w ON w.id = wan.workspace_id
LEFT JOIN users u ON u.id = wan.user_id
WHERE wan.workspace_id IS NULL OR w.id IS NULL OR u.id IS NULL;

-- Keep the first historical occurrence and clear duplicate provider IDs before
-- enforcing future exactly-once claims. NULL remains valid for local/test rows.
UPDATE whatsapp_assistant_messages duplicate_row
JOIN whatsapp_assistant_messages first_row
  ON first_row.workspace_id = duplicate_row.workspace_id
 AND first_row.direction = duplicate_row.direction
 AND first_row.whatsapp_message_id = duplicate_row.whatsapp_message_id
 AND first_row.id < duplicate_row.id
SET duplicate_row.whatsapp_message_id = NULL
WHERE duplicate_row.whatsapp_message_id IS NOT NULL
  AND duplicate_row.whatsapp_message_id <> '';

UPDATE whatsapp_messages duplicate_row
JOIN whatsapp_messages first_row
  ON first_row.workspace_id = duplicate_row.workspace_id
 AND first_row.direction = duplicate_row.direction
 AND first_row.whatsapp_message_id = duplicate_row.whatsapp_message_id
 AND first_row.id < duplicate_row.id
SET duplicate_row.whatsapp_message_id = NULL
WHERE duplicate_row.whatsapp_message_id IS NOT NULL
  AND duplicate_row.whatsapp_message_id <> '';

ALTER TABLE whatsapp_assistant_messages
    ADD UNIQUE KEY IF NOT EXISTS uq_whatsapp_assistant_provider_message (workspace_id, direction, whatsapp_message_id);

ALTER TABLE whatsapp_messages
    ADD UNIQUE KEY IF NOT EXISTS uq_whatsapp_provider_message (workspace_id, direction, whatsapp_message_id);

SET @wa_authorized_workspace_fk_exists := (
    SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'whatsapp_assistant_authorized_numbers'
      AND constraint_name = 'fk_wa_assistant_authorized_workspace'
);
SET @sql := IF(
    @wa_authorized_workspace_fk_exists = 0,
    'ALTER TABLE whatsapp_assistant_authorized_numbers ADD CONSTRAINT fk_wa_assistant_authorized_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @wa_authorized_user_fk_exists := (
    SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'whatsapp_assistant_authorized_numbers'
      AND constraint_name = 'fk_wa_assistant_authorized_user'
);
SET @sql := IF(
    @wa_authorized_user_fk_exists = 0,
    'ALTER TABLE whatsapp_assistant_authorized_numbers ADD CONSTRAINT fk_wa_assistant_authorized_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
