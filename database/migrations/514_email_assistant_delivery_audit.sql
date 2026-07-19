-- Add observable, non-blocking audit metadata for Email Assistant digests.

ALTER TABLE email_digest_log
    ADD COLUMN IF NOT EXISTS is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS recipient_email VARCHAR(255) NULL AFTER is_test,
    ADD COLUMN IF NOT EXISTS provider_key VARCHAR(64) NULL AFTER recipient_email,
    ADD COLUMN IF NOT EXISTS delivery_method VARCHAR(64) NULL AFTER provider_key;

ALTER TABLE email_digest_log
    ADD KEY IF NOT EXISTS idx_email_digest_log_workspace_test_sent (workspace_id, is_test, sent_at),
    ADD KEY IF NOT EXISTS idx_email_digest_log_recipient_sent (recipient_email, sent_at);
