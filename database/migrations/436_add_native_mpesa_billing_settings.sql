ALTER TABLE workspace_billing_settings
    ADD COLUMN IF NOT EXISTS mpesa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER paystack_webhook_url,
    ADD COLUMN IF NOT EXISTS mpesa_environment ENUM('sandbox', 'live') NOT NULL DEFAULT 'sandbox' AFTER mpesa_enabled,
    ADD COLUMN IF NOT EXISTS mpesa_consumer_key VARCHAR(255) NULL AFTER mpesa_environment,
    ADD COLUMN IF NOT EXISTS mpesa_consumer_secret VARCHAR(255) NULL AFTER mpesa_consumer_key,
    ADD COLUMN IF NOT EXISTS mpesa_shortcode VARCHAR(64) NULL AFTER mpesa_consumer_secret,
    ADD COLUMN IF NOT EXISTS mpesa_passkey VARCHAR(255) NULL AFTER mpesa_shortcode,
    ADD COLUMN IF NOT EXISTS mpesa_callback_url VARCHAR(500) NULL AFTER mpesa_passkey;
