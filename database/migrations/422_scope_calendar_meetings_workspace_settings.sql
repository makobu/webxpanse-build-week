-- Workspace-scope Calendar & Meetings settings, secrets, and run history.

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE meeting_bot_config ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_bot_config'
      AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE meeting_note_taker_config ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_note_taker_config'
      AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE meeting_bot_runs ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_bot_runs'
      AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE meeting_note_taker_runs ADD COLUMN workspace_id INT NULL AFTER id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_note_taker_runs'
      AND COLUMN_NAME = 'workspace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO meeting_bot_config (
    workspace_id, enabled, provider, bot_display_name, join_policy, recording_mode, transcript_required,
    auto_apply_mode, consent_notice, zoom_account_id, zoom_client_id, zoom_client_secret,
    webhook_secret, scheduling_secret, config_json, updated_by
)
SELECT
    i.workspace_id,
    src.enabled,
    src.provider,
    src.bot_display_name,
    src.join_policy,
    src.recording_mode,
    src.transcript_required,
    src.auto_apply_mode,
    src.consent_notice,
    src.zoom_account_id,
    src.zoom_client_id,
    src.zoom_client_secret,
    COALESCE(NULLIF(src.webhook_secret, ''), SHA2(CONCAT(UUID(), '-', RAND(), '-bot'), 256)),
    COALESCE(NULLIF(src.scheduling_secret, ''), SHA2(CONCAT(UUID(), '-', RAND(), '-schedule'), 256)),
    src.config_json,
    src.updated_by
FROM workspace_skill_installs i
CROSS JOIN (
    SELECT *
    FROM meeting_bot_config
    ORDER BY CASE WHEN workspace_id IS NULL OR id = 1 THEN 0 ELSE 1 END, id ASC
    LIMIT 1
) src
LEFT JOIN meeting_bot_config existing_config ON existing_config.workspace_id = i.workspace_id
WHERE i.skill_key = 'calendar_meetings'
  AND i.status = 'installed'
  AND i.uninstalled_at IS NULL
  AND existing_config.id IS NULL;

INSERT INTO meeting_bot_config (
    workspace_id, enabled, provider, bot_display_name, join_policy, recording_mode, transcript_required,
    auto_apply_mode, consent_notice, webhook_secret, scheduling_secret, config_json, updated_by
)
SELECT
    i.workspace_id,
    0,
    'zoom',
    NULL,
    'manual_invite_only',
    'provider_native',
    1,
    'auto_safe',
    'This meeting may be joined and transcribed by your workspace meeting assistant.',
    SHA2(CONCAT(UUID(), '-', RAND(), '-bot'), 256),
    SHA2(CONCAT(UUID(), '-', RAND(), '-schedule'), 256),
    JSON_OBJECT('schema_version', 1),
    NULL
FROM workspace_skill_installs i
LEFT JOIN meeting_bot_config existing_config ON existing_config.workspace_id = i.workspace_id
WHERE i.skill_key = 'calendar_meetings'
  AND i.status = 'installed'
  AND i.uninstalled_at IS NULL
  AND existing_config.id IS NULL;

INSERT INTO meeting_note_taker_config (
    workspace_id, enabled, auto_apply_mode, contact_updates_additive_only, task_auto_create_enabled,
    deal_stage_auto_move_enabled, deal_stage_min_confidence, contact_update_min_confidence,
    ingest_secret, config_json, updated_by
)
SELECT
    i.workspace_id,
    src.enabled,
    src.auto_apply_mode,
    src.contact_updates_additive_only,
    src.task_auto_create_enabled,
    src.deal_stage_auto_move_enabled,
    src.deal_stage_min_confidence,
    src.contact_update_min_confidence,
    COALESCE(NULLIF(src.ingest_secret, ''), SHA2(CONCAT(UUID(), '-', RAND(), '-notes'), 256)),
    src.config_json,
    src.updated_by
FROM workspace_skill_installs i
CROSS JOIN (
    SELECT *
    FROM meeting_note_taker_config
    ORDER BY CASE WHEN workspace_id IS NULL OR id = 1 THEN 0 ELSE 1 END, id ASC
    LIMIT 1
) src
LEFT JOIN meeting_note_taker_config existing_config ON existing_config.workspace_id = i.workspace_id
WHERE i.skill_key = 'calendar_meetings'
  AND i.status = 'installed'
  AND i.uninstalled_at IS NULL
  AND existing_config.id IS NULL;

INSERT INTO meeting_note_taker_config (
    workspace_id, enabled, auto_apply_mode, contact_updates_additive_only, task_auto_create_enabled,
    deal_stage_auto_move_enabled, deal_stage_min_confidence, contact_update_min_confidence,
    ingest_secret, config_json, updated_by
)
SELECT
    i.workspace_id,
    0,
    'full_auto',
    1,
    1,
    1,
    0.90,
    0.75,
    SHA2(CONCAT(UUID(), '-', RAND(), '-notes'), 256),
    JSON_OBJECT(
        'allowed_contact_fields', JSON_ARRAY('job_title', 'location', 'company_website', 'linkedin_url', 'twitter_url', 'timezone'),
        'max_context_entries', 10,
        'schema_version', 1
    ),
    NULL
FROM workspace_skill_installs i
LEFT JOIN meeting_note_taker_config existing_config ON existing_config.workspace_id = i.workspace_id
WHERE i.skill_key = 'calendar_meetings'
  AND i.status = 'installed'
  AND i.uninstalled_at IS NULL
  AND existing_config.id IS NULL;

DELETE duplicate_config
FROM meeting_bot_config duplicate_config
JOIN meeting_bot_config keep_config
  ON keep_config.workspace_id = duplicate_config.workspace_id
 AND keep_config.id < duplicate_config.id
WHERE duplicate_config.workspace_id IS NOT NULL;

DELETE duplicate_config
FROM meeting_note_taker_config duplicate_config
JOIN meeting_note_taker_config keep_config
  ON keep_config.workspace_id = duplicate_config.workspace_id
 AND keep_config.id < duplicate_config.id
WHERE duplicate_config.workspace_id IS NOT NULL;

DELETE FROM meeting_bot_config WHERE workspace_id IS NULL OR workspace_id <= 0;
DELETE FROM meeting_note_taker_config WHERE workspace_id IS NULL OR workspace_id <= 0;

ALTER TABLE meeting_bot_config MODIFY workspace_id INT NOT NULL;
ALTER TABLE meeting_note_taker_config MODIFY workspace_id INT NOT NULL;

UPDATE meeting_bot_runs r
JOIN events e ON e.id = r.event_id AND e.workspace_id IS NOT NULL
SET r.workspace_id = e.workspace_id
WHERE r.workspace_id IS NULL;

UPDATE meeting_bot_runs r
JOIN (
    SELECT user_id, MIN(workspace_id) AS workspace_id
    FROM workspace_memberships
    WHERE membership_status = 'active'
    GROUP BY user_id
    HAVING COUNT(DISTINCT workspace_id) = 1
) single_workspace ON single_workspace.user_id = r.created_by
SET r.workspace_id = single_workspace.workspace_id
WHERE r.workspace_id IS NULL;

UPDATE meeting_note_taker_runs n
JOIN meeting_bot_runs r ON r.id = n.meeting_bot_run_id AND r.workspace_id IS NOT NULL
SET n.workspace_id = r.workspace_id
WHERE n.workspace_id IS NULL;

UPDATE meeting_note_taker_runs n
JOIN contacts c ON c.id = n.matched_contact_id AND c.workspace_id IS NOT NULL
SET n.workspace_id = c.workspace_id
WHERE n.workspace_id IS NULL;

UPDATE meeting_note_taker_runs n
JOIN deals d ON d.id = n.matched_deal_id AND d.workspace_id IS NOT NULL
SET n.workspace_id = d.workspace_id
WHERE n.workspace_id IS NULL;

UPDATE meeting_note_taker_runs n
JOIN (
    SELECT user_id, MIN(workspace_id) AS workspace_id
    FROM workspace_memberships
    WHERE membership_status = 'active'
    GROUP BY user_id
    HAVING COUNT(DISTINCT workspace_id) = 1
) single_workspace ON single_workspace.user_id = n.created_by
SET n.workspace_id = single_workspace.workspace_id
WHERE n.workspace_id IS NULL;

UPDATE meeting_bot_runs r
JOIN meeting_note_taker_runs n ON n.id = r.meeting_note_taker_run_id AND n.workspace_id IS NOT NULL
SET r.workspace_id = n.workspace_id
WHERE r.workspace_id IS NULL;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_bot_config'
      AND INDEX_NAME = 'uniq_meeting_bot_config_workspace'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE meeting_bot_config ADD UNIQUE KEY uniq_meeting_bot_config_workspace (workspace_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_note_taker_config'
      AND INDEX_NAME = 'uniq_meeting_note_taker_config_workspace'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE meeting_note_taker_config ADD UNIQUE KEY uniq_meeting_note_taker_config_workspace (workspace_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_bot_runs'
      AND INDEX_NAME = 'idx_meeting_bot_runs_workspace_status'
);
SET @sql := IF(
    @idx_exists = 0,
    'CREATE INDEX idx_meeting_bot_runs_workspace_status ON meeting_bot_runs (workspace_id, status, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_bot_runs'
      AND INDEX_NAME = 'idx_meeting_bot_runs_workspace_meeting'
);
SET @sql := IF(
    @idx_exists = 0,
    'CREATE INDEX idx_meeting_bot_runs_workspace_meeting ON meeting_bot_runs (workspace_id, provider, external_meeting_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_note_taker_runs'
      AND INDEX_NAME = 'idx_meeting_note_taker_workspace_created'
);
SET @sql := IF(
    @idx_exists = 0,
    'CREATE INDEX idx_meeting_note_taker_workspace_created ON meeting_note_taker_runs (workspace_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_bot_config'
      AND CONSTRAINT_NAME = 'fk_meeting_bot_config_workspace'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE meeting_bot_config ADD CONSTRAINT fk_meeting_bot_config_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_note_taker_config'
      AND CONSTRAINT_NAME = 'fk_meeting_note_taker_config_workspace'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE meeting_note_taker_config ADD CONSTRAINT fk_meeting_note_taker_config_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_bot_runs'
      AND CONSTRAINT_NAME = 'fk_meeting_bot_runs_workspace'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE meeting_bot_runs ADD CONSTRAINT fk_meeting_bot_runs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_note_taker_runs'
      AND CONSTRAINT_NAME = 'fk_meeting_note_taker_runs_workspace'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE meeting_note_taker_runs ADD CONSTRAINT fk_meeting_note_taker_runs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
