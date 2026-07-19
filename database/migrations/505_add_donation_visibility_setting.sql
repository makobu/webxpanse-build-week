ALTER TABLE workspace_billing_settings
    ADD COLUMN IF NOT EXISTS donations_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER payment_bank_transfer_enabled;
