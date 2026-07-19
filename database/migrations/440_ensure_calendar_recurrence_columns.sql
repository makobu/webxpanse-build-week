-- Repair environments where the historical recurring-events migration was marked executed without applying.

SET @has_recurrence_pattern := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'recurrence_pattern'
);
SET @sql := IF(
    @has_recurrence_pattern = 0,
    'ALTER TABLE events ADD COLUMN recurrence_pattern ENUM(''none'',''daily'',''weekly'',''monthly'',''yearly'') DEFAULT ''none'' AFTER event_type',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_recurrence_end_date := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'recurrence_end_date'
);
SET @sql := IF(
    @has_recurrence_end_date = 0,
    'ALTER TABLE events ADD COLUMN recurrence_end_date DATE NULL AFTER recurrence_pattern',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_recurrence_count := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'recurrence_count'
);
SET @sql := IF(
    @has_recurrence_count = 0,
    'ALTER TABLE events ADD COLUMN recurrence_count INT NULL AFTER recurrence_end_date',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_parent_event_id := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND COLUMN_NAME = 'parent_event_id'
);
SET @sql := IF(
    @has_parent_event_id = 0,
    'ALTER TABLE events ADD COLUMN parent_event_id INT NULL AFTER recurrence_count',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'events'
      AND INDEX_NAME = 'idx_recurrence_pattern'
);
SET @sql := IF(
    @idx_exists = 0,
    'CREATE INDEX idx_recurrence_pattern ON events (recurrence_pattern)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
