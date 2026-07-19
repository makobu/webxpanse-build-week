-- Marketing Phase 90: live connector preflight proofs.
-- Preflight remains internal-only: it records readiness evidence without calling external APIs.

ALTER TABLE marketing_channel_connector_test_runs
    MODIFY COLUMN test_mode ENUM('diagnostics','dry_run','capability_check','live_preflight') NOT NULL DEFAULT 'diagnostics';

ALTER TABLE marketing_channel_connectors
    ADD COLUMN IF NOT EXISTS live_preflight_status ENUM('not_checked','passed','blocked','failed') NOT NULL DEFAULT 'not_checked' AFTER last_live_check_at,
    ADD COLUMN IF NOT EXISTS live_preflight_checked_at DATETIME NULL AFTER live_preflight_status,
    ADD COLUMN IF NOT EXISTS live_preflight_json JSON NULL AFTER live_preflight_checked_at,
    ADD INDEX IF NOT EXISTS idx_marketing_channel_connectors_live_preflight (workspace_id, live_enabled, live_preflight_status, last_live_check_at);
