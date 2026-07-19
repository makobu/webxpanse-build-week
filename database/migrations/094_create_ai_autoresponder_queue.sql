-- Migration 094: AI Auto-responder queue
-- Async queue for inbound communications to avoid webhook latency

CREATE TABLE IF NOT EXISTS ai_autoresponder_queue (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    communication_id INT NOT NULL,
    contact_id INT NOT NULL,
    channel ENUM('email', 'whatsapp', 'sms', 'web_chat') NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL DEFAULT 'inbound',
    message_text TEXT NOT NULL,
    normalized_payload JSON NOT NULL,
    dedupe_key CHAR(64) NULL,
    status ENUM('pending', 'processing', 'completed', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    error_message TEXT NULL,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ai_autoresponder_dedupe_key (dedupe_key),
    INDEX idx_ai_auto_queue_status_available (status, available_at),
    INDEX idx_ai_auto_queue_contact_created (contact_id, created_at),
    INDEX idx_ai_auto_queue_comm (communication_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
