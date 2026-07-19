-- Allow workflow and system activity types beyond the original fixed enum.
ALTER TABLE activities
MODIFY COLUMN activity_type VARCHAR(64) NOT NULL;
