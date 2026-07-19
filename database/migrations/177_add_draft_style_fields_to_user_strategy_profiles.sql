ALTER TABLE user_strategy_profiles
    ADD COLUMN draft_tone_preset VARCHAR(50) NULL AFTER positioning_notes,
    ADD COLUMN draft_voice_notes TEXT NULL AFTER draft_tone_preset,
    ADD COLUMN draft_cta_style VARCHAR(50) NULL AFTER draft_voice_notes,
    ADD COLUMN draft_formality_level VARCHAR(50) NULL AFTER draft_cta_style;
