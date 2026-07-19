-- Add workspace-scoped business functions and multi-function user ownership.

CREATE TABLE IF NOT EXISTS organization_functions (
    id INT NOT NULL AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    description TEXT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'core',
    measurement_strength VARCHAR(20) NOT NULL DEFAULT 'partial',
    is_core TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_organization_functions_workspace_slug (workspace_id, slug),
    KEY idx_organization_functions_workspace_active (workspace_id, is_active),
    CONSTRAINT fk_organization_functions_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_function_assignments (
    id INT NOT NULL AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    function_id INT NOT NULL,
    assignment_type VARCHAR(30) NOT NULL DEFAULT 'owner',
    importance VARCHAR(30) NOT NULL DEFAULT 'secondary',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_function_assignments_user_function (workspace_id, user_id, function_id),
    KEY idx_user_function_assignments_workspace_user (workspace_id, user_id),
    KEY idx_user_function_assignments_function (function_id),
    CONSTRAINT fk_user_function_assignments_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_function_assignments_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_function_assignments_function
        FOREIGN KEY (function_id) REFERENCES organization_functions(id) ON DELETE CASCADE
);

INSERT INTO organization_functions (
    workspace_id, name, slug, description, category, measurement_strength, is_core, is_active
)
SELECT w.id, seed.name, seed.slug, seed.description, seed.category, seed.measurement_strength, 1, 1
FROM workspaces w
JOIN (
    SELECT 'Leadership' AS name, 'leadership' AS slug, 'Founder, executive, ownership, delegation, and operating cadence.' AS description, 'core' AS category, 'partial' AS measurement_strength
    UNION ALL SELECT 'Strategy', 'strategy', 'Strategic priorities, market direction, and strategy-to-execution follow-through.', 'core', 'partial'
    UNION ALL SELECT 'Operations', 'operations', 'Execution rhythm, workload balance, queue health, process reliability, and delivery follow-through.', 'core', 'strong'
    UNION ALL SELECT 'Sales', 'sales', 'Pipeline movement, follow-up discipline, deal ownership, and revenue-linked execution.', 'core', 'strong'
    UNION ALL SELECT 'Marketing', 'marketing', 'Campaign ownership, audience activity, growth work, and campaign-to-pipeline contribution.', 'core', 'strong'
    UNION ALL SELECT 'Finance', 'finance', 'Cash, billing, vendor, financial review, and reporting ownership where tracked.', 'support', 'weak'
    UNION ALL SELECT 'Customer Success', 'customer_success', 'Customer follow-up, retention, support ownership, and relationship continuity.', 'core', 'partial'
    UNION ALL SELECT 'Product', 'product', 'Product direction, roadmap work, feature follow-through, and delivery clarity.', 'core', 'weak'
    UNION ALL SELECT 'Delivery', 'delivery', 'Client/project delivery commitments, fulfilment, and operational handoff quality.', 'core', 'partial'
    UNION ALL SELECT 'People / HR', 'people_hr', 'Hiring, onboarding, people support, reviews, and employee operating health.', 'support', 'partial'
    UNION ALL SELECT 'Administration', 'administration', 'Administrative coordination, workspace hygiene, and support operations.', 'support', 'partial'
) seed
LEFT JOIN organization_functions existing_function
    ON existing_function.workspace_id = w.id
   AND existing_function.slug = seed.slug
WHERE existing_function.id IS NULL;

INSERT IGNORE INTO user_function_assignments (
    workspace_id, user_id, function_id, assignment_type, importance, is_primary
)
SELECT wm.workspace_id, wm.user_id, f.id, 'owner', 'primary', 1
FROM workspace_memberships wm
JOIN organization_functions f
  ON f.workspace_id = wm.workspace_id
 AND f.slug = 'leadership'
WHERE wm.membership_status = 'active'
  AND (wm.is_owner = 1 OR LOWER(wm.role_slug) IN ('owner', 'admin'));

INSERT IGNORE INTO user_function_assignments (
    workspace_id, user_id, function_id, assignment_type, importance, is_primary
)
SELECT wm.workspace_id, wm.user_id, f.id, 'oversight', 'secondary', 0
FROM workspace_memberships wm
JOIN organization_functions f
  ON f.workspace_id = wm.workspace_id
 AND f.slug = 'strategy'
WHERE wm.membership_status = 'active'
  AND (wm.is_owner = 1 OR LOWER(wm.role_slug) IN ('owner', 'admin'));

INSERT IGNORE INTO user_function_assignments (
    workspace_id, user_id, function_id, assignment_type, importance, is_primary
)
SELECT wm.workspace_id, wm.user_id, f.id, 'owner', 'primary', 1
FROM workspace_memberships wm
JOIN organization_functions f
  ON f.workspace_id = wm.workspace_id
 AND f.slug = 'marketing'
WHERE wm.membership_status = 'active'
  AND LOWER(wm.role_slug) LIKE '%marketing%';

INSERT IGNORE INTO user_function_assignments (
    workspace_id, user_id, function_id, assignment_type, importance, is_primary
)
SELECT wm.workspace_id, wm.user_id, f.id, 'owner', 'primary', 1
FROM workspace_memberships wm
JOIN organization_functions f
  ON f.workspace_id = wm.workspace_id
 AND f.slug = 'sales'
WHERE wm.membership_status = 'active'
  AND LOWER(wm.role_slug) LIKE '%sales%';

INSERT IGNORE INTO user_function_assignments (
    workspace_id, user_id, function_id, assignment_type, importance, is_primary
)
SELECT wm.workspace_id, wm.user_id, f.id, 'owner', 'primary', 1
FROM workspace_memberships wm
JOIN organization_functions f
  ON f.workspace_id = wm.workspace_id
 AND f.slug = 'operations'
LEFT JOIN user_function_assignments existing_assignment
  ON existing_assignment.workspace_id = wm.workspace_id
 AND existing_assignment.user_id = wm.user_id
 AND existing_assignment.is_primary = 1
WHERE wm.membership_status = 'active'
  AND existing_assignment.id IS NULL;
