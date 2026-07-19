-- Post-call CRM links. These make worker retries safe.
-- Plain ALTER statements keep this import compatible with live phpMyAdmin users
-- that cannot read information_schema or use MariaDB-only IF NOT EXISTS syntax.
-- The project migration runners tolerate duplicate column/index errors on rerun.

ALTER TABLE voice_calls ADD COLUMN activity_id INT NULL AFTER disposition_notes;
ALTER TABLE voice_calls ADD COLUMN communication_id INT NULL AFTER activity_id;
ALTER TABLE voice_calls ADD KEY idx_voice_call_activity (workspace_id, activity_id);
ALTER TABLE voice_calls ADD KEY idx_voice_call_communication (workspace_id, communication_id);
