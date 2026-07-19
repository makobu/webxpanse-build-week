ALTER TABLE billing_checkout_sessions
    ADD COLUMN IF NOT EXISTS payment_mode ENUM('card', 'mpesa', 'bank_transfer') NOT NULL DEFAULT 'card' AFTER provider,
    ADD COLUMN IF NOT EXISTS flow_type ENUM('redirect', 'offline_charge') NOT NULL DEFAULT 'redirect' AFTER payment_mode,
    ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(32) NULL AFTER callback_url,
    ADD COLUMN IF NOT EXISTS display_text VARCHAR(255) NULL AFTER customer_phone,
    ADD COLUMN IF NOT EXISTS instructions_json JSON NULL AFTER display_text,
    ADD KEY IF NOT EXISTS idx_billing_checkout_mode_status (workspace_id, payment_mode, status);

ALTER TABLE billing_transactions
    ADD COLUMN IF NOT EXISTS payment_mode ENUM('card', 'mpesa', 'bank_transfer') NOT NULL DEFAULT 'card' AFTER provider,
    ADD COLUMN IF NOT EXISTS flow_type ENUM('redirect', 'offline_charge') NOT NULL DEFAULT 'redirect' AFTER payment_mode,
    ADD KEY IF NOT EXISTS idx_billing_transactions_mode_status (workspace_id, payment_mode, transaction_status, created_at);
