-- Finish workspace scoping for legacy tenant-owned configuration tables.

SET @db_name = DATABASE();

-- Forms.
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE forms ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forms' AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE forms SET workspace_id = 1 WHERE workspace_id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE forms ADD INDEX idx_forms_workspace_id (workspace_id, id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'forms' AND INDEX_NAME = 'idx_forms_workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Tags and assignments.
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tags ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tags' AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE tags SET workspace_id = 1 WHERE workspace_id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE tags DROP INDEX name',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tags' AND INDEX_NAME = 'name' AND NON_UNIQUE = 0
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tags ADD UNIQUE KEY uq_tags_workspace_name (workspace_id, name)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tags' AND INDEX_NAME = 'uq_tags_workspace_name'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tags ADD INDEX idx_tags_workspace_id (workspace_id, id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tags' AND INDEX_NAME = 'idx_tags_workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tag_assignments ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tag_assignments' AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE tag_assignments ta
JOIN tags t ON t.id = ta.tag_id
SET ta.workspace_id = t.workspace_id
WHERE ta.workspace_id IS NULL;

UPDATE tag_assignments SET workspace_id = 1 WHERE workspace_id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tag_assignments ADD INDEX idx_tag_assignments_workspace_entity (workspace_id, entity_type, entity_id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'tag_assignments' AND INDEX_NAME = 'idx_tag_assignments_workspace_entity'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Campaign children.
UPDATE campaigns SET workspace_id = 1 WHERE workspace_id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE campaigns ADD INDEX idx_campaigns_workspace_id (workspace_id, id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'campaigns' AND INDEX_NAME = 'idx_campaigns_workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE campaign_steps ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'campaign_steps' AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE campaign_steps cs
JOIN campaigns c ON c.id = cs.campaign_id
SET cs.workspace_id = c.workspace_id
WHERE cs.workspace_id IS NULL;

UPDATE campaign_steps SET workspace_id = 1 WHERE workspace_id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE campaign_steps ADD INDEX idx_campaign_steps_workspace_campaign (workspace_id, campaign_id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'campaign_steps' AND INDEX_NAME = 'idx_campaign_steps_workspace_campaign'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE campaign_audience_snapshots ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'campaign_audience_snapshots' AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE campaign_audience_snapshots cas
JOIN campaigns c ON c.id = cas.campaign_id
SET cas.workspace_id = c.workspace_id
WHERE cas.workspace_id IS NULL;

UPDATE campaign_audience_snapshots SET workspace_id = 1 WHERE workspace_id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE campaign_audience_snapshots ADD INDEX idx_campaign_snapshots_workspace_campaign (workspace_id, campaign_id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'campaign_audience_snapshots' AND INDEX_NAME = 'idx_campaign_snapshots_workspace_campaign'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @campaign_rate_limits_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'campaign_rate_limits'
);

SET @sql = (
    SELECT IF(
        @campaign_rate_limits_exists > 0 AND COUNT(*) = 0,
        'ALTER TABLE campaign_rate_limits ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'campaign_rate_limits' AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @campaign_rate_limits_exists > 0,
    'UPDATE campaign_rate_limits crl JOIN campaigns c ON c.id = crl.campaign_id SET crl.workspace_id = c.workspace_id WHERE crl.workspace_id IS NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @campaign_rate_limits_exists > 0,
    'UPDATE campaign_rate_limits SET workspace_id = 1 WHERE workspace_id IS NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        @campaign_rate_limits_exists > 0 AND COUNT(*) = 0,
        'ALTER TABLE campaign_rate_limits ADD INDEX idx_campaign_rate_limits_workspace_campaign (workspace_id, campaign_id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'campaign_rate_limits' AND INDEX_NAME = 'idx_campaign_rate_limits_workspace_campaign'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Custom fields and data.
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE custom_fields ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'custom_fields' AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE custom_fields SET workspace_id = 1 WHERE workspace_id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE custom_fields ADD INDEX idx_custom_fields_workspace_module (workspace_id, module, display_order)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'custom_fields' AND INDEX_NAME = 'idx_custom_fields_workspace_module'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE contact_custom_data ADD COLUMN workspace_id INT NULL FIRST',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'contact_custom_data' AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE contact_custom_data ccd
JOIN contacts c ON c.id = ccd.contact_id
SET ccd.workspace_id = c.workspace_id
WHERE ccd.workspace_id IS NULL;

UPDATE contact_custom_data SET workspace_id = 1 WHERE workspace_id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE contact_custom_data ADD INDEX idx_contact_custom_data_workspace_contact (workspace_id, contact_id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'contact_custom_data' AND INDEX_NAME = 'idx_contact_custom_data_workspace_contact'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Document categories.
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE document_categories ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'document_categories' AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE document_categories SET workspace_id = 1 WHERE workspace_id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE document_categories DROP INDEX unique_name',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'document_categories' AND INDEX_NAME = 'unique_name' AND NON_UNIQUE = 0
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE document_categories ADD UNIQUE KEY uq_document_categories_workspace_name (workspace_id, name)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'document_categories' AND INDEX_NAME = 'uq_document_categories_workspace_name'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Email templates: library/system templates may stay global, user-owned templates are scoped.
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE email_templates ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'email_templates' AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE email_templates
SET workspace_id = 1
WHERE workspace_id IS NULL
  AND COALESCE(is_library, 0) = 0;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE email_templates ADD INDEX idx_email_templates_workspace_slug (workspace_id, slug, is_active)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'email_templates' AND INDEX_NAME = 'idx_email_templates_workspace_slug'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Products.
UPDATE products SET workspace_id = 1 WHERE workspace_id IS NULL;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE products ADD INDEX idx_products_workspace_id (workspace_id, id)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
