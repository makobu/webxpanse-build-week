-- Workspace isolation hardening for profiles, products, targets, reset permissions, and scoped RBAC.

CREATE TABLE IF NOT EXISTS workspace_user_roles (
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    assigned_by INT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (workspace_id, user_id),
    KEY idx_workspace_user_roles_user (user_id),
    KEY idx_workspace_user_roles_role (role_id),
    CONSTRAINT fk_workspace_user_roles_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE company_profile ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE company_profile ADD KEY idx_company_profile_workspace (workspace_id, is_active);

ALTER TABLE products ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE products ADD KEY idx_products_workspace (workspace_id, is_active);

ALTER TABLE targets ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE targets ADD KEY idx_targets_workspace (workspace_id, status);

ALTER TABLE target_reminders ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE target_reminders ADD KEY idx_target_reminders_workspace (workspace_id, target_id);

ALTER TABLE target_advice ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE target_advice ADD KEY idx_target_advice_workspace (workspace_id, target_id);

ALTER TABLE target_milestones ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE target_milestones ADD KEY idx_target_milestones_workspace (workspace_id, target_id);

UPDATE company_profile SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE products SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE targets SET workspace_id = 1 WHERE workspace_id IS NULL;

UPDATE target_reminders tr
JOIN targets t ON t.id = tr.target_id
SET tr.workspace_id = t.workspace_id
WHERE tr.workspace_id IS NULL;

UPDATE target_advice ta
JOIN targets t ON t.id = ta.target_id
SET ta.workspace_id = t.workspace_id
WHERE ta.workspace_id IS NULL;

UPDATE target_milestones tm
JOIN targets t ON t.id = tm.target_id
SET tm.workspace_id = t.workspace_id
WHERE tm.workspace_id IS NULL;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('platform.settings.manage', 'Manage Platform Settings', 'Manage global platform settings and systemwide controls', TRUE),
('platform.users.view', 'View Platform Users', 'View users across all workspaces and platform-only accounts', TRUE),
('platform.system.reset', 'Reset Platform Data', 'Run global platform reset operations', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'superadmin'
  AND p.permission_key IN ('platform.settings.manage', 'platform.users.view', 'platform.system.reset')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO workspace_user_roles (workspace_id, user_id, role_id, assigned_by)
SELECT wm.workspace_id, wm.user_id, r.id, wm.invited_by
FROM workspace_memberships wm
JOIN roles r ON r.slug = wm.role_slug
LEFT JOIN workspace_user_roles wur ON wur.workspace_id = wm.workspace_id AND wur.user_id = wm.user_id
WHERE wm.membership_status = 'active'
  AND wur.user_id IS NULL;
