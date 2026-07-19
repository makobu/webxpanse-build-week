-- Repair conversation_threads identity column for environments where the table exists
-- but id is not a stable AUTO_INCREMENT primary key.

SET @threads_table_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
);

SET @threads_has_id_column := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND column_name = 'id'
);

SET @threads_has_temp_row_id := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND column_name = '_row_id'
);

SET @threads_id_is_auto_increment := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND column_name = 'id'
      AND extra LIKE '%auto_increment%'
);

SET @threads_has_primary_key := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND constraint_type = 'PRIMARY KEY'
);

SET @sql := IF(
    @threads_table_exists > 0
    AND @threads_has_temp_row_id = 0
    AND (@threads_has_id_column = 0 OR @threads_id_is_auto_increment = 0 OR @threads_has_primary_key = 0),
    'ALTER TABLE conversation_threads ADD COLUMN _row_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @threads_table_exists > 0
    AND @threads_has_temp_row_id = 0
    AND @threads_has_id_column > 0
    AND (@threads_id_is_auto_increment = 0 OR @threads_has_primary_key = 0),
    'ALTER TABLE conversation_threads DROP COLUMN id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @threads_has_temp_row_id := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND column_name = '_row_id'
);

SET @threads_has_id_column := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND column_name = 'id'
);

SET @sql := IF(
    @threads_table_exists > 0
    AND @threads_has_temp_row_id > 0
    AND @threads_has_id_column > 0,
    'ALTER TABLE conversation_threads DROP COLUMN id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @threads_has_id_column := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND column_name = 'id'
);

SET @sql := IF(
    @threads_table_exists > 0
    AND @threads_has_temp_row_id > 0
    AND @threads_has_id_column = 0,
    'ALTER TABLE conversation_threads CHANGE COLUMN _row_id id INT NOT NULL AUTO_INCREMENT FIRST',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_contact_channel_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND index_name = 'idx_contact_channel'
);

SET @sql := IF(
    @threads_table_exists > 0 AND @idx_contact_channel_exists = 0,
    'ALTER TABLE conversation_threads ADD INDEX idx_contact_channel (contact_id, channel)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_status_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND index_name = 'idx_status'
);

SET @sql := IF(
    @threads_table_exists > 0 AND @idx_status_exists = 0,
    'ALTER TABLE conversation_threads ADD INDEX idx_status (status)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @unique_thread_key_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND index_name = 'unique_thread_key'
);

SET @sql := IF(
    @threads_table_exists > 0 AND @unique_thread_key_exists = 0,
    'ALTER TABLE conversation_threads ADD UNIQUE INDEX unique_thread_key (thread_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @threads_has_primary_key := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND constraint_type = 'PRIMARY KEY'
);

SET @legacy_row_id_index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND index_name = '_row_id'
);

SET @sql := IF(
    @threads_table_exists > 0 AND @threads_has_primary_key = 0 AND @legacy_row_id_index_exists > 0,
    'ALTER TABLE conversation_threads DROP INDEX _row_id, ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @threads_has_primary_key := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'conversation_threads'
      AND constraint_type = 'PRIMARY KEY'
);

SET @sql := IF(
    @threads_table_exists > 0 AND @threads_has_primary_key = 0,
    'ALTER TABLE conversation_threads ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
