-- Add created_by to contacts for activity/audit reporting
ALTER TABLE contacts ADD COLUMN created_by INT NULL AFTER assigned_to;

-- Helpful for filtering by creator (e.g. user_view stats)
CREATE INDEX idx_created_by ON contacts(created_by);

-- Best-effort backfill: if a contact is assigned, assume creator = assignee (legacy data)
UPDATE contacts SET created_by = assigned_to WHERE created_by IS NULL AND assigned_to IS NOT NULL;
