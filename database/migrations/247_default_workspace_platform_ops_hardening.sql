-- Harden the protected default workspace used as the Platform Ops HQ.
-- This migration is intentionally idempotent.

INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_by)
SELECT
    1,
    '00000000-0000-4000-8000-000000000001',
    'Default Workspace',
    'default',
    'active',
    'inactive',
    (SELECT MIN(id) FROM users)
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM workspaces WHERE id = 1 OR slug = 'default'
);

UPDATE workspaces
SET slug = 'default',
    updated_at = NOW()
WHERE id = 1
  AND slug <> 'default'
  AND NOT EXISTS (
      SELECT 1
      FROM (SELECT id FROM workspaces WHERE slug = 'default' AND id <> 1) existing_default
  );

SET @default_workspace_id := COALESCE(
    (SELECT id FROM workspaces WHERE id = 1 LIMIT 1),
    (SELECT id FROM workspaces WHERE slug = 'default' ORDER BY id ASC LIMIT 1)
);

INSERT INTO workspace_slugs (workspace_id, slug, is_primary)
SELECT @default_workspace_id, 'default', 1
FROM DUAL
WHERE @default_workspace_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    workspace_id = VALUES(workspace_id),
    is_primary = VALUES(is_primary);

INSERT INTO workspace_wallets (workspace_id, currency, token_balance, reserved_tokens, lifetime_credited_tokens, lifetime_debited_tokens, last_activity_at)
SELECT @default_workspace_id, 'KES', 0, 0, 0, 0, NOW()
FROM DUAL
WHERE @default_workspace_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM workspace_wallets WHERE workspace_id = @default_workspace_id
  );

INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
SELECT @default_workspace_id, u.id, 'superadmin', 'active', 1, NOW(), u.id
FROM users u
WHERE @default_workspace_id IS NOT NULL
  AND u.role = 'admin'
  AND NOT EXISTS (
      SELECT 1
      FROM workspace_memberships wm
      WHERE wm.workspace_id = @default_workspace_id
        AND wm.membership_status = 'active'
        AND wm.role_slug = 'superadmin'
  )
ORDER BY u.id ASC
LIMIT 1
ON DUPLICATE KEY UPDATE
    role_slug = VALUES(role_slug),
    membership_status = VALUES(membership_status),
    is_owner = VALUES(is_owner),
    joined_at = COALESCE(workspace_memberships.joined_at, VALUES(joined_at)),
    invited_by = COALESCE(workspace_memberships.invited_by, VALUES(invited_by));

ALTER TABLE products ADD COLUMN IF NOT EXISTS seed_metadata_json JSON NULL AFTER product_demo_video_url;
ALTER TABLE email_templates ADD COLUMN IF NOT EXISTS seed_metadata_json JSON NULL AFTER template_key;
ALTER TABLE workflow_templates ADD COLUMN IF NOT EXISTS seed_metadata_json JSON NULL AFTER template_key;

CREATE TABLE IF NOT EXISTS default_workspace_owner_contacts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    default_workspace_id INT NOT NULL,
    owner_workspace_id INT NOT NULL,
    owner_user_id INT NOT NULL,
    contact_id INT NOT NULL,
    relationship_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    customer_state ENUM('qualified_workspace_lead', 'current_paying_customer', 'ineligible') NOT NULL DEFAULT 'qualified_workspace_lead',
    last_synced_at DATETIME NULL,
    last_synced_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_default_owner_workspace_user (owner_workspace_id, owner_user_id),
    KEY idx_default_owner_contact (contact_id),
    KEY idx_default_owner_user (owner_user_id),
    KEY idx_default_owner_state (default_workspace_id, relationship_status, customer_state),
    CONSTRAINT fk_default_owner_contacts_default_workspace FOREIGN KEY (default_workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_default_owner_contacts_owner_workspace FOREIGN KEY (owner_workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_default_owner_contacts_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_default_owner_contacts_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_default_owner_contacts_synced_by FOREIGN KEY (last_synced_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO default_workspace_owner_contacts (
    default_workspace_id,
    owner_workspace_id,
    owner_user_id,
    contact_id,
    relationship_status,
    customer_state,
    last_synced_at,
    last_synced_by_user_id
)
SELECT
    c.workspace_id,
    CAST(JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.owner_workspace_id')) AS UNSIGNED),
    CAST(JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.owner_user_id')) AS UNSIGNED),
    c.id,
    CASE
        WHEN JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.default_workspace_contact_scope')) = 'inactive_workspace_owner'
            THEN 'inactive'
        ELSE 'active'
    END,
    CASE
        WHEN JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.current_paying_customer')) = 'true'
            THEN 'current_paying_customer'
        WHEN JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.default_workspace_contact_scope')) = 'inactive_workspace_owner'
            THEN 'ineligible'
        ELSE 'qualified_workspace_lead'
    END,
    NOW(),
    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.last_synced_by_user_id')), 'null') AS UNSIGNED)
FROM contacts c
JOIN workspaces owner_workspace
  ON owner_workspace.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.owner_workspace_id')) AS UNSIGNED)
JOIN users owner_user
  ON owner_user.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.owner_user_id')) AS UNSIGNED)
WHERE c.workspace_id = @default_workspace_id
  AND c.metadata_json IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.source')) = 'default_workspace_owner_contact'
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.owner_workspace_id')) AS UNSIGNED) > 0
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.owner_user_id')) AS UNSIGNED) > 0
ON DUPLICATE KEY UPDATE
    contact_id = VALUES(contact_id),
    relationship_status = VALUES(relationship_status),
    customer_state = VALUES(customer_state),
    last_synced_at = VALUES(last_synced_at),
    last_synced_by_user_id = VALUES(last_synced_by_user_id);

CREATE INDEX idx_contacts_workspace_email ON contacts (workspace_id, email);
