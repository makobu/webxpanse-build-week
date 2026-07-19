-- Add stage-aware relevance states for Organization Intelligence functions.

ALTER TABLE organization_functions
    ADD COLUMN relevance_status VARCHAR(30) NOT NULL DEFAULT 'active' AFTER measurement_strength,
    ADD COLUMN relevance_note TEXT NULL AFTER relevance_status,
    ADD KEY idx_organization_functions_workspace_relevance (workspace_id, relevance_status);

UPDATE organization_functions
SET relevance_status = 'active'
WHERE relevance_status IS NULL OR relevance_status = '';
