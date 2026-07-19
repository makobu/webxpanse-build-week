-- Add primary contact support to companies

ALTER TABLE companies
    ADD COLUMN primary_contact_id INT NULL AFTER assigned_to;

ALTER TABLE companies
    ADD INDEX idx_companies_primary_contact_id (primary_contact_id);

ALTER TABLE companies
    ADD CONSTRAINT fk_companies_primary_contact
    FOREIGN KEY (primary_contact_id) REFERENCES contacts(id)
    ON DELETE SET NULL;
