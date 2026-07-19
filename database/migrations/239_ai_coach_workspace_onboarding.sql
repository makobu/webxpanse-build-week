-- AI Coach workspace-scoped onboarding and context.

SET @db_name = DATABASE();

SET @has_strategy_workspace_id = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'user_strategy_profiles'
      AND COLUMN_NAME = 'workspace_id'
);
SET @sql = IF(
    @has_strategy_workspace_id = 0,
    'ALTER TABLE user_strategy_profiles ADD COLUMN workspace_id INT NOT NULL DEFAULT 0 AFTER id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idea_workspace_id = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'idea_validation_context'
      AND COLUMN_NAME = 'workspace_id'
);
SET @sql = IF(
    @has_idea_workspace_id = 0,
    'ALTER TABLE idea_validation_context ADD COLUMN workspace_id INT NOT NULL DEFAULT 0 AFTER id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_old_strategy_unique = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'user_strategy_profiles'
      AND INDEX_NAME = 'uk_user_strategy_profiles_user_id'
);
SET @sql = IF(
    @has_old_strategy_unique > 0,
    'ALTER TABLE user_strategy_profiles DROP INDEX uk_user_strategy_profiles_user_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_old_idea_unique = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'idea_validation_context'
      AND INDEX_NAME = 'uk_user_id'
);
SET @sql = IF(
    @has_old_idea_unique > 0,
    'ALTER TABLE idea_validation_context DROP INDEX uk_user_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_strategy_workspace_unique = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'user_strategy_profiles'
      AND INDEX_NAME = 'uk_user_strategy_profiles_workspace_user'
);
SET @sql = IF(
    @has_strategy_workspace_unique = 0,
    'ALTER TABLE user_strategy_profiles ADD UNIQUE KEY uk_user_strategy_profiles_workspace_user (workspace_id, user_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_idea_workspace_unique = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'idea_validation_context'
      AND INDEX_NAME = 'uk_idea_validation_workspace_user'
);
SET @sql = IF(
    @has_idea_workspace_unique = 0,
    'ALTER TABLE idea_validation_context ADD UNIQUE KEY uk_idea_validation_workspace_user (workspace_id, user_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ai_coach_user_onboarding (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('in_progress', 'completed') NOT NULL DEFAULT 'in_progress',
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ai_coach_user_onboarding_workspace_user (workspace_id, user_id),
    KEY idx_ai_coach_user_onboarding_status (workspace_id, status),
    KEY idx_ai_coach_user_onboarding_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
