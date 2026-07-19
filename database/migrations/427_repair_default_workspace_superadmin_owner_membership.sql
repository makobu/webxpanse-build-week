-- Keep the protected default workspace usable for platform operations.
-- Global Super Admin accounts must also be owner-style members of the
-- default workspace so setup gates that depend on workspace owners can run.

SET @default_workspace_id := COALESCE(
    (SELECT id FROM workspaces WHERE id = 1 LIMIT 1),
    (SELECT id FROM workspaces WHERE slug = 'default' ORDER BY id ASC LIMIT 1)
);

SET @superadmin_role_id := (
    SELECT id
    FROM roles
    WHERE slug = 'superadmin'
      AND is_active = 1
    LIMIT 1
);

SET @default_superadmin_user_id := (
    SELECT u.id
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles gr ON gr.id = ur.role_id
    WHERE u.role = 'admin'
       OR gr.slug = 'superadmin'
    ORDER BY CASE WHEN gr.slug = 'superadmin' THEN 0 ELSE 1 END, u.id ASC
    LIMIT 1
);

INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
SELECT @default_workspace_id, @default_superadmin_user_id, 'superadmin', 'active', 1, NOW(), @default_superadmin_user_id
FROM DUAL
WHERE @default_workspace_id IS NOT NULL
  AND @default_superadmin_user_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM workspace_memberships wm
      WHERE wm.workspace_id = @default_workspace_id
        AND wm.user_id = @default_superadmin_user_id
  )
ON DUPLICATE KEY UPDATE
    role_slug = VALUES(role_slug),
    membership_status = VALUES(membership_status),
    is_owner = VALUES(is_owner),
    joined_at = COALESCE(workspace_memberships.joined_at, VALUES(joined_at)),
    invited_by = COALESCE(workspace_memberships.invited_by, VALUES(invited_by)),
    updated_at = NOW();

UPDATE workspace_memberships wm
JOIN users u ON u.id = wm.user_id
LEFT JOIN user_roles ur ON ur.user_id = u.id
LEFT JOIN roles gr ON gr.id = ur.role_id
SET wm.role_slug = 'superadmin',
    wm.membership_status = 'active',
    wm.is_owner = 1,
    wm.updated_at = NOW()
WHERE wm.workspace_id = @default_workspace_id
  AND (u.role = 'admin' OR gr.slug = 'superadmin')
  AND (
      wm.role_slug <> 'superadmin'
      OR wm.membership_status <> 'active'
      OR wm.is_owner <> 1
  );

INSERT INTO workspace_user_roles (workspace_id, user_id, role_id, assigned_by)
SELECT wm.workspace_id, wm.user_id, @superadmin_role_id, wm.user_id
FROM workspace_memberships wm
JOIN users u ON u.id = wm.user_id
LEFT JOIN user_roles ur ON ur.user_id = u.id
LEFT JOIN roles gr ON gr.id = ur.role_id
WHERE wm.workspace_id = @default_workspace_id
  AND wm.membership_status = 'active'
  AND wm.is_owner = 1
  AND @superadmin_role_id IS NOT NULL
  AND (u.role = 'admin' OR gr.slug = 'superadmin')
ON DUPLICATE KEY UPDATE
    role_id = VALUES(role_id),
    assigned_by = VALUES(assigned_by),
    updated_at = NOW();
