SET @db_name = DATABASE();

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @db_name
      AND table_name = 'communications'
      AND index_name = 'idx_communications_created_at'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_communications_created_at ON communications (created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @db_name
      AND table_name = 'communications'
      AND index_name = 'idx_communications_read_created'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_communications_read_created ON communications (read_at, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @db_name
      AND table_name = 'communications'
      AND index_name = 'idx_communications_channel_created'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_communications_channel_created ON communications (channel, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @db_name
      AND table_name = 'communications'
      AND index_name = 'idx_communications_contact_created'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_communications_contact_created ON communications (contact_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @triage_priority_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db_name
      AND table_name = 'communications'
      AND column_name = 'triage_priority'
);
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @db_name
      AND table_name = 'communications'
      AND index_name = 'idx_communications_triage_priority_created'
);
SET @sql = IF(@triage_priority_exists > 0 AND @idx_exists = 0, 'CREATE INDEX idx_communications_triage_priority_created ON communications (triage_priority, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @triage_status_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db_name
      AND table_name = 'communications'
      AND column_name = 'triage_status'
);
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @db_name
      AND table_name = 'communications'
      AND index_name = 'idx_communications_triage_status_created'
);
SET @sql = IF(@triage_status_exists > 0 AND @idx_exists = 0, 'CREATE INDEX idx_communications_triage_status_created ON communications (triage_status, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
