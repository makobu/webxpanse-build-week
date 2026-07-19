-- Repair legacy form_submissions schemas where id lost PRIMARY KEY / AUTO_INCREMENT.
-- Stable submission IDs are required by Forms, tracking touchpoints, and activity links.

SET @next_form_submission_id := (
    SELECT COALESCE(MAX(CASE WHEN id > 0 THEN id END), 0)
    FROM form_submissions
);

UPDATE form_submissions
SET id = (@next_form_submission_id := @next_form_submission_id + 1)
WHERE id <= 0
ORDER BY submitted_at ASC, visitor_id ASC, form_id ASC;

SET @form_submissions_has_pk := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'form_submissions'
      AND CONSTRAINT_TYPE = 'PRIMARY KEY'
);

SET @form_submissions_sql := IF(
    @form_submissions_has_pk = 0,
    'ALTER TABLE form_submissions ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @form_submissions_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @form_submissions_id_is_auto := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'form_submissions'
      AND COLUMN_NAME = 'id'
      AND EXTRA LIKE '%auto_increment%'
);

SET @form_submissions_sql := IF(
    @form_submissions_id_is_auto = 0,
    'ALTER TABLE form_submissions MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @form_submissions_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
