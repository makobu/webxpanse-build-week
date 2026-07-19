-- Cue-aware protected demo director state.

ALTER TABLE demo_experience_events
    ADD COLUMN trigger_mode VARCHAR(20) NOT NULL DEFAULT 'time';

ALTER TABLE demo_experience_events
    ADD COLUMN trigger_page VARCHAR(120) NULL;

ALTER TABLE demo_experience_events
    ADD COLUMN trigger_name VARCHAR(120) NULL;

ALTER TABLE demo_experience_events
    ADD COLUMN depends_on_event_key VARCHAR(80) NULL;

ALTER TABLE demo_experience_events
    ADD COLUMN eligible_at DATETIME NULL;

ALTER TABLE demo_experience_events
    ADD COLUMN deadline_at DATETIME NULL;

ALTER TABLE demo_experience_events
    ADD COLUMN cue_seen_at DATETIME NULL;

ALTER TABLE demo_experience_events
    ADD COLUMN cue_count INT NOT NULL DEFAULT 0;

ALTER TABLE demo_experience_events
    ADD COLUMN auto_action_state VARCHAR(40) NOT NULL DEFAULT 'none';

ALTER TABLE demo_experience_events
    ADD COLUMN director_state_json JSON NULL;

ALTER TABLE demo_experience_events
    ADD KEY idx_demo_experience_cue (demo_session_id, status, trigger_page, trigger_name);

ALTER TABLE demo_experience_events
    ADD KEY idx_demo_experience_eligible (demo_session_id, status, eligible_at);
