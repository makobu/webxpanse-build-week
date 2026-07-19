-- Repair notifications identity column for environments where migration 029 ran with a broken schema.
-- This backfills stable IDs and restores AUTO_INCREMENT + PRIMARY KEY on notifications.id.

SET @notifications_table_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
);

SET @notifications_has_id_column := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND column_name = 'id'
);

SET @notifications_has_temp_row_id := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND column_name = '_row_id'
);

SET @notifications_id_is_auto_increment := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND column_name = 'id'
      AND extra LIKE '%auto_increment%'
);

SET @notifications_has_primary_key := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND constraint_type = 'PRIMARY KEY'
);

SET @sql := IF(
    @notifications_table_exists > 0
    AND @notifications_has_temp_row_id = 0
    AND (@notifications_has_id_column = 0 OR @notifications_id_is_auto_increment = 0 OR @notifications_has_primary_key = 0),
    'ALTER TABLE notifications ADD COLUMN _row_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @notifications_table_exists > 0
    AND @notifications_has_temp_row_id = 0
    AND @notifications_has_id_column > 0
    AND (@notifications_id_is_auto_increment = 0 OR @notifications_has_primary_key = 0),
    'ALTER TABLE notifications DROP COLUMN id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @notifications_has_temp_row_id := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND column_name = '_row_id'
);

SET @notifications_has_id_column := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND column_name = 'id'
);

SET @sql := IF(
    @notifications_table_exists > 0
    AND @notifications_has_temp_row_id > 0
    AND @notifications_has_id_column = 0,
    'ALTER TABLE notifications CHANGE COLUMN _row_id id INT NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_user_id_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND index_name = 'idx_user_id'
);

SET @sql := IF(
    @notifications_table_exists > 0 AND @idx_user_id_exists = 0,
    'ALTER TABLE notifications ADD INDEX idx_user_id (user_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_is_read_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND index_name = 'idx_is_read'
);

SET @sql := IF(
    @notifications_table_exists > 0 AND @idx_is_read_exists = 0,
    'ALTER TABLE notifications ADD INDEX idx_is_read (is_read)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_created_at_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND index_name = 'idx_created_at'
);

SET @sql := IF(
    @notifications_table_exists > 0 AND @idx_created_at_exists = 0,
    'ALTER TABLE notifications ADD INDEX idx_created_at (created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_user_unread_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND index_name = 'idx_user_unread'
);

SET @sql := IF(
    @notifications_table_exists > 0 AND @idx_user_unread_exists = 0,
    'ALTER TABLE notifications ADD INDEX idx_user_unread (user_id, is_read, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @notifications_has_primary_key := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND constraint_type = 'PRIMARY KEY'
);

SET @legacy_row_id_index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND index_name = '_row_id'
);

SET @sql := IF(
    @notifications_table_exists > 0 AND @notifications_has_primary_key = 0 AND @legacy_row_id_index_exists > 0,
    'ALTER TABLE notifications DROP INDEX _row_id, ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @notifications_has_primary_key := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'notifications'
      AND constraint_type = 'PRIMARY KEY'
);

SET @sql := IF(
    @notifications_table_exists > 0 AND @notifications_has_primary_key = 0,
    'ALTER TABLE notifications ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
