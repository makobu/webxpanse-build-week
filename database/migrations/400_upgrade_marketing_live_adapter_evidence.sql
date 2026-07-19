-- Marketing live execution: adapter-level evidence hardening.
-- Keeps the existing guarded queue flow intact while adding normalized proof fields
-- for every live adapter attempt.

ALTER TABLE marketing_execution_attempts
    ADD COLUMN IF NOT EXISTS connector_id INT NULL AFTER queue_id,
    ADD COLUMN IF NOT EXISTS adapter_key VARCHAR(120) NULL AFTER attempt_mode,
    ADD COLUMN IF NOT EXISTS provider_reference VARCHAR(255) NULL AFTER adapter_key,
    ADD COLUMN IF NOT EXISTS failure_class VARCHAR(80) NULL AFTER provider_reference,
    ADD COLUMN IF NOT EXISTS duration_ms INT NULL AFTER failure_class,
    ADD COLUMN IF NOT EXISTS request_evidence_json JSON NULL AFTER duration_ms,
    ADD COLUMN IF NOT EXISTS response_evidence_json JSON NULL AFTER request_evidence_json,
    ADD COLUMN IF NOT EXISTS adapter_contract_json JSON NULL AFTER response_evidence_json,
    ADD INDEX IF NOT EXISTS idx_marketing_execution_attempts_adapter (workspace_id, adapter_key, status, created_at),
    ADD INDEX IF NOT EXISTS idx_marketing_execution_attempts_connector (workspace_id, connector_id, created_at),
    ADD INDEX IF NOT EXISTS idx_marketing_execution_attempts_provider_ref (workspace_id, provider_reference);

ALTER TABLE marketing_live_webhook_attempts
    ADD COLUMN IF NOT EXISTS adapter_key VARCHAR(120) NULL AFTER connector_id,
    ADD COLUMN IF NOT EXISTS provider_reference VARCHAR(255) NULL AFTER adapter_key,
    ADD COLUMN IF NOT EXISTS failure_class VARCHAR(80) NULL AFTER provider_reference,
    ADD INDEX IF NOT EXISTS idx_marketing_live_webhook_attempts_adapter (workspace_id, adapter_key, status, created_at),
    ADD INDEX IF NOT EXISTS idx_marketing_live_webhook_attempts_provider_ref (workspace_id, provider_reference);

ALTER TABLE marketing_live_channel_handoffs
    ADD COLUMN IF NOT EXISTS adapter_key VARCHAR(120) NULL AFTER connector_id,
    ADD COLUMN IF NOT EXISTS idempotency_key CHAR(64) NULL AFTER provider_message_id,
    ADD COLUMN IF NOT EXISTS failure_class VARCHAR(80) NULL AFTER idempotency_key,
    ADD INDEX IF NOT EXISTS idx_marketing_live_channel_handoffs_adapter (workspace_id, adapter_key, status, created_at),
    ADD INDEX IF NOT EXISTS idx_marketing_live_channel_handoffs_idempotency (workspace_id, idempotency_key);

ALTER TABLE marketing_live_email_handoffs
    ADD COLUMN IF NOT EXISTS adapter_key VARCHAR(120) NULL AFTER connector_id,
    ADD COLUMN IF NOT EXISTS idempotency_key CHAR(64) NULL AFTER recipient_email,
    ADD COLUMN IF NOT EXISTS provider_reference VARCHAR(255) NULL AFTER idempotency_key,
    ADD COLUMN IF NOT EXISTS failure_class VARCHAR(80) NULL AFTER provider_reference,
    ADD INDEX IF NOT EXISTS idx_marketing_live_email_handoffs_adapter (workspace_id, adapter_key, status, created_at),
    ADD INDEX IF NOT EXISTS idx_marketing_live_email_handoffs_idempotency (workspace_id, idempotency_key);
