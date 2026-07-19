-- Remove duplicate workspace foreign keys left by legacy draft-table installs.
-- Keep the canonical fk_* constraints added by migration 213.

SET @drop_draft_templates_legacy_fk := (
    SELECT IF(
        COALESCE(SUM(CONSTRAINT_NAME = 'draft_templates_ibfk_1'), 0) > 0
        AND COALESCE(SUM(CONSTRAINT_NAME = 'fk_draft_templates_workspace_id'), 0) > 0,
        'ALTER TABLE draft_templates DROP FOREIGN KEY draft_templates_ibfk_1',
        'DO 0'
    )
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'draft_templates'
      AND COLUMN_NAME = 'workspace_id'
      AND REFERENCED_TABLE_NAME = 'workspaces'
      AND REFERENCED_COLUMN_NAME = 'id'
);

PREPARE drop_draft_templates_legacy_fk FROM @drop_draft_templates_legacy_fk;
EXECUTE drop_draft_templates_legacy_fk;
DEALLOCATE PREPARE drop_draft_templates_legacy_fk;

SET @drop_draft_reviews_legacy_fk := (
    SELECT IF(
        COALESCE(SUM(CONSTRAINT_NAME = 'draft_reviews_ibfk_1'), 0) > 0
        AND COALESCE(SUM(CONSTRAINT_NAME = 'fk_draft_reviews_workspace_id'), 0) > 0,
        'ALTER TABLE draft_reviews DROP FOREIGN KEY draft_reviews_ibfk_1',
        'DO 0'
    )
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'draft_reviews'
      AND COLUMN_NAME = 'workspace_id'
      AND REFERENCED_TABLE_NAME = 'workspaces'
      AND REFERENCED_COLUMN_NAME = 'id'
);

PREPARE drop_draft_reviews_legacy_fk FROM @drop_draft_reviews_legacy_fk;
EXECUTE drop_draft_reviews_legacy_fk;
DEALLOCATE PREPARE drop_draft_reviews_legacy_fk;
