SET @billing_transactions_subscription_fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'billing_transactions'
      AND CONSTRAINT_NAME = 'fk_billing_transactions_subscription'
);

SET @billing_transactions_subscription_fk_sql := IF(
    @billing_transactions_subscription_fk_exists = 0,
    'ALTER TABLE billing_transactions
        ADD CONSTRAINT fk_billing_transactions_subscription
        FOREIGN KEY (subscription_id) REFERENCES workspace_subscriptions(id)
        ON DELETE SET NULL',
    'SELECT 1'
);

PREPARE stmt FROM @billing_transactions_subscription_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
