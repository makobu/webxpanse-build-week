-- Workspace-scoped search/list/report indexes.
-- These are intentionally idempotent because older installs may already have
-- some equivalent single-column indexes from the workspace migration series.

SET @db_name = DATABASE();

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'contacts' AND index_name = 'idx_contacts_workspace_created'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_contacts_workspace_created ON contacts (workspace_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'contacts' AND index_name = 'idx_contacts_workspace_stage_created'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_contacts_workspace_stage_created ON contacts (workspace_id, stage, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'contacts' AND index_name = 'idx_contacts_workspace_assigned_created'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_contacts_workspace_assigned_created ON contacts (workspace_id, assigned_to, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'deals' AND index_name = 'idx_deals_workspace_created'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_deals_workspace_created ON deals (workspace_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'deals' AND index_name = 'idx_deals_workspace_stage_created'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_deals_workspace_stage_created ON deals (workspace_id, stage, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'deals' AND index_name = 'idx_deals_workspace_assigned_created'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_deals_workspace_assigned_created ON deals (workspace_id, assigned_to, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'tasks' AND index_name = 'idx_tasks_workspace_status_priority_due'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_tasks_workspace_status_priority_due ON tasks (workspace_id, status, priority, due_date, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'tasks' AND index_name = 'idx_tasks_workspace_assigned_status_due'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_tasks_workspace_assigned_status_due ON tasks (workspace_id, assigned_to, status, due_date, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'activities' AND index_name = 'idx_activities_workspace_created'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_activities_workspace_created ON activities (workspace_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'notes' AND index_name = 'idx_notes_workspace_entity_created'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_notes_workspace_entity_created ON notes (workspace_id, entity_type, entity_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'emails' AND index_name = 'idx_emails_workspace_created'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_emails_workspace_created ON emails (workspace_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'events' AND index_name = 'idx_events_workspace_type_start'
);
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX idx_events_workspace_type_start ON events (workspace_id, event_type, start_time)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
