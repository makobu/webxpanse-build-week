ALTER TABLE default_workspace_owner_contacts
    ADD COLUMN conversion_deal_id INT NULL AFTER contact_id,
    ADD KEY idx_default_owner_conversion_deal (conversion_deal_id),
    ADD CONSTRAINT fk_default_owner_contacts_conversion_deal
        FOREIGN KEY (conversion_deal_id) REFERENCES deals(id) ON DELETE SET NULL;
