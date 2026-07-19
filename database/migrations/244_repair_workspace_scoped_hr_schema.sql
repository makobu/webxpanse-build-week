-- Repair HR workspace scoping for environments where migration 242 was marked executed before schema changes landed.

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE hr_analytics_settings ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'hr_analytics_settings'
      AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE hr_analytics_settings SET workspace_id = 1 WHERE workspace_id IS NULL;

ALTER TABLE hr_analytics_settings MODIFY id INT NOT NULL AUTO_INCREMENT;
ALTER TABLE hr_analytics_settings MODIFY workspace_id INT NOT NULL;

INSERT INTO hr_analytics_settings (
    workspace_id,
    ai_enabled,
    scoring_weights_json,
    thresholds_json,
    department_mappings_json,
    prompt_config_json,
    updated_by
)
SELECT
    w.id,
    src.ai_enabled,
    src.scoring_weights_json,
    src.thresholds_json,
    src.department_mappings_json,
    src.prompt_config_json,
    src.updated_by
FROM workspaces w
CROSS JOIN (
    SELECT ai_enabled, scoring_weights_json, thresholds_json, department_mappings_json, prompt_config_json, updated_by
    FROM hr_analytics_settings
    ORDER BY CASE WHEN workspace_id = 1 THEN 0 ELSE 1 END, id ASC
    LIMIT 1
) src
LEFT JOIN hr_analytics_settings existing_settings ON existing_settings.workspace_id = w.id
WHERE existing_settings.id IS NULL;

DELETE duplicate_settings
FROM hr_analytics_settings duplicate_settings
JOIN hr_analytics_settings keep_settings
  ON keep_settings.workspace_id = duplicate_settings.workspace_id
 AND keep_settings.id < duplicate_settings.id;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE hr_analytics_settings ADD UNIQUE KEY uniq_hr_analytics_settings_workspace (workspace_id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'hr_analytics_settings'
      AND INDEX_NAME = 'uniq_hr_analytics_settings_workspace'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE hr_analytics_settings ADD CONSTRAINT fk_hr_analytics_settings_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE',
        'SELECT 1'
    )
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'hr_analytics_settings'
      AND CONSTRAINT_NAME = 'fk_hr_analytics_settings_workspace'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE departments ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'departments'
      AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE departments SET workspace_id = 1 WHERE workspace_id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE departments DROP INDEX uq_departments_slug',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'departments'
      AND INDEX_NAME = 'uq_departments_slug'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE departments DROP INDEX uq_departments_name',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'departments'
      AND INDEX_NAME = 'uq_departments_name'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE departments MODIFY workspace_id INT NOT NULL;

INSERT INTO departments (workspace_id, name, slug, description, is_active, is_system)
SELECT w.id, seed.name, seed.slug, seed.description, 1, 1
FROM workspaces w
JOIN (
    SELECT 'Administration' AS name, 'admin' AS slug, 'Executive and administrative staff' AS description
    UNION ALL SELECT 'Sales', 'sales', 'Sales and revenue team'
    UNION ALL SELECT 'Marketing', 'marketing', 'Marketing and growth team'
    UNION ALL SELECT 'Operations', 'operations', 'General operations team'
) seed
LEFT JOIN departments existing_department
    ON existing_department.workspace_id = w.id
   AND existing_department.slug = seed.slug
WHERE existing_department.id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE departments ADD UNIQUE KEY uq_departments_workspace_slug (workspace_id, slug)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'departments'
      AND INDEX_NAME = 'uq_departments_workspace_slug'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE departments ADD UNIQUE KEY uq_departments_workspace_name (workspace_id, name)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'departments'
      AND INDEX_NAME = 'uq_departments_workspace_name'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE departments ADD CONSTRAINT fk_departments_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE',
        'SELECT 1'
    )
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'departments'
      AND CONSTRAINT_NAME = 'fk_departments_workspace'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE workspace_memberships ADD COLUMN department_id INT NULL AFTER role_slug',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'workspace_memberships'
      AND COLUMN_NAME = 'department_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE workspace_memberships wm
JOIN users u ON u.id = wm.user_id
JOIN departments legacy_department ON legacy_department.id = u.department_id
JOIN departments scoped_department
    ON scoped_department.workspace_id = wm.workspace_id
   AND scoped_department.slug = legacy_department.slug
SET wm.department_id = scoped_department.id
WHERE wm.department_id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE workspace_memberships ADD KEY idx_workspace_memberships_department (department_id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'workspace_memberships'
      AND INDEX_NAME = 'idx_workspace_memberships_department'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE workspace_memberships ADD CONSTRAINT fk_workspace_memberships_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL',
        'SELECT 1'
    )
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'workspace_memberships'
      AND CONSTRAINT_NAME = 'fk_workspace_memberships_department'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
