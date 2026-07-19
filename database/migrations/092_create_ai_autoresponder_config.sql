-- Migration 092: AI Auto-responder Configuration
-- Stores singleton settings for multi-channel AI auto replies

CREATE TABLE IF NOT EXISTS ai_autoresponder_config (
    id TINYINT PRIMARY KEY DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    mode ENUM('off', 'draft_only', 'hybrid', 'full_auto') NOT NULL DEFAULT 'draft_only',
    default_confidence_threshold DECIMAL(4,3) NOT NULL DEFAULT 0.850,
    config_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_autoresponder_config (id, enabled, mode, default_confidence_threshold, config_json)
SELECT 1, 0, 'draft_only', 0.850, JSON_OBJECT(
    'channels', JSON_OBJECT(
        'email', JSON_OBJECT('enabled', true, 'confidence_threshold', 0.88, 'max_chars', 4000),
        'whatsapp', JSON_OBJECT('enabled', true, 'confidence_threshold', 0.90, 'max_chars', 700),
        'sms', JSON_OBJECT('enabled', true, 'confidence_threshold', 0.92, 'max_chars', 320)
    ),
    'quiet_hours', JSON_OBJECT(
        'enabled', false,
        'start', '20:00',
        'end', '08:00',
        'timezone', 'UTC'
    ),
    'safety', JSON_OBJECT(
        'escalation_keywords', JSON_ARRAY('refund', 'lawyer', 'legal', 'complaint', 'angry'),
        'opt_out_keywords', JSON_ARRAY('stop', 'unsubscribe', 'cancel'),
        'max_auto_replies_per_contact_per_day', 5,
        'forbid_hallucinations', true,
        'require_human_for_sensitive_intents', true
    ),
    'updated_by', 'migration_092'
)
WHERE NOT EXISTS (SELECT 1 FROM ai_autoresponder_config WHERE id = 1);
