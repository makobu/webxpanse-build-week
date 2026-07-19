-- Repair report table constraints after legacy imports that left the tables
-- without primary keys or report execution foreign keys.

DELETE re
FROM report_executions re
LEFT JOIN reports r ON r.id = re.report_id
WHERE r.id IS NULL;

UPDATE report_executions re
LEFT JOIN users u ON u.id = re.executed_by
SET re.executed_by = NULL
WHERE re.executed_by IS NOT NULL
  AND u.id IS NULL;

SET @reports_pk_exists := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'reports'
      AND constraint_type = 'PRIMARY KEY'
);
SET @sql := IF(
    @reports_pk_exists = 0,
    'ALTER TABLE reports MODIFY id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @report_executions_pk_exists := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND constraint_type = 'PRIMARY KEY'
);
SET @sql := IF(
    @report_executions_pk_exists = 0,
    'ALTER TABLE report_executions MODIFY id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE report_executions MODIFY executed_by INT NULL;

SET @idx_reports_created_by_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'reports'
      AND index_name = 'idx_reports_created_by'
);
SET @sql := IF(
    @idx_reports_created_by_exists = 0,
    'CREATE INDEX idx_reports_created_by ON reports (created_by)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_reports_report_type_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'reports'
      AND index_name = 'idx_reports_report_type'
);
SET @sql := IF(
    @idx_reports_report_type_exists = 0,
    'CREATE INDEX idx_reports_report_type ON reports (report_type)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_reports_is_public_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'reports'
      AND index_name = 'idx_reports_is_public'
);
SET @sql := IF(
    @idx_reports_is_public_exists = 0,
    'CREATE INDEX idx_reports_is_public ON reports (is_public)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_report_executions_report_id_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND index_name = 'idx_report_executions_report_id'
);
SET @sql := IF(
    @idx_report_executions_report_id_exists = 0,
    'CREATE INDEX idx_report_executions_report_id ON report_executions (report_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_report_executions_executed_by_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND index_name = 'idx_report_executions_executed_by'
);
SET @sql := IF(
    @idx_report_executions_executed_by_exists = 0,
    'CREATE INDEX idx_report_executions_executed_by ON report_executions (executed_by)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_report_executions_executed_at_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND index_name = 'idx_report_executions_executed_at'
);
SET @sql := IF(
    @idx_report_executions_executed_at_exists = 0,
    'CREATE INDEX idx_report_executions_executed_at ON report_executions (executed_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_reports_created_by_exists := (
    SELECT COUNT(*)
    FROM information_schema.key_column_usage
    WHERE table_schema = DATABASE()
      AND table_name = 'reports'
      AND column_name = 'created_by'
      AND referenced_table_name = 'users'
      AND referenced_column_name = 'id'
);
SET @sql := IF(
    @fk_reports_created_by_exists = 0,
    'ALTER TABLE reports ADD CONSTRAINT fk_reports_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_report_executions_report_exists := (
    SELECT COUNT(*)
    FROM information_schema.key_column_usage
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND column_name = 'report_id'
      AND referenced_table_name = 'reports'
      AND referenced_column_name = 'id'
);
SET @sql := IF(
    @fk_report_executions_report_exists = 0,
    'ALTER TABLE report_executions ADD CONSTRAINT fk_report_executions_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_report_executions_user_exists := (
    SELECT COUNT(*)
    FROM information_schema.key_column_usage
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND column_name = 'executed_by'
      AND referenced_table_name = 'users'
      AND referenced_column_name = 'id'
);
SET @sql := IF(
    @fk_report_executions_user_exists = 0,
    'ALTER TABLE report_executions ADD CONSTRAINT fk_report_executions_user FOREIGN KEY (executed_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
