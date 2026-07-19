ALTER TABLE meeting_booking_profiles
    MODIFY COLUMN buffer_before_minutes INT NOT NULL DEFAULT 15,
    MODIFY COLUMN buffer_after_minutes INT NOT NULL DEFAULT 15;

UPDATE meeting_booking_profiles
SET buffer_before_minutes = 15,
    buffer_after_minutes = 15
WHERE slug = 'default'
  AND title = 'Book a meeting'
  AND status = 'active'
  AND (buffer_before_minutes = 0 OR buffer_after_minutes = 0);
