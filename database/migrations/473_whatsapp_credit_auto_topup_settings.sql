ALTER TABLE workspace_whatsapp_credit_wallets
    ADD COLUMN IF NOT EXISTS auto_topup_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER monthly_spend_cap,
    ADD COLUMN IF NOT EXISTS auto_topup_threshold DECIMAL(14,4) NULL AFTER auto_topup_enabled,
    ADD COLUMN IF NOT EXISTS auto_topup_amount DECIMAL(14,4) NULL AFTER auto_topup_threshold,
    ADD COLUMN IF NOT EXISTS auto_topup_payment_method_reference VARCHAR(191) NULL AFTER auto_topup_amount;

ALTER TABLE workspace_whatsapp_integrations
    ADD COLUMN IF NOT EXISTS managed_auto_topup_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER managed_monthly_spend_cap,
    ADD COLUMN IF NOT EXISTS managed_auto_topup_threshold DECIMAL(14,4) NULL AFTER managed_auto_topup_enabled,
    ADD COLUMN IF NOT EXISTS managed_auto_topup_amount DECIMAL(14,4) NULL AFTER managed_auto_topup_threshold;
