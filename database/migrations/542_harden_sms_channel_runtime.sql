-- Restore drifted SMS tables and add the runtime controls required for safe
-- multi-workspace dispatch, webhook idempotency, and durable queue claims.

CREATE TABLE IF NOT EXISTS sms_messages (
    id INT NOT NULL AUTO_INCREMENT,
    workspace_id INT NULL,
    uuid VARCHAR(36) NOT NULL,
    contact_id INT NULL,
    user_id INT NULL,
    campaign_id INT NULL,
    to_number VARCHAR(20) NOT NULL,
    from_number VARCHAR(20) NOT NULL,
    message_body TEXT NOT NULL,
    message_type ENUM('text', 'media') NOT NULL DEFAULT 'text',
    media_url VARCHAR(500) NULL,
    status ENUM('pending', 'queued', 'sent', 'delivered', 'failed', 'undelivered') NOT NULL DEFAULT 'pending',
    direction ENUM('inbound', 'outbound') NOT NULL DEFAULT 'outbound',
    provider VARCHAR(50) NOT NULL DEFAULT 'twilio',
    provider_message_id VARCHAR(255) NULL,
    idempotency_key CHAR(64) NULL,
    consent_confirmed_at DATETIME NULL,
    consent_source VARCHAR(120) NULL,
    error_message TEXT NULL,
    cost DECIMAL(10, 4) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sms_messages_uuid (uuid),
    UNIQUE KEY uq_sms_messages_provider (workspace_id, provider_message_id),
    UNIQUE KEY uq_sms_messages_idempotency (workspace_id, idempotency_key),
    KEY idx_sms_messages_workspace_status (workspace_id, status, created_at),
    KEY idx_sms_messages_contact (contact_id),
    KEY idx_sms_messages_user (user_id),
    KEY idx_sms_messages_campaign (campaign_id),
    KEY idx_sms_messages_to_number (to_number),
    KEY idx_sms_messages_created_at (created_at),
    CONSTRAINT fk_sms_messages_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_sms_messages_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_sms_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_sms_messages_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sms_messages
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS campaign_id INT NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS idempotency_key CHAR(64) NULL AFTER provider_message_id,
    ADD COLUMN IF NOT EXISTS consent_confirmed_at DATETIME NULL AFTER idempotency_key,
    ADD COLUMN IF NOT EXISTS consent_source VARCHAR(120) NULL AFTER consent_confirmed_at,
    ADD INDEX IF NOT EXISTS idx_sms_messages_workspace_status (workspace_id, status, created_at),
    ADD INDEX IF NOT EXISTS idx_sms_messages_campaign (campaign_id);

UPDATE sms_messages sm
INNER JOIN contacts c ON c.id = sm.contact_id
SET sm.workspace_id = c.workspace_id
WHERE sm.workspace_id IS NULL
  AND c.workspace_id IS NOT NULL;

-- Preserve the oldest provider record before adding the database-level webhook
-- idempotency guarantee.
UPDATE sms_messages duplicate_row
INNER JOIN sms_messages keeper
    ON keeper.workspace_id = duplicate_row.workspace_id
   AND keeper.provider_message_id = duplicate_row.provider_message_id
   AND keeper.id < duplicate_row.id
SET duplicate_row.provider_message_id = NULL
WHERE duplicate_row.workspace_id IS NOT NULL
  AND duplicate_row.provider_message_id IS NOT NULL;

SET @sms_provider_unique_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'sms_messages'
          AND index_name = 'uq_sms_messages_provider'
    ),
    'ALTER TABLE sms_messages ADD UNIQUE INDEX uq_sms_messages_provider (workspace_id, provider_message_id)',
    'SELECT 1'
);
PREPARE sms_provider_unique_stmt FROM @sms_provider_unique_sql;
EXECUTE sms_provider_unique_stmt;
DEALLOCATE PREPARE sms_provider_unique_stmt;

SET @sms_idempotency_unique_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'sms_messages'
          AND index_name = 'uq_sms_messages_idempotency'
    ),
    'ALTER TABLE sms_messages ADD UNIQUE INDEX uq_sms_messages_idempotency (workspace_id, idempotency_key)',
    'SELECT 1'
);
PREPARE sms_idempotency_unique_stmt FROM @sms_idempotency_unique_sql;
EXECUTE sms_idempotency_unique_stmt;
DEALLOCATE PREPARE sms_idempotency_unique_stmt;

CREATE TABLE IF NOT EXISTS sms_queue (
    id INT NOT NULL AUTO_INCREMENT,
    message_id INT NOT NULL,
    workspace_id INT NULL,
    priority INT NOT NULL DEFAULT 0,
    scheduled_at TIMESTAMP NULL,
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    last_attempt_at TIMESTAMP NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    claim_token CHAR(36) NULL,
    claimed_at DATETIME NULL,
    lease_expires_at DATETIME NULL,
    worker_id VARCHAR(191) NULL,
    error_message VARCHAR(500) NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sms_queue_message (message_id),
    KEY idx_sms_queue_workspace_status (workspace_id, status, scheduled_at),
    KEY idx_sms_queue_claimable (status, lease_expires_at, scheduled_at, priority, id),
    CONSTRAINT fk_sms_queue_message FOREIGN KEY (message_id) REFERENCES sms_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_sms_queue_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sms_queue
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER message_id,
    ADD COLUMN IF NOT EXISTS claim_token CHAR(36) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS claimed_at DATETIME NULL AFTER claim_token,
    ADD COLUMN IF NOT EXISTS lease_expires_at DATETIME NULL AFTER claimed_at,
    ADD COLUMN IF NOT EXISTS worker_id VARCHAR(191) NULL AFTER lease_expires_at,
    ADD COLUMN IF NOT EXISTS error_message VARCHAR(500) NULL AFTER worker_id,
    ADD COLUMN IF NOT EXISTS processed_at DATETIME NULL AFTER error_message,
    ADD INDEX IF NOT EXISTS idx_sms_queue_workspace_status (workspace_id, status, scheduled_at),
    ADD INDEX IF NOT EXISTS idx_sms_queue_claimable (status, lease_expires_at, scheduled_at, priority, id);

UPDATE sms_queue q
INNER JOIN sms_messages sm ON sm.id = q.message_id
SET q.workspace_id = sm.workspace_id
WHERE q.workspace_id IS NULL
  AND sm.workspace_id IS NOT NULL;

DELETE duplicate_queue
FROM sms_queue duplicate_queue
INNER JOIN sms_queue keeper
    ON keeper.message_id = duplicate_queue.message_id
   AND keeper.id < duplicate_queue.id;

SET @sms_queue_unique_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'sms_queue'
          AND index_name = 'uq_sms_queue_message'
    ),
    'ALTER TABLE sms_queue ADD UNIQUE INDEX uq_sms_queue_message (message_id)',
    'SELECT 1'
);
PREPARE sms_queue_unique_stmt FROM @sms_queue_unique_sql;
EXECUTE sms_queue_unique_stmt;
DEALLOCATE PREPARE sms_queue_unique_stmt;

CREATE TABLE IF NOT EXISTS workspace_sms_rate_buckets (
    workspace_id INT NOT NULL,
    bucket_type ENUM('minute', 'hour', 'day') NOT NULL,
    bucket_start DATETIME NOT NULL,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (workspace_id, bucket_type, bucket_start),
    KEY idx_workspace_sms_rate_cleanup (bucket_start),
    CONSTRAINT fk_workspace_sms_rate_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
    ('sms.send', 'Send SMS', 'Send individual or automated SMS messages', TRUE),
    ('sms.bulk_send', 'Send Bulk SMS', 'Queue SMS messages to multiple contacts', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'sms.send'
WHERE r.slug IN ('superadmin', 'admin', 'owner', 'sales', 'marketing')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'sms.bulk_send'
WHERE r.slug IN ('superadmin', 'admin', 'owner', 'marketing')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
