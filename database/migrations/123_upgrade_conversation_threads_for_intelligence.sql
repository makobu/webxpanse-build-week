ALTER TABLE communications
    ADD COLUMN IF NOT EXISTS thread_key VARCHAR(191) NULL AFTER contact_id;

UPDATE communications
SET thread_key = CONCAT(channel, ':contact:', contact_id)
WHERE thread_key IS NULL OR thread_key = '';

ALTER TABLE communications
    MODIFY COLUMN thread_key VARCHAR(191) NOT NULL,
    ADD INDEX IF NOT EXISTS idx_thread_key (thread_key),
    ADD INDEX IF NOT EXISTS idx_channel_thread_created (channel, thread_key, created_at);

ALTER TABLE communications
    MODIFY COLUMN thread_key VARCHAR(191)
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci
    NOT NULL;

ALTER TABLE conversation_threads
    ADD COLUMN IF NOT EXISTS thread_key VARCHAR(191) NULL AFTER channel,
    ADD COLUMN IF NOT EXISTS status VARCHAR(32) NOT NULL DEFAULT 'open' AFTER last_message_at,
    ADD COLUMN IF NOT EXISTS current_owner_id INT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS priority VARCHAR(32) NULL AFTER current_owner_id,
    ADD COLUMN IF NOT EXISTS response_due_at DATETIME NULL AFTER priority,
    ADD COLUMN IF NOT EXISTS last_inbound_at DATETIME NULL AFTER response_due_at,
    ADD COLUMN IF NOT EXISTS last_outbound_at DATETIME NULL AFTER last_inbound_at,
    ADD COLUMN IF NOT EXISTS last_channel VARCHAR(32) NULL AFTER last_outbound_at,
    ADD COLUMN IF NOT EXISTS unresolved_item_count INT NOT NULL DEFAULT 0 AFTER last_channel,
    ADD COLUMN IF NOT EXISTS escalation_status VARCHAR(32) NULL AFTER unresolved_item_count,
    ADD COLUMN IF NOT EXISTS resolution_reason VARCHAR(255) NULL AFTER escalation_status,
    ADD COLUMN IF NOT EXISTS metadata_json JSON NULL AFTER resolution_reason;

UPDATE conversation_threads
SET thread_key = CONCAT(channel, ':contact:', contact_id),
    status = CASE
        WHEN is_resolved = 1 THEN 'resolved'
        ELSE 'open'
    END,
    last_inbound_at = COALESCE(last_inbound_at, last_message_at),
    last_channel = COALESCE(last_channel, channel)
WHERE thread_key IS NULL OR thread_key = '';

ALTER TABLE conversation_threads
    DROP INDEX IF EXISTS unique_thread,
    MODIFY COLUMN thread_key VARCHAR(191) NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS unique_thread_key (thread_key),
    ADD INDEX IF NOT EXISTS idx_thread_status_due (status, response_due_at),
    ADD INDEX IF NOT EXISTS idx_thread_owner_status (current_owner_id, status),
    ADD INDEX IF NOT EXISTS idx_thread_priority (priority);
