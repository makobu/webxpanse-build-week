-- Idempotent meeting note ingest keys for direct provider payloads and meeting bot handoffs.

SET @has_dedupe_key := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_note_taker_runs'
      AND COLUMN_NAME = 'dedupe_key'
);

SET @sql := IF(
    @has_dedupe_key = 0,
    'ALTER TABLE meeting_note_taker_runs ADD COLUMN dedupe_key VARCHAR(191) NULL AFTER external_meeting_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE meeting_note_taker_runs
SET dedupe_key = CONCAT('meeting_bot_run:', meeting_bot_run_id)
WHERE dedupe_key IS NULL
  AND meeting_bot_run_id IS NOT NULL
  AND meeting_bot_run_id > 0;

UPDATE meeting_note_taker_runs
SET dedupe_key = CONCAT('provider:', LOWER(COALESCE(NULLIF(provider, ''), 'generic')), ':external:', SHA2(external_meeting_id, 256))
WHERE dedupe_key IS NULL
  AND external_meeting_id IS NOT NULL
  AND external_meeting_id <> '';

UPDATE meeting_note_taker_runs duplicate_runs
JOIN (
    SELECT workspace_id, dedupe_key, MIN(id) AS keep_id
    FROM meeting_note_taker_runs
    WHERE dedupe_key IS NOT NULL
      AND workspace_id IS NOT NULL
    GROUP BY workspace_id, dedupe_key
    HAVING COUNT(*) > 1
) grouped_runs
    ON grouped_runs.workspace_id = duplicate_runs.workspace_id
   AND grouped_runs.dedupe_key = duplicate_runs.dedupe_key
   AND duplicate_runs.id <> grouped_runs.keep_id
SET duplicate_runs.dedupe_key = NULL;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_note_taker_runs'
      AND INDEX_NAME = 'uniq_meeting_note_taker_workspace_dedupe'
);

SET @sql := IF(
    @idx_exists = 0,
    'CREATE UNIQUE INDEX uniq_meeting_note_taker_workspace_dedupe ON meeting_note_taker_runs (workspace_id, dedupe_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
