-- Protect high-edit shared records from silent last-write-wins overwrites.

ALTER TABLE companies ADD COLUMN lock_version INT NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN lock_version INT NOT NULL DEFAULT 0;
ALTER TABLE events ADD COLUMN lock_version INT NOT NULL DEFAULT 0;
ALTER TABLE invoices ADD COLUMN lock_version INT NOT NULL DEFAULT 0;
ALTER TABLE company_profile ADD COLUMN lock_version INT NOT NULL DEFAULT 0;
ALTER TABLE custom_fields ADD COLUMN lock_version INT NOT NULL DEFAULT 0;
ALTER TABLE scheduled_reports ADD COLUMN lock_version INT NOT NULL DEFAULT 0;
