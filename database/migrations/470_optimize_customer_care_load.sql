SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'nurture_enrollments'
      AND INDEX_NAME = 'idx_nurture_enrollments_contact_status_updated'
);

SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX idx_nurture_enrollments_contact_status_updated ON nurture_enrollments (workspace_id, contact_id, status, updated_at, id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
