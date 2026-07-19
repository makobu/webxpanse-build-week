ALTER TABLE task_completion_scan_queue
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS active_dedupe_key VARCHAR(64) NULL AFTER request_source;

SET @default_workspace_id := COALESCE((SELECT MIN(id) FROM workspaces), 1);

UPDATE task_completion_scan_queue q
LEFT JOIN (
    SELECT user_id, MIN(workspace_id) AS workspace_id
    FROM workspace_memberships
    WHERE membership_status = 'active'
    GROUP BY user_id
) wm ON wm.user_id = q.user_id
SET q.workspace_id = COALESCE(NULLIF(q.workspace_id, 0), wm.workspace_id, @default_workspace_id)
WHERE q.workspace_id IS NULL OR q.workspace_id <= 0;

UPDATE task_completion_scan_queue older
JOIN task_completion_scan_queue newer
    ON newer.workspace_id = older.workspace_id
   AND newer.user_id = older.user_id
   AND newer.status IN ('queued', 'running')
   AND older.status IN ('queued', 'running')
   AND newer.id > older.id
SET older.status = 'failed',
    older.finished_at = COALESCE(older.finished_at, NOW()),
    older.last_error = 'Superseded by workspace-scoped active scan.',
    older.active_dedupe_key = NULL,
    older.updated_at = NOW();

UPDATE task_completion_scan_queue
SET active_dedupe_key = CASE
    WHEN status IN ('queued', 'running') THEN CONCAT(workspace_id, ':', user_id)
    ELSE NULL
END;

ALTER TABLE task_completion_scan_queue
    MODIFY COLUMN workspace_id INT NOT NULL,
    ADD UNIQUE KEY IF NOT EXISTS uniq_task_scan_active_workspace_user (active_dedupe_key),
    ADD INDEX IF NOT EXISTS idx_task_scan_workspace_user_status (workspace_id, user_id, status, queued_at),
    ADD INDEX IF NOT EXISTS idx_task_scan_workspace_status_queued (workspace_id, status, queued_at),
    ADD INDEX IF NOT EXISTS idx_task_scan_workspace_finished (workspace_id, user_id, finished_at);
