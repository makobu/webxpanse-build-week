-- Repair legacy custom_fields schemas where id lost PRIMARY KEY / AUTO_INCREMENT,
-- which causes every custom field to be stored as id = 0 and breaks edit/delete flows.
-- Also restore the expected composite primary key on contact_custom_data so updates are stable.

SET @zero_custom_field_count := (SELECT COUNT(*) FROM custom_fields WHERE id = 0);
SET @next_custom_field_id := (SELECT COALESCE(MAX(CASE WHEN id > 0 THEN id END), 0) FROM custom_fields);
SET @single_zero_custom_field_new_id := IF(@zero_custom_field_count = 1, @next_custom_field_id + 1, NULL);

UPDATE custom_fields
SET id = (@next_custom_field_id := @next_custom_field_id + 1)
WHERE id = 0
ORDER BY created_at ASC, field_name ASC;

UPDATE contact_custom_data
SET field_id = @single_zero_custom_field_new_id
WHERE field_id = 0
  AND @zero_custom_field_count = 1
  AND @single_zero_custom_field_new_id IS NOT NULL;

DELETE FROM contact_custom_data
WHERE field_id = 0
  AND @zero_custom_field_count <> 1;

DELETE FROM contact_custom_data
WHERE field_id NOT IN (SELECT id FROM custom_fields)
   OR contact_id NOT IN (SELECT id FROM contacts);

DROP TEMPORARY TABLE IF EXISTS contact_custom_data_repaired;
CREATE TEMPORARY TABLE contact_custom_data_repaired AS
SELECT
    contact_id,
    field_id,
    SUBSTRING_INDEX(
        GROUP_CONCAT(COALESCE(field_value, '') ORDER BY updated_at DESC, created_at DESC SEPARATOR '\n--crm-split--\n'),
        '\n--crm-split--\n',
        1
    ) AS field_value,
    MIN(created_at) AS created_at,
    MAX(updated_at) AS updated_at
FROM contact_custom_data
GROUP BY contact_id, field_id;

DELETE FROM contact_custom_data;

INSERT INTO contact_custom_data (contact_id, field_id, field_value, created_at, updated_at)
SELECT contact_id, field_id, field_value, created_at, updated_at
FROM contact_custom_data_repaired;

DROP TEMPORARY TABLE IF EXISTS contact_custom_data_repaired;

SET @custom_fields_has_pk := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'custom_fields'
      AND CONSTRAINT_TYPE = 'PRIMARY KEY'
);

SET @custom_fields_id_is_auto := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'custom_fields'
      AND COLUMN_NAME = 'id'
      AND EXTRA LIKE '%auto_increment%'
);

SET @custom_fields_sql := IF(
    @custom_fields_has_pk = 0,
    'ALTER TABLE custom_fields ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @custom_fields_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @custom_fields_sql := IF(
    @custom_fields_id_is_auto = 0,
    'ALTER TABLE custom_fields MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @custom_fields_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @contact_custom_data_has_pk := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'contact_custom_data'
      AND CONSTRAINT_TYPE = 'PRIMARY KEY'
);

SET @custom_fields_sql := IF(
    @contact_custom_data_has_pk = 0,
    'ALTER TABLE contact_custom_data ADD PRIMARY KEY (contact_id, field_id)',
    'SELECT 1'
);
PREPARE stmt FROM @custom_fields_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @contact_custom_data_has_contact_idx := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'contact_custom_data'
      AND INDEX_NAME = 'idx_contact_id'
);

SET @custom_fields_sql := IF(
    @contact_custom_data_has_contact_idx = 0,
    'ALTER TABLE contact_custom_data ADD INDEX idx_contact_id (contact_id)',
    'SELECT 1'
);
PREPARE stmt FROM @custom_fields_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @contact_custom_data_has_field_idx := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'contact_custom_data'
      AND INDEX_NAME = 'idx_field_id'
);

SET @custom_fields_sql := IF(
    @contact_custom_data_has_field_idx = 0,
    'ALTER TABLE contact_custom_data ADD INDEX idx_field_id (field_id)',
    'SELECT 1'
);
PREPARE stmt FROM @custom_fields_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
