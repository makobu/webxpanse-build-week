-- Migration 192: add workspace ownership to remaining async driver rows and calendar events

SET @email_fetch_log_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
);

SET @email_fetch_log_workspace_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
      AND column_name = 'workspace_id'
);

SET @sql := IF(
    @email_fetch_log_exists > 0 AND @email_fetch_log_workspace_exists = 0,
    'ALTER TABLE email_fetch_log ADD COLUMN workspace_id INT NULL AFTER id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_email_fetch_log_workspace_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log'
      AND index_name = 'idx_email_fetch_log_workspace_status'
);

SET @sql := IF(
    @email_fetch_log_exists > 0 AND @idx_email_fetch_log_workspace_exists = 0,
    'CREATE INDEX idx_email_fetch_log_workspace_status ON email_fetch_log (workspace_id, status, last_fetch_at)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @email_fetch_log_details_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log_details'
);

SET @email_fetch_log_details_workspace_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log_details'
      AND column_name = 'workspace_id'
);

SET @sql := IF(
    @email_fetch_log_details_exists > 0 AND @email_fetch_log_details_workspace_exists = 0,
    'ALTER TABLE email_fetch_log_details ADD COLUMN workspace_id INT NULL AFTER id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_email_fetch_log_details_workspace_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'email_fetch_log_details'
      AND index_name = 'idx_email_fetch_log_details_workspace_outcome'
);

SET @sql := IF(
    @email_fetch_log_details_exists > 0 AND @idx_email_fetch_log_details_workspace_exists = 0,
    'CREATE INDEX idx_email_fetch_log_details_workspace_outcome ON email_fetch_log_details (workspace_id, outcome, created_at)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @events_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'events'
);

SET @events_workspace_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'events'
      AND column_name = 'workspace_id'
);

SET @sql := IF(
    @events_exists > 0 AND @events_workspace_exists = 0,
    'ALTER TABLE events ADD COLUMN workspace_id INT NULL AFTER id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_events_workspace_start_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'events'
      AND index_name = 'idx_events_workspace_start'
);

SET @sql := IF(
    @events_exists > 0 AND @idx_events_workspace_start_exists = 0,
    'CREATE INDEX idx_events_workspace_start ON events (workspace_id, start_time)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_events_workspace_assigned_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'events'
      AND index_name = 'idx_events_workspace_assigned'
);

SET @sql := IF(
    @events_exists > 0 AND @idx_events_workspace_assigned_exists = 0,
    'CREATE INDEX idx_events_workspace_assigned ON events (workspace_id, assigned_to, status)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @events_exists > 0,
    'UPDATE events e
     INNER JOIN contacts c ON c.id = e.contact_id
     SET e.workspace_id = c.workspace_id
     WHERE e.workspace_id IS NULL
       AND c.workspace_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @events_exists > 0,
    'UPDATE events e
     INNER JOIN (
         SELECT wm.user_id, MIN(wm.workspace_id) AS workspace_id
         FROM workspace_memberships wm
         WHERE wm.membership_status = ''active''
         GROUP BY wm.user_id
         HAVING COUNT(*) = 1
     ) single_workspace ON single_workspace.user_id = e.created_by
     SET e.workspace_id = single_workspace.workspace_id
     WHERE e.workspace_id IS NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @events_exists > 0,
    'UPDATE events e
     INNER JOIN (
         SELECT wm.user_id, MIN(wm.workspace_id) AS workspace_id
         FROM workspace_memberships wm
         WHERE wm.membership_status = ''active''
         GROUP BY wm.user_id
         HAVING COUNT(*) = 1
     ) single_workspace ON single_workspace.user_id = e.assigned_to
     SET e.workspace_id = single_workspace.workspace_id
     WHERE e.workspace_id IS NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @email_fetch_log_exists > 0,
    'UPDATE email_fetch_log efl
     INNER JOIN (
         SELECT wm.user_id, MIN(wm.workspace_id) AS workspace_id
         FROM workspace_memberships wm
         WHERE wm.membership_status = ''active''
         GROUP BY wm.user_id
         HAVING COUNT(*) = 1
     ) single_workspace ON single_workspace.user_id = efl.initiated_by_user_id
     SET efl.workspace_id = single_workspace.workspace_id
     WHERE efl.workspace_id IS NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @email_fetch_log_details_exists > 0,
    'UPDATE email_fetch_log_details d
     LEFT JOIN email_fetch_log l ON l.id = d.fetch_log_id
     LEFT JOIN communications comm ON comm.id = d.communication_id
     LEFT JOIN contacts c ON c.id = d.contact_id
     SET d.workspace_id = COALESCE(l.workspace_id, comm.workspace_id, c.workspace_id)
     WHERE d.workspace_id IS NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
