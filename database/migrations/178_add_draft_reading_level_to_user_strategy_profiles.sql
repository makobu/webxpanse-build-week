ALTER TABLE user_strategy_profiles
    ADD COLUMN draft_reading_level VARCHAR(50) NULL AFTER draft_formality_level;
