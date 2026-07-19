ALTER TABLE workspace_billing_settings
    ADD COLUMN billing_mode ENUM('central_hub', 'local_provider') NOT NULL DEFAULT 'central_hub' AFTER enabled,
    ADD COLUMN billing_hub_base_url VARCHAR(500) NULL AFTER billing_mode,
    ADD COLUMN billing_workspace_key VARCHAR(191) NULL AFTER billing_hub_base_url,
    ADD COLUMN billing_hub_signing_secret VARCHAR(255) NULL AFTER billing_workspace_key,
    ADD COLUMN billing_hub_checkout_path VARCHAR(255) NULL AFTER billing_hub_signing_secret,
    ADD COLUMN billing_hub_status_path VARCHAR(255) NULL AFTER billing_hub_checkout_path,
    ADD COLUMN hub_synced_amount_due DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER billing_hub_status_path,
    ADD COLUMN hub_synced_currency VARCHAR(10) NULL AFTER hub_synced_amount_due,
    ADD COLUMN hub_synced_due_at DATETIME NULL AFTER hub_synced_currency,
    ADD COLUMN hub_synced_grace_expires_at DATETIME NULL AFTER hub_synced_due_at,
    ADD COLUMN hub_last_synced_at DATETIME NULL AFTER hub_synced_grace_expires_at,
    ADD COLUMN hub_last_payment_reference VARCHAR(100) NULL AFTER hub_last_synced_at;

UPDATE workspace_billing_settings
SET billing_mode = 'local_provider'
WHERE COALESCE(TRIM(paystack_secret_key), '') <> '';
