-- Backfill owner responsibility coverage for Organization Intelligence.
-- Owners carry all active business areas, with Leadership as the primary area.

UPDATE user_function_assignments ufa
JOIN workspace_memberships wm
  ON wm.workspace_id = ufa.workspace_id
 AND wm.user_id = ufa.user_id
LEFT JOIN organization_functions f
  ON f.id = ufa.function_id
 AND f.workspace_id = ufa.workspace_id
SET ufa.importance = CASE WHEN ufa.importance = 'primary' THEN 'secondary' ELSE ufa.importance END,
    ufa.is_primary = 0,
    ufa.updated_at = NOW()
WHERE wm.membership_status = 'active'
  AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
  AND (
        f.id IS NULL
        OR f.slug <> 'leadership'
        OR f.is_active <> 1
        OR COALESCE(NULLIF(f.relevance_status, ''), 'active') <> 'active'
      );

INSERT INTO user_function_assignments (
    workspace_id,
    user_id,
    function_id,
    assignment_type,
    importance,
    is_primary
)
SELECT
    wm.workspace_id,
    wm.user_id,
    f.id,
    'owner',
    CASE WHEN f.slug = 'leadership' THEN 'primary' ELSE 'secondary' END,
    CASE WHEN f.slug = 'leadership' THEN 1 ELSE 0 END
FROM workspace_memberships wm
JOIN organization_functions f
  ON f.workspace_id = wm.workspace_id
 AND f.is_active = 1
 AND COALESCE(NULLIF(f.relevance_status, ''), 'active') = 'active'
WHERE wm.membership_status = 'active'
  AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
ON DUPLICATE KEY UPDATE
    assignment_type = 'owner',
    importance = VALUES(importance),
    is_primary = VALUES(is_primary),
    updated_at = NOW();

UPDATE user_function_assignments ufa
JOIN workspace_memberships wm
  ON wm.workspace_id = ufa.workspace_id
 AND wm.user_id = ufa.user_id
JOIN organization_functions f
  ON f.id = ufa.function_id
 AND f.workspace_id = ufa.workspace_id
SET ufa.assignment_type = 'owner',
    ufa.importance = 'primary',
    ufa.is_primary = 1,
    ufa.updated_at = NOW()
WHERE wm.membership_status = 'active'
  AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
  AND f.slug = 'leadership'
  AND f.is_active = 1
  AND COALESCE(NULLIF(f.relevance_status, ''), 'active') = 'active';

UPDATE user_function_assignments ufa
JOIN (
    SELECT wm.workspace_id, wm.user_id, MIN(f.id) AS fallback_function_id
    FROM workspace_memberships wm
    JOIN organization_functions f
      ON f.workspace_id = wm.workspace_id
     AND f.is_active = 1
     AND COALESCE(NULLIF(f.relevance_status, ''), 'active') = 'active'
    LEFT JOIN user_function_assignments primary_assignment
      ON primary_assignment.workspace_id = wm.workspace_id
     AND primary_assignment.user_id = wm.user_id
     AND primary_assignment.is_primary = 1
    WHERE wm.membership_status = 'active'
      AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
      AND primary_assignment.id IS NULL
    GROUP BY wm.workspace_id, wm.user_id
) fallback
  ON fallback.workspace_id = ufa.workspace_id
 AND fallback.user_id = ufa.user_id
 AND fallback.fallback_function_id = ufa.function_id
SET ufa.assignment_type = 'owner',
    ufa.importance = 'primary',
    ufa.is_primary = 1,
    ufa.updated_at = NOW();
