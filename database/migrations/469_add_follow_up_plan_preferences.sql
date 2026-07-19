ALTER TABLE nurture_programs
    ADD COLUMN IF NOT EXISTS first_touch_delay_days INT NULL AFTER cadence,
    ADD COLUMN IF NOT EXISTS preferred_channel ENUM('task','email','whatsapp','sms','phone','meeting','note','other') NOT NULL DEFAULT 'task' AFTER first_touch_delay_days,
    ADD COLUMN IF NOT EXISTS default_touch_type ENUM('check_in','renewal','expansion','risk_recovery') NOT NULL DEFAULT 'check_in' AFTER preferred_channel,
    ADD COLUMN IF NOT EXISTS touch_guidance TEXT NULL AFTER default_touch_type;

UPDATE nurture_programs
SET preferred_channel = COALESCE(preferred_channel, 'task'),
    default_touch_type = COALESCE(default_touch_type, 'check_in')
WHERE preferred_channel IS NULL
   OR default_touch_type IS NULL;

ALTER TABLE nurture_touchpoints
    MODIFY COLUMN channel ENUM('email','phone','whatsapp','sms','meeting','task','note','other') NOT NULL DEFAULT 'task';
