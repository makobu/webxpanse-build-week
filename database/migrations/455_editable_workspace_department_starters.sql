-- Normalize workspace department starters as editable setup guidance.

UPDATE departments d
LEFT JOIN departments name_conflict
  ON name_conflict.workspace_id = d.workspace_id
 AND name_conflict.name = 'Leadership / Admin'
 AND name_conflict.id <> d.id
SET d.name = 'Leadership / Admin',
    d.description = 'Leadership, ownership, administration, and workspace coordination.'
WHERE d.slug = 'admin'
  AND d.name IN ('Admin', 'Administration')
  AND (
      d.description IS NULL
      OR d.description IN (
          'Administrative and executive leadership department',
          'Executive and administrative staff'
      )
  )
  AND name_conflict.id IS NULL;

UPDATE departments
SET is_system = 0
WHERE slug IN ('admin', 'sales', 'marketing', 'operations', 'finance', 'customer_success');

INSERT INTO departments (workspace_id, name, slug, description, is_active, is_system)
SELECT w.id, seed.name, seed.slug, seed.description, 1, 0
FROM workspaces w
JOIN (
    SELECT 'Leadership / Admin' AS name, 'admin' AS slug, 'Leadership, ownership, administration, and workspace coordination.' AS description
    UNION ALL SELECT 'Sales', 'sales', 'Sales and revenue team.'
    UNION ALL SELECT 'Marketing', 'marketing', 'Marketing and growth team.'
    UNION ALL SELECT 'Operations', 'operations', 'General operations and delivery coordination.'
    UNION ALL SELECT 'Finance', 'finance', 'Cash, billing, vendor, and reporting coordination.'
    UNION ALL SELECT 'Customer Success', 'customer_success', 'Post-sale customer follow-up, retention, support, and relationship continuity.'
) seed
LEFT JOIN departments existing_slug
  ON existing_slug.workspace_id = w.id
 AND existing_slug.slug = seed.slug
LEFT JOIN departments existing_name
  ON existing_name.workspace_id = w.id
 AND existing_name.name = seed.name
WHERE existing_slug.id IS NULL
  AND existing_name.id IS NULL;
