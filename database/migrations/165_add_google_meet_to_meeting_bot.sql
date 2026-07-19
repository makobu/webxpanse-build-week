ALTER TABLE meeting_bot_config
    MODIFY provider ENUM('zoom', 'google_meet') NOT NULL DEFAULT 'zoom';

ALTER TABLE meeting_bot_runs
    MODIFY provider ENUM('zoom', 'google_meet') NOT NULL DEFAULT 'zoom';
