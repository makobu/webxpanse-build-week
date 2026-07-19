-- Add parent_id for threaded replies
ALTER TABLE notes ADD COLUMN parent_id INT NULL AFTER entity_id, ADD INDEX idx_parent_id (parent_id);
