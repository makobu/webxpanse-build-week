-- Repair legacy tags schemas where id lost PRIMARY KEY / AUTO_INCREMENT,
-- which causes every new tag and assignment to be stored as id = 0 and breaks edit/delete flows.

SET @tags_has_pk := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tags'
      AND CONSTRAINT_TYPE = 'PRIMARY KEY'
);

SET @tags_id_is_auto := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tags'
      AND COLUMN_NAME = 'id'
      AND EXTRA LIKE '%auto_increment%'
);

SET @tags_needs_repair := IF(@tags_has_pk = 0 OR @tags_id_is_auto = 0, 1, 0);

SET @zero_tag_count := (SELECT COUNT(*) FROM tags WHERE id = 0);
SET @next_tag_id := (SELECT COALESCE(MAX(CASE WHEN id > 0 THEN id END), 0) FROM tags);
SET @single_zero_tag_new_id := IF(@zero_tag_count = 1, @next_tag_id + 1, NULL);

SET @tags_sql := IF(
    @tags_needs_repair = 1 AND @zero_tag_count > 0,
    'UPDATE tags SET id = (@next_tag_id := @next_tag_id + 1) WHERE id = 0 ORDER BY created_at ASC, name ASC',
    'SELECT 1'
);
PREPARE stmt FROM @tags_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tag_assignments_has_pk := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tag_assignments'
      AND CONSTRAINT_TYPE = 'PRIMARY KEY'
);

SET @tag_assignments_id_is_auto := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tag_assignments'
      AND COLUMN_NAME = 'id'
      AND EXTRA LIKE '%auto_increment%'
);

SET @tag_assignments_needs_repair := IF(@tag_assignments_has_pk = 0 OR @tag_assignments_id_is_auto = 0, 1, 0);

SET @zero_assignment_count := (SELECT COUNT(*) FROM tag_assignments WHERE tag_id = 0);

SET @tags_sql := IF(
    @tags_needs_repair = 1 AND @zero_assignment_count > 0 AND @zero_tag_count = 1 AND @single_zero_tag_new_id IS NOT NULL,
    CONCAT('UPDATE tag_assignments SET tag_id = ', @single_zero_tag_new_id, ' WHERE tag_id = 0'),
    'SELECT 1'
);
PREPARE stmt FROM @tags_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tags_sql := IF(
    @tags_needs_repair = 1 AND @zero_assignment_count > 0 AND @zero_tag_count <> 1,
    'DELETE FROM tag_assignments WHERE tag_id = 0',
    'SELECT 1'
);
PREPARE stmt FROM @tags_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @next_assignment_id := (SELECT COALESCE(MAX(CASE WHEN id > 0 THEN id END), 0) FROM tag_assignments);
SET @tags_sql := IF(
    @tag_assignments_needs_repair = 1 AND EXISTS(SELECT 1 FROM tag_assignments WHERE id = 0),
    'UPDATE tag_assignments SET id = (@next_assignment_id := @next_assignment_id + 1) WHERE id = 0 ORDER BY created_at ASC, entity_type ASC, entity_id ASC',
    'SELECT 1'
);
PREPARE stmt FROM @tags_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tags_sql := IF(
    @tags_needs_repair = 1 AND @tags_has_pk = 0,
    'ALTER TABLE tags ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @tags_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tags_sql := IF(
    @tags_needs_repair = 1 AND @tags_id_is_auto = 0,
    'ALTER TABLE tags MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @tags_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tags_sql := IF(
    @tag_assignments_needs_repair = 1 AND @tag_assignments_has_pk = 0,
    'ALTER TABLE tag_assignments ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @tags_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tags_sql := IF(
    @tag_assignments_needs_repair = 1 AND @tag_assignments_id_is_auto = 0,
    'ALTER TABLE tag_assignments MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @tags_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
