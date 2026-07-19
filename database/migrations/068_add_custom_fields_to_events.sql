-- Add custom_fields to events for calendar sync (google_event_id, ical_uid, etc.)
ALTER TABLE events ADD COLUMN custom_fields JSON NULL AFTER recurrence_count;
