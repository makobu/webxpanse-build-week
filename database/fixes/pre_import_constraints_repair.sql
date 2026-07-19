-- Pre-import constraints repair for local restores.
-- Purpose: avoid FK errors like:
--   #6125 Missing unique key ... referenced table 'contacts'
--
-- Run this on the target database before importing a dump that adds FKs.

SET FOREIGN_KEY_CHECKS = 0;

-- Contacts
ALTER TABLE contacts ENGINE=InnoDB;
ALTER TABLE contacts MODIFY id INT NOT NULL;
SET @has_contacts_pk := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'contacts'
      AND constraint_type = 'PRIMARY KEY'
);
SET @sql_contacts_pk := IF(@has_contacts_pk = 0, 'ALTER TABLE contacts ADD PRIMARY KEY (id)', 'SELECT 1');
PREPARE stmt FROM @sql_contacts_pk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @contacts_ai := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contacts'
      AND column_name = 'id'
      AND LOWER(COALESCE(extra, '')) LIKE '%auto_increment%'
);
SET @sql_contacts_ai := IF(@contacts_ai = 0, 'ALTER TABLE contacts MODIFY id INT NOT NULL AUTO_INCREMENT', 'SELECT 1');
PREPARE stmt FROM @sql_contacts_ai;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Attribution dependency tables
ALTER TABLE attribution_models ENGINE=InnoDB;
ALTER TABLE deals ENGINE=InnoDB;
ALTER TABLE campaigns ENGINE=InnoDB;
ALTER TABLE touchpoints ENGINE=InnoDB;

ALTER TABLE attribution_models MODIFY id INT NOT NULL;
ALTER TABLE deals MODIFY id INT NOT NULL;
ALTER TABLE campaigns MODIFY id INT NOT NULL;
ALTER TABLE touchpoints MODIFY id INT NOT NULL;

SET @has_attr_models_pk := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'attribution_models'
      AND constraint_type = 'PRIMARY KEY'
);
SET @sql_attr_models_pk := IF(@has_attr_models_pk = 0, 'ALTER TABLE attribution_models ADD PRIMARY KEY (id)', 'SELECT 1');
PREPARE stmt FROM @sql_attr_models_pk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_deals_pk := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'deals'
      AND constraint_type = 'PRIMARY KEY'
);
SET @sql_deals_pk := IF(@has_deals_pk = 0, 'ALTER TABLE deals ADD PRIMARY KEY (id)', 'SELECT 1');
PREPARE stmt FROM @sql_deals_pk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_campaigns_pk := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'campaigns'
      AND constraint_type = 'PRIMARY KEY'
);
SET @sql_campaigns_pk := IF(@has_campaigns_pk = 0, 'ALTER TABLE campaigns ADD PRIMARY KEY (id)', 'SELECT 1');
PREPARE stmt FROM @sql_campaigns_pk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_touchpoints_pk := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'touchpoints'
      AND constraint_type = 'PRIMARY KEY'
);
SET @sql_touchpoints_pk := IF(@has_touchpoints_pk = 0, 'ALTER TABLE touchpoints ADD PRIMARY KEY (id)', 'SELECT 1');
PREPARE stmt FROM @sql_touchpoints_pk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @attr_models_ai := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'attribution_models'
      AND column_name = 'id'
      AND LOWER(COALESCE(extra, '')) LIKE '%auto_increment%'
);
SET @sql_attr_models_ai := IF(@attr_models_ai = 0, 'ALTER TABLE attribution_models MODIFY id INT NOT NULL AUTO_INCREMENT', 'SELECT 1');
PREPARE stmt FROM @sql_attr_models_ai;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @deals_ai := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'deals'
      AND column_name = 'id'
      AND LOWER(COALESCE(extra, '')) LIKE '%auto_increment%'
);
SET @sql_deals_ai := IF(@deals_ai = 0, 'ALTER TABLE deals MODIFY id INT NOT NULL AUTO_INCREMENT', 'SELECT 1');
PREPARE stmt FROM @sql_deals_ai;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @campaigns_ai := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'campaigns'
      AND column_name = 'id'
      AND LOWER(COALESCE(extra, '')) LIKE '%auto_increment%'
);
SET @sql_campaigns_ai := IF(@campaigns_ai = 0, 'ALTER TABLE campaigns MODIFY id INT NOT NULL AUTO_INCREMENT', 'SELECT 1');
PREPARE stmt FROM @sql_campaigns_ai;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @touchpoints_ai := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'touchpoints'
      AND column_name = 'id'
      AND LOWER(COALESCE(extra, '')) LIKE '%auto_increment%'
);
SET @sql_touchpoints_ai := IF(@touchpoints_ai = 0, 'ALTER TABLE touchpoints MODIFY id INT NOT NULL AUTO_INCREMENT', 'SELECT 1');
PREPARE stmt FROM @sql_touchpoints_ai;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

-- Optional verification:
-- SHOW CREATE TABLE contacts;
-- SHOW CREATE TABLE attribution_results;
