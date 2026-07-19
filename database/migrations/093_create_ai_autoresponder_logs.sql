-- Migration 093: AI Auto-responder logs
-- Keeps full audit trail for decisions, outputs, and delivery outcomes

CREATE TABLE IF NOT EXISTS ai_autoresponder_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    queue_id BIGINT NULL,
    communication_id INT NULL,
    contact_id INT NOT NULL,
    channel ENUM('email', 'whatsapp', 'sms', 'web_chat') NOT NULL,
    decision ENUM('auto_sent', 'draft', 'blocked', 'skipped', 'failed') NOT NULL,
    status ENUM('pending_review', 'sent', 'failed', 'skipped', 'blocked') NOT NULL DEFAULT 'pending_review',
    confidence DECIMAL(4,3) NULL,
    reason_code VARCHAR(120) NULL,
    reason_notes TEXT NULL,
    reply_subject VARCHAR(500) NULL,
    reply_text TEXT NULL,
    prompt_hash CHAR(64) NULL,
    request_payload JSON NULL,
    response_payload JSON NULL,
    dispatch_payload JSON NULL,
    latency_ms INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ai_auto_logs_contact_created (contact_id, created_at),
    INDEX idx_ai_auto_logs_status_created (status, created_at),
    INDEX idx_ai_auto_logs_comm (communication_id),
    INDEX idx_ai_auto_logs_channel_created (channel, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
