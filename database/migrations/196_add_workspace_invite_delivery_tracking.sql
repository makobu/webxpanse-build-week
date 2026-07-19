ALTER TABLE workspace_invites
    ADD COLUMN delivery_status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending' AFTER accepted_at;

ALTER TABLE workspace_invites
    ADD COLUMN delivery_error VARCHAR(255) NULL AFTER delivery_status;

ALTER TABLE workspace_invites
    ADD COLUMN last_delivery_attempt_at DATETIME NULL AFTER delivery_error;

ALTER TABLE workspace_invites
    ADD COLUMN delivery_attempt_count INT NOT NULL DEFAULT 0 AFTER last_delivery_attempt_at;

ALTER TABLE workspace_invites
    ADD KEY idx_workspace_invites_delivery (workspace_id, invite_status, delivery_status);
