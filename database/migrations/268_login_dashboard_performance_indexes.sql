ALTER TABLE notifications
    ADD INDEX idx_notifications_workspace_user_unread_created (workspace_id, user_id, is_read, created_at);

ALTER TABLE notifications
    ADD INDEX idx_notifications_workspace_user_created (workspace_id, user_id, created_at);

ALTER TABLE task_completion_scan_queue
    ADD INDEX idx_task_scan_status_finished (status, finished_at);

ALTER TABLE session_automation_state
    ADD INDEX idx_session_automation_updated (updated_at);
