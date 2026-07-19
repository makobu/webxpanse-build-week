-- Marketing Phase 50: live consent and suppression gates.
-- Store queue- and recipient-level consent evidence without exposing private payloads or secrets.

ALTER TABLE marketing_execution_queue
    ADD COLUMN IF NOT EXISTS live_consent_basis VARCHAR(120) NULL AFTER live_recovery_json,
    ADD COLUMN IF NOT EXISTS live_unsubscribe_evidence_json JSON NULL AFTER live_consent_basis,
    ADD COLUMN IF NOT EXISTS live_suppression_checked_at DATETIME NULL AFTER live_unsubscribe_evidence_json,
    ADD COLUMN IF NOT EXISTS live_suppression_result ENUM('not_checked','clear','blocked','warning') NOT NULL DEFAULT 'not_checked' AFTER live_suppression_checked_at,
    ADD COLUMN IF NOT EXISTS live_suppression_blocked_reason TEXT NULL AFTER live_suppression_result,
    ADD COLUMN IF NOT EXISTS live_consent_evidence_json JSON NULL AFTER live_suppression_blocked_reason,
    ADD INDEX IF NOT EXISTS idx_marketing_execution_queue_live_consent (workspace_id, execution_mode, live_suppression_result, live_suppression_checked_at);

ALTER TABLE marketing_live_email_handoffs
    ADD COLUMN IF NOT EXISTS consent_basis VARCHAR(120) NULL AFTER failure_class,
    ADD COLUMN IF NOT EXISTS unsubscribe_text_evidence TEXT NULL AFTER consent_basis,
    ADD COLUMN IF NOT EXISTS suppression_checked_at DATETIME NULL AFTER unsubscribe_text_evidence,
    ADD COLUMN IF NOT EXISTS suppression_result ENUM('not_checked','clear','blocked','warning') NOT NULL DEFAULT 'not_checked' AFTER suppression_checked_at,
    ADD COLUMN IF NOT EXISTS suppression_blocked_reason TEXT NULL AFTER suppression_result,
    ADD COLUMN IF NOT EXISTS consent_evidence_json JSON NULL AFTER suppression_blocked_reason,
    ADD INDEX IF NOT EXISTS idx_marketing_live_email_handoffs_consent (workspace_id, suppression_result, suppression_checked_at);

ALTER TABLE marketing_live_channel_handoffs
    ADD COLUMN IF NOT EXISTS consent_basis VARCHAR(120) NULL AFTER failure_class,
    ADD COLUMN IF NOT EXISTS suppression_checked_at DATETIME NULL AFTER consent_basis,
    ADD COLUMN IF NOT EXISTS suppression_result ENUM('not_checked','clear','blocked','warning') NOT NULL DEFAULT 'not_checked' AFTER suppression_checked_at,
    ADD COLUMN IF NOT EXISTS suppression_blocked_reason TEXT NULL AFTER suppression_result,
    ADD COLUMN IF NOT EXISTS consent_evidence_json JSON NULL AFTER suppression_blocked_reason,
    ADD COLUMN IF NOT EXISTS whatsapp_template_evidence_json JSON NULL AFTER consent_evidence_json,
    ADD INDEX IF NOT EXISTS idx_marketing_live_channel_handoffs_consent (workspace_id, execution_type, suppression_result, suppression_checked_at);
