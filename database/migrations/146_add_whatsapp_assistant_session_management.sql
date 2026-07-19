CREATE TABLE IF NOT EXISTS whatsapp_assistant_sessions (
    id INT NOT NULL AUTO_INCREMENT,
    authorized_number_id INT NOT NULL,
    user_id INT NOT NULL,
    phone_number VARCHAR(32) NOT NULL,
    session_state VARCHAR(32) NOT NULL DEFAULT 'expired_requires_reopen',
    last_inbound_at DATETIME DEFAULT NULL,
    last_outbound_at DATETIME DEFAULT NULL,
    session_expires_at DATETIME DEFAULT NULL,
    last_keepalive_reminder_at DATETIME DEFAULT NULL,
    last_reopen_template_sent_at DATETIME DEFAULT NULL,
    reopen_required_since DATETIME DEFAULT NULL,
    last_error_message TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_whatsapp_assistant_session_authorized_number (authorized_number_id),
    KEY idx_whatsapp_assistant_sessions_user_id (user_id),
    KEY idx_whatsapp_assistant_sessions_phone_number (phone_number),
    KEY idx_whatsapp_assistant_sessions_state (session_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_assistant_keepalive_log (
    id INT NOT NULL AUTO_INCREMENT,
    authorized_number_id INT NOT NULL,
    user_id INT NOT NULL,
    phone_number VARCHAR(32) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'queued',
    message TEXT DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_whatsapp_assistant_keepalive_authorized_number (authorized_number_id),
    KEY idx_whatsapp_assistant_keepalive_user_id (user_id),
    KEY idx_whatsapp_assistant_keepalive_event_type (event_type),
    KEY idx_whatsapp_assistant_keepalive_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO whatsapp_assistant_sessions (
    authorized_number_id,
    user_id,
    phone_number,
    session_state,
    last_inbound_at,
    created_at,
    updated_at
)
SELECT
    wan.id,
    wan.user_id,
    wan.phone_number,
    CASE
        WHEN wan.last_used_at IS NOT NULL AND wan.last_used_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 'session_open'
        ELSE 'expired_requires_reopen'
    END,
    wan.last_used_at,
    NOW(),
    NOW()
FROM whatsapp_assistant_authorized_numbers wan
LEFT JOIN whatsapp_assistant_sessions was
    ON was.authorized_number_id = wan.id
WHERE was.id IS NULL;
