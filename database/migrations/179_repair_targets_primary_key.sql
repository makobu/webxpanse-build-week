-- Repair legacy targets schemas where id lost PRIMARY KEY / AUTO_INCREMENT,
-- which causes every new target to be stored as id = 0 and breaks open/delete flows.

SET @targets_has_pk := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'targets'
      AND CONSTRAINT_TYPE = 'PRIMARY KEY'
);

SET @targets_id_is_auto := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'targets'
      AND COLUMN_NAME = 'id'
      AND EXTRA LIKE '%auto_increment%'
);

SET @targets_needs_repair := IF(@targets_has_pk = 0 OR @targets_id_is_auto = 0, 1, 0);

SET @targets_row_count := (SELECT COUNT(*) FROM targets);
SET @targets_zero_count := (SELECT COUNT(*) FROM targets WHERE id = 0);
SET @targets_single_zero_row := IF(@targets_row_count = 1 AND @targets_zero_count = 1, 1, 0);

SET @targets_sql := IF(
    @targets_needs_repair = 1 AND @targets_single_zero_row = 1,
    'UPDATE targets SET id = 1 WHERE id = 0',
    'SELECT 1'
);
PREPARE stmt FROM @targets_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @target_reminders_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'target_reminders'
);
SET @targets_sql := IF(
    @targets_needs_repair = 1 AND @targets_single_zero_row = 1 AND @target_reminders_exists = 1,
    'UPDATE target_reminders SET target_id = 1 WHERE target_id = 0',
    'SELECT 1'
);
PREPARE stmt FROM @targets_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @target_advice_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'target_advice'
);
SET @targets_sql := IF(
    @targets_needs_repair = 1 AND @targets_single_zero_row = 1 AND @target_advice_exists = 1,
    'UPDATE target_advice SET target_id = 1 WHERE target_id = 0',
    'SELECT 1'
);
PREPARE stmt FROM @targets_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @target_milestones_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'target_milestones'
);
SET @targets_sql := IF(
    @targets_needs_repair = 1 AND @targets_single_zero_row = 1 AND @target_milestones_exists = 1,
    'UPDATE target_milestones SET target_id = 1 WHERE target_id = 0',
    'SELECT 1'
);
PREPARE stmt FROM @targets_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @targets_sql := IF(
    @targets_needs_repair = 1 AND @targets_has_pk = 0,
    'ALTER TABLE targets ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @targets_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @targets_sql := IF(
    @targets_needs_repair = 1 AND @targets_id_is_auto = 0,
    'ALTER TABLE targets MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @targets_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
