ALTER TABLE workspace_billing_settings
    ADD COLUMN IF NOT EXISTS payment_card_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER mpesa_callback_url,
    ADD COLUMN IF NOT EXISTS payment_mpesa_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER payment_card_enabled,
    ADD COLUMN IF NOT EXISTS payment_bank_transfer_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER payment_mpesa_enabled;
