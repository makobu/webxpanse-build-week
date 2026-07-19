-- Add durable queue claims so abandoned jobs can be recovered and a second
-- worker cannot acknowledge work owned by a different process.

ALTER TABLE email_queue
    ADD COLUMN claim_token CHAR(36) NULL AFTER status;

ALTER TABLE email_queue
    ADD COLUMN claimed_at DATETIME NULL AFTER claim_token;

ALTER TABLE email_queue
    ADD COLUMN lease_expires_at DATETIME NULL AFTER claimed_at;

ALTER TABLE email_queue
    ADD COLUMN worker_id VARCHAR(191) NULL AFTER lease_expires_at;

ALTER TABLE email_queue
    ADD INDEX idx_email_queue_claimable (status, lease_expires_at, scheduled_at, priority, id);

CREATE TABLE IF NOT EXISTS queue_worker_heartbeats (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_name VARCHAR(80) NOT NULL,
    worker_id VARCHAR(191) NOT NULL,
    hostname VARCHAR(191) NULL,
    process_id INT NULL,
    status ENUM('running', 'stopped', 'failed') NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL,
    heartbeat_at DATETIME NOT NULL,
    stopped_at DATETIME NULL,
    metadata_json LONGTEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_queue_worker_identity (worker_name, worker_id),
    KEY idx_queue_worker_health (worker_name, status, heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
