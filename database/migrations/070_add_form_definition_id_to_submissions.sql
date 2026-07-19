-- Link form submissions to form definitions
ALTER TABLE form_submissions ADD COLUMN form_definition_id INT NULL AFTER form_id, ADD INDEX idx_form_definition_id (form_definition_id);