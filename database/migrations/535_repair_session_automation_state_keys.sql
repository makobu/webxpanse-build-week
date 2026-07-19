-- Repair installations where session_automation_state lost the keys created by
-- migration 125. Without the unique user/feature key, ON DUPLICATE KEY UPDATE
-- inserts a new row on every session automation pass and point lookups scan the
-- entire table.

-- Legacy/corrupted copies can contain repeated zero IDs. Reassign IDs in
-- chronological order so the greatest ID for each user/feature pair represents
-- its newest recorded state.
SET @session_automation_has_primary_key := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'session_automation_state'
      AND CONSTRAINT_TYPE = 'PRIMARY KEY'
);

SET @session_automation_repair_id := 0;

SET @session_automation_repair_sql := IF(
    @session_automation_has_primary_key = 0,
    'UPDATE session_automation_state
     SET id = (@session_automation_repair_id := @session_automation_repair_id + 1)
     ORDER BY updated_at ASC,
              COALESCE(last_attempt_at, created_at) ASC,
              created_at ASC,
              user_id ASC,
              feature_key ASC',
    'SELECT 1'
);
PREPARE stmt FROM @session_automation_repair_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @session_automation_repair_sql := IF(
    @session_automation_has_primary_key = 0,
    'ALTER TABLE session_automation_state ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE stmt FROM @session_automation_repair_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE session_automation_state
    MODIFY COLUMN id BIGINT NOT NULL AUTO_INCREMENT,
    ADD INDEX idx_session_automation_pair_repair (user_id, feature_key, id);

-- Keep the newest row assigned above and remove historical duplicates that
-- should have been updates.
DELETE FROM session_automation_state
WHERE id NOT IN (
    SELECT newest_id
    FROM (
        SELECT MAX(id) AS newest_id
        FROM session_automation_state
        GROUP BY user_id, feature_key
    ) newest_rows
);

ALTER TABLE session_automation_state
    ADD UNIQUE KEY uniq_session_automation_user_feature (user_id, feature_key);

ALTER TABLE session_automation_state
    ADD INDEX idx_session_automation_user_attempt (user_id, last_attempt_at);

ALTER TABLE session_automation_state
    ADD INDEX idx_session_automation_feature_attempt (feature_key, last_attempt_at);

ALTER TABLE session_automation_state
    DROP INDEX idx_session_automation_pair_repair;
