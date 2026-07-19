ALTER TABLE contacts
    ADD COLUMN metadata_json JSON DEFAULT NULL AFTER ai_context;
