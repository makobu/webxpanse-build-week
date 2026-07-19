-- Workspace-scope draft templates/reviews, API keys, and webhooks.
-- Orphan fallback: rows without an owning contact/user workspace are assigned to workspace id 1.

INSERT INTO permissions (permission_key, label, description, is_sensitive)
VALUES
    ('settings.integrations', 'Manage Integrations', 'Manage workspace integrations such as API keys and webhooks', TRUE),
    ('settings.api_keys', 'Manage API Keys', 'Create and manage workspace API keys', TRUE),
    ('settings.webhooks', 'Manage Webhooks', 'Create and manage workspace outbound webhooks', TRUE),
    ('drafts.manage', 'Manage Drafts', 'Manage workspace draft templates and draft review queues', FALSE),
    ('reports.nl_generate', 'Generate Natural-Language Reports', 'Ask natural-language questions against permitted workspace report data', FALSE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'settings.integrations',
    'settings.api_keys',
    'settings.webhooks',
    'drafts.manage',
    'reports.nl_generate'
)
WHERE r.slug IN ('admin', 'owner', 'superadmin')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

CREATE TABLE IF NOT EXISTS draft_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL,
    purpose VARCHAR(100) NOT NULL,
    tone VARCHAR(50) DEFAULT 'professional',
    subject VARCHAR(500) NULL,
    body TEXT NOT NULL,
    variables JSON NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_type (type),
    INDEX idx_purpose (purpose),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS draft_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    draft_type VARCHAR(50) NOT NULL,
    contact_id INT NULL,
    subject VARCHAR(500) NULL,
    body TEXT NOT NULL,
    original_body TEXT NULL,
    tone VARCHAR(50) DEFAULT 'professional',
    status VARCHAR(50) DEFAULT 'draft',
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_contact_id (contact_id),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE draft_templates
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE draft_reviews
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE api_keys
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE webhooks
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

UPDATE draft_templates dt
SET dt.workspace_id = COALESCE(
    (
        SELECT wm.workspace_id
        FROM workspace_memberships wm
        WHERE wm.user_id = dt.created_by
          AND wm.membership_status = 'active'
        ORDER BY wm.is_owner DESC, wm.id ASC
        LIMIT 1
    ),
    1
)
WHERE dt.workspace_id IS NULL OR dt.workspace_id <= 0;

UPDATE draft_reviews dr
LEFT JOIN contacts c ON c.id = dr.contact_id
SET dr.workspace_id = COALESCE(
    c.workspace_id,
    (
        SELECT wm.workspace_id
        FROM workspace_memberships wm
        WHERE wm.user_id = dr.created_by
          AND wm.membership_status = 'active'
        ORDER BY wm.is_owner DESC, wm.id ASC
        LIMIT 1
    ),
    1
)
WHERE dr.workspace_id IS NULL OR dr.workspace_id <= 0;

UPDATE api_keys ak
SET ak.workspace_id = COALESCE(
    (
        SELECT wm.workspace_id
        FROM workspace_memberships wm
        WHERE wm.user_id = ak.user_id
          AND wm.membership_status = 'active'
        ORDER BY wm.is_owner DESC, wm.id ASC
        LIMIT 1
    ),
    1
)
WHERE ak.workspace_id IS NULL OR ak.workspace_id <= 0;

UPDATE webhooks w
SET w.workspace_id = COALESCE(
    (
        SELECT wm.workspace_id
        FROM workspace_memberships wm
        WHERE wm.user_id = w.user_id
          AND wm.membership_status = 'active'
        ORDER BY wm.is_owner DESC, wm.id ASC
        LIMIT 1
    ),
    1
)
WHERE w.workspace_id IS NULL OR w.workspace_id <= 0;

ALTER TABLE draft_templates
    MODIFY COLUMN workspace_id INT NOT NULL,
    ADD INDEX IF NOT EXISTS idx_draft_templates_workspace (workspace_id),
    ADD INDEX IF NOT EXISTS idx_draft_templates_workspace_type (workspace_id, type);

ALTER TABLE draft_reviews
    MODIFY COLUMN workspace_id INT NOT NULL,
    ADD INDEX IF NOT EXISTS idx_draft_reviews_workspace (workspace_id),
    ADD INDEX IF NOT EXISTS idx_draft_reviews_workspace_status (workspace_id, status);

ALTER TABLE api_keys
    MODIFY COLUMN workspace_id INT NOT NULL,
    ADD INDEX IF NOT EXISTS idx_api_keys_workspace (workspace_id),
    ADD INDEX IF NOT EXISTS idx_api_keys_workspace_active (workspace_id, is_active);

ALTER TABLE webhooks
    MODIFY COLUMN workspace_id INT NOT NULL,
    ADD INDEX IF NOT EXISTS idx_webhooks_workspace (workspace_id),
    ADD INDEX IF NOT EXISTS idx_webhooks_workspace_active (workspace_id, is_active);

ALTER TABLE draft_templates
    ADD CONSTRAINT fk_draft_templates_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE;

ALTER TABLE draft_reviews
    ADD CONSTRAINT fk_draft_reviews_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE;

ALTER TABLE api_keys
    ADD CONSTRAINT fk_api_keys_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE;

ALTER TABLE webhooks
    ADD CONSTRAINT fk_webhooks_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE;
