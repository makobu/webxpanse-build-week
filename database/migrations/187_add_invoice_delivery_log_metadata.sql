ALTER TABLE invoice_delivery_log
    ADD COLUMN email_id INT NULL AFTER sent_by_type,
    ADD COLUMN email_uuid VARCHAR(64) NULL AFTER email_id,
    ADD COLUMN provider_key VARCHAR(100) NULL AFTER email_uuid,
    ADD COLUMN provider_method VARCHAR(100) NULL AFTER provider_key,
    ADD COLUMN failure_reason TEXT NULL AFTER provider_method,
    ADD COLUMN details_json JSON NULL AFTER failure_reason;

ALTER TABLE invoice_delivery_log
    ADD INDEX idx_invoice_delivery_email_id (email_id);
