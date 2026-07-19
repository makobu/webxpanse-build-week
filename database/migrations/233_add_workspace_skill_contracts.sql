-- Skill-owned guidance contracts and workspace-owned skill definitions.
-- Idempotent so it can run safely in older tenant databases.

SET @owner_workspace_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_definitions'
      AND column_name = 'owner_workspace_id'
);
SET @sql := IF(
    @owner_workspace_exists = 0,
    'ALTER TABLE workspace_skill_definitions ADD COLUMN owner_workspace_id INT NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @definition_source_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_definitions'
      AND column_name = 'definition_source'
);
SET @sql := IF(
    @definition_source_exists = 0,
    'ALTER TABLE workspace_skill_definitions ADD COLUMN definition_source ENUM(''platform'',''custom'',''template_clone'') NOT NULL DEFAULT ''platform'' AFTER skill_key',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @advice_domains_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_definitions'
      AND column_name = 'advice_domains_json'
);
SET @sql := IF(
    @advice_domains_exists = 0,
    'ALTER TABLE workspace_skill_definitions ADD COLUMN advice_domains_json JSON NULL AFTER capabilities_json',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @context_schema_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_definitions'
      AND column_name = 'context_schema_json'
);
SET @sql := IF(
    @context_schema_exists = 0,
    'ALTER TABLE workspace_skill_definitions ADD COLUMN context_schema_json JSON NULL AFTER onboarding_fields_json',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @task_templates_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_definitions'
      AND column_name = 'task_templates_json'
);
SET @sql := IF(
    @task_templates_exists = 0,
    'ALTER TABLE workspace_skill_definitions ADD COLUMN task_templates_json JSON NULL AFTER context_schema_json',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @boundary_policy_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_definitions'
      AND column_name = 'boundary_policy'
);
SET @sql := IF(
    @boundary_policy_exists = 0,
    'ALTER TABLE workspace_skill_definitions ADD COLUMN boundary_policy VARCHAR(40) NOT NULL DEFAULT ''strict'' AFTER task_templates_json',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @routing_examples_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_definitions'
      AND column_name = 'routing_examples_json'
);
SET @sql := IF(
    @routing_examples_exists = 0,
    'ALTER TABLE workspace_skill_definitions ADD COLUMN routing_examples_json JSON NULL AFTER boundary_policy',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @created_by_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_definitions'
      AND column_name = 'created_by_user_id'
);
SET @sql := IF(
    @created_by_exists = 0,
    'ALTER TABLE workspace_skill_definitions ADD COLUMN created_by_user_id INT NULL AFTER permissions_json',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_owner_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workspace_skill_definitions'
      AND index_name = 'idx_workspace_skill_definitions_owner'
);
SET @sql := IF(
    @idx_owner_exists = 0,
    'CREATE INDEX idx_workspace_skill_definitions_owner ON workspace_skill_definitions (owner_workspace_id, definition_source, is_active)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE workspace_skill_definitions
SET definition_source = 'platform',
    boundary_policy = COALESCE(NULLIF(boundary_policy, ''), 'strict')
WHERE owner_workspace_id IS NULL;
