-- Repair products identity column for environments where migration 049 ran with a broken schema.
-- Supports both the original broken state (`id` exists but is not auto increment)
-- and the intermediate repair state (`_row_id` already exists).

SET @products_table_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
);

SET @products_has_id_column := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND column_name = 'id'
);

SET @products_has_temp_row_id := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND column_name = '_row_id'
);

SET @products_id_is_auto_increment := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND column_name = 'id'
      AND extra LIKE '%auto_increment%'
);

SET @products_has_primary_key := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND constraint_type = 'PRIMARY KEY'
);

SET @sql := IF(
    @products_table_exists > 0
    AND @products_has_temp_row_id = 0
    AND (@products_has_id_column = 0 OR @products_id_is_auto_increment = 0 OR @products_has_primary_key = 0),
    'ALTER TABLE products ADD COLUMN _row_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @products_table_exists > 0
    AND @products_has_temp_row_id = 0
    AND @products_has_id_column > 0
    AND (@products_id_is_auto_increment = 0 OR @products_has_primary_key = 0),
    'ALTER TABLE products DROP COLUMN id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @products_has_temp_row_id := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND column_name = '_row_id'
);

SET @products_has_id_column := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND column_name = 'id'
);

SET @sql := IF(
    @products_table_exists > 0
    AND @products_has_temp_row_id > 0
    AND @products_has_id_column = 0,
    'ALTER TABLE products CHANGE COLUMN _row_id id INT NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_category_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND index_name = 'idx_category'
);

SET @sql := IF(
    @products_table_exists > 0 AND @idx_category_exists = 0,
    'ALTER TABLE products ADD INDEX idx_category (category)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_is_active_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND index_name = 'idx_is_active'
);

SET @sql := IF(
    @products_table_exists > 0 AND @idx_is_active_exists = 0,
    'ALTER TABLE products ADD INDEX idx_is_active (is_active)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_display_order_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND index_name = 'idx_display_order'
);

SET @sql := IF(
    @products_table_exists > 0 AND @idx_display_order_exists = 0,
    'ALTER TABLE products ADD INDEX idx_display_order (display_order)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @products_has_primary_key := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND constraint_type = 'PRIMARY KEY'
);

SET @legacy_row_id_index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND index_name = '_row_id'
);

SET @sql := IF(
    @products_table_exists > 0 AND @products_has_primary_key = 0 AND @legacy_row_id_index_exists > 0,
    'ALTER TABLE products DROP INDEX _row_id, ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @products_has_primary_key := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'products'
      AND constraint_type = 'PRIMARY KEY'
);

SET @sql := IF(
    @products_table_exists > 0 AND @products_has_primary_key = 0,
    'ALTER TABLE products ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
