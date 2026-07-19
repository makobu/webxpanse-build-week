ALTER TABLE billing_checkout_sessions
    ADD COLUMN IF NOT EXISTS user_id INT NULL AFTER workspace_id,
    ADD COLUMN IF NOT EXISTS provider VARCHAR(50) NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS checkout_type VARCHAR(50) NULL AFTER provider,
    ADD COLUMN IF NOT EXISTS billing_plan_price_id INT NULL AFTER amount,
    ADD COLUMN IF NOT EXISTS token_pack_price_id INT NULL AFTER billing_plan_price_id,
    ADD COLUMN IF NOT EXISTS callback_url VARCHAR(1000) NULL AFTER authorization_url,
    ADD COLUMN IF NOT EXISTS metadata_json JSON NULL AFTER callback_url;

SET @billing_checkout_sessions_provider_name_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'billing_checkout_sessions'
      AND column_name = 'provider_name'
);
SET @billing_checkout_sessions_provider_name_sql := IF(
    @billing_checkout_sessions_provider_name_exists > 0,
    "UPDATE billing_checkout_sessions
     SET provider = COALESCE(NULLIF(provider, ''), NULLIF(provider_name, ''))
     WHERE (provider IS NULL OR provider = '')
       AND provider_name IS NOT NULL
       AND provider_name <> ''",
    'SELECT 1'
);
PREPARE stmt FROM @billing_checkout_sessions_provider_name_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @billing_checkout_sessions_return_url_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'billing_checkout_sessions'
      AND column_name = 'return_url'
);
SET @billing_checkout_sessions_return_url_sql := IF(
    @billing_checkout_sessions_return_url_exists > 0,
    "UPDATE billing_checkout_sessions
     SET callback_url = COALESCE(NULLIF(callback_url, ''), NULLIF(return_url, ''))
     WHERE (callback_url IS NULL OR callback_url = '')
       AND return_url IS NOT NULL
       AND return_url <> ''",
    'SELECT 1'
);
PREPARE stmt FROM @billing_checkout_sessions_return_url_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @billing_checkout_sessions_request_payload_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'billing_checkout_sessions'
      AND column_name = 'request_payload_json'
);
SET @billing_checkout_sessions_request_payload_sql := IF(
    @billing_checkout_sessions_request_payload_exists > 0,
    "UPDATE billing_checkout_sessions
     SET metadata_json = request_payload_json
     WHERE metadata_json IS NULL
       AND request_payload_json IS NOT NULL
       AND request_payload_json <> ''
       AND JSON_VALID(request_payload_json)",
    'SELECT 1'
);
PREPARE stmt FROM @billing_checkout_sessions_request_payload_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE billing_checkout_sessions
SET checkout_type = CASE
        WHEN billing_plan_price_id IS NOT NULL AND token_pack_price_id IS NOT NULL THEN 'mixed'
        WHEN billing_plan_price_id IS NOT NULL THEN 'subscription'
        WHEN token_pack_price_id IS NOT NULL THEN 'token_pack'
        ELSE COALESCE(NULLIF(checkout_type, ''), 'token_pack')
    END
WHERE checkout_type IS NULL
   OR checkout_type = '';

ALTER TABLE billing_transactions
    ADD COLUMN IF NOT EXISTS wallet_ledger_id INT NULL AFTER subscription_id,
    ADD COLUMN IF NOT EXISTS provider VARCHAR(50) NULL AFTER wallet_ledger_id,
    ADD COLUMN IF NOT EXISTS provider_reference VARCHAR(255) NULL AFTER provider,
    ADD COLUMN IF NOT EXISTS transaction_type VARCHAR(100) NULL AFTER provider_reference,
    ADD COLUMN IF NOT EXISTS transaction_status VARCHAR(50) NULL AFTER transaction_type,
    ADD COLUMN IF NOT EXISTS metadata_json JSON NULL AFTER currency;

SET @billing_transactions_provider_name_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'billing_transactions'
      AND column_name = 'provider_name'
);
SET @billing_transactions_provider_name_sql := IF(
    @billing_transactions_provider_name_exists > 0,
    "UPDATE billing_transactions
     SET provider = COALESCE(NULLIF(provider, ''), NULLIF(provider_name, ''))
     WHERE (provider IS NULL OR provider = '')
       AND provider_name IS NOT NULL
       AND provider_name <> ''",
    'SELECT 1'
);
PREPARE stmt FROM @billing_transactions_provider_name_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @billing_transactions_reference_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'billing_transactions'
      AND column_name = 'reference'
);
SET @billing_transactions_reference_sql := IF(
    @billing_transactions_reference_exists > 0,
    "UPDATE billing_transactions
     SET provider_reference = COALESCE(NULLIF(provider_reference, ''), NULLIF(reference, ''))
     WHERE (provider_reference IS NULL OR provider_reference = '')
       AND reference IS NOT NULL
       AND reference <> ''",
    'SELECT 1'
);
PREPARE stmt FROM @billing_transactions_reference_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @billing_transactions_status_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'billing_transactions'
      AND column_name = 'status'
);
SET @billing_transactions_status_sql := IF(
    @billing_transactions_status_exists > 0,
    "UPDATE billing_transactions
     SET transaction_status = COALESCE(NULLIF(transaction_status, ''), NULLIF(status, ''))
     WHERE (transaction_status IS NULL OR transaction_status = '')
       AND status IS NOT NULL
       AND status <> ''",
    'SELECT 1'
);
PREPARE stmt FROM @billing_transactions_status_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @billing_transactions_event_name_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'billing_transactions'
      AND column_name = 'event_name'
);
SET @billing_transactions_event_name_sql := IF(
    @billing_transactions_event_name_exists > 0,
    "UPDATE billing_transactions
     SET transaction_type = CASE
             WHEN subscription_id IS NOT NULL THEN 'subscription_charge'
             WHEN wallet_ledger_id IS NOT NULL THEN 'token_pack_purchase'
             WHEN event_name IS NOT NULL AND event_name <> '' THEN event_name
             ELSE COALESCE(NULLIF(transaction_type, ''), 'provider_event')
         END
     WHERE transaction_type IS NULL
        OR transaction_type = ''",
    "UPDATE billing_transactions
     SET transaction_type = CASE
             WHEN subscription_id IS NOT NULL THEN 'subscription_charge'
             WHEN wallet_ledger_id IS NOT NULL THEN 'token_pack_purchase'
             ELSE COALESCE(NULLIF(transaction_type, ''), 'provider_event')
         END
     WHERE transaction_type IS NULL
        OR transaction_type = ''"
);
PREPARE stmt FROM @billing_transactions_event_name_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @billing_transactions_raw_payload_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'billing_transactions'
      AND column_name = 'raw_payload_json'
);
SET @billing_transactions_raw_payload_sql := IF(
    @billing_transactions_raw_payload_exists > 0,
    "UPDATE billing_transactions
     SET metadata_json = raw_payload_json
     WHERE metadata_json IS NULL
       AND raw_payload_json IS NOT NULL
       AND raw_payload_json <> ''
       AND JSON_VALID(raw_payload_json)",
    'SELECT 1'
);
PREPARE stmt FROM @billing_transactions_raw_payload_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @billing_checkout_sessions_provider_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'billing_checkout_sessions'
      AND index_name = 'idx_billing_checkout_sessions_provider_reference'
);
SET @billing_checkout_sessions_provider_idx_sql := IF(
    @billing_checkout_sessions_provider_idx_exists = 0,
    'ALTER TABLE billing_checkout_sessions ADD KEY idx_billing_checkout_sessions_provider_reference (provider_reference)',
    'SELECT 1'
);
PREPARE stmt FROM @billing_checkout_sessions_provider_idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @billing_transactions_checkout_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'billing_transactions'
      AND index_name = 'idx_billing_transactions_checkout_session'
);
SET @billing_transactions_checkout_idx_sql := IF(
    @billing_transactions_checkout_idx_exists = 0,
    'ALTER TABLE billing_transactions ADD KEY idx_billing_transactions_checkout_session (checkout_session_id)',
    'SELECT 1'
);
PREPARE stmt FROM @billing_transactions_checkout_idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
