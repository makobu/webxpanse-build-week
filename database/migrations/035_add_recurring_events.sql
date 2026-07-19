-- Add recurring event fields to events table
ALTER TABLE events 
ADD COLUMN recurrence_pattern ENUM('none','daily','weekly','monthly','yearly') DEFAULT 'none' AFTER event_type,
ADD COLUMN recurrence_end_date DATE NULL AFTER recurrence_pattern,
ADD COLUMN recurrence_count INT NULL AFTER recurrence_end_date,
ADD COLUMN parent_event_id INT NULL AFTER recurrence_count,
ADD INDEX idx_recurrence_pattern (recurrence_pattern),
ADD INDEX idx_parent_event_id (parent_event_id),
ADD FOREIGN KEY (parent_event_id) REFERENCES events(id) ON DELETE CASCADE;
