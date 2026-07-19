-- Store business function accountability selected while creating workspace invites.

CREATE TABLE IF NOT EXISTS workspace_invite_function_assignments (
    id INT NOT NULL AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    invite_id INT NOT NULL,
    function_id INT NOT NULL,
    assignment_type VARCHAR(30) NOT NULL DEFAULT 'contributor',
    importance VARCHAR(30) NOT NULL DEFAULT 'secondary',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workspace_invite_function_assignment (workspace_id, invite_id, function_id),
    KEY idx_workspace_invite_function_assignments_invite (invite_id),
    KEY idx_workspace_invite_function_assignments_function (function_id),
    CONSTRAINT fk_workspace_invite_function_assignments_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_invite_function_assignments_invite
        FOREIGN KEY (invite_id) REFERENCES workspace_invites(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_invite_function_assignments_function
        FOREIGN KEY (function_id) REFERENCES organization_functions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO user_function_assignments (
    workspace_id, user_id, function_id, assignment_type, importance, is_primary
)
SELECT wm.workspace_id,
       wm.user_id,
       f.id,
       'owner',
       CASE WHEN f.slug = 'leadership' THEN 'primary' ELSE 'secondary' END,
       CASE WHEN f.slug = 'leadership' THEN 1 ELSE 0 END
FROM workspace_memberships wm
JOIN organization_functions f
  ON f.workspace_id = wm.workspace_id
WHERE wm.membership_status = 'active'
  AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
  AND f.is_active = 1
  AND COALESCE(NULLIF(f.relevance_status, ''), 'active') = 'active'
ON DUPLICATE KEY UPDATE
    assignment_type = 'owner',
    updated_at = NOW();
