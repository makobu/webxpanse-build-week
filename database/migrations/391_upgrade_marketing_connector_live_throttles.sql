-- Marketing live execution: per-connector live execution throttles.
-- Connector/channel limits complement workspace-wide caps before live dispatch.

ALTER TABLE marketing_channel_connectors
    ADD COLUMN IF NOT EXISTS live_hourly_cap INT NOT NULL DEFAULT 0 AFTER live_adapter_key,
    ADD COLUMN IF NOT EXISTS live_daily_cap INT NOT NULL DEFAULT 0 AFTER live_hourly_cap,
    ADD COLUMN IF NOT EXISTS live_cooldown_seconds INT NOT NULL DEFAULT 0 AFTER live_daily_cap,
    ADD COLUMN IF NOT EXISTS last_live_attempt_at DATETIME NULL AFTER live_cooldown_seconds,
    ADD COLUMN IF NOT EXISTS live_throttle_json JSON NULL AFTER last_live_attempt_at,
    ADD INDEX IF NOT EXISTS idx_marketing_channel_connectors_live_throttle (workspace_id, live_enabled, live_hourly_cap, live_daily_cap, last_live_attempt_at);
