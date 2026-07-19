-- Marketing Phase 10: manual distribution publishing toolkit.

ALTER TABLE marketing_distribution_posts
    MODIFY status ENUM('draft','scheduled','exported','published','cancelled') NOT NULL DEFAULT 'draft',
    ADD COLUMN IF NOT EXISTS publishing_checklist_json JSON NULL AFTER planned_copy,
    ADD COLUMN IF NOT EXISTS required_fields_json JSON NULL AFTER publishing_checklist_json,
    ADD COLUMN IF NOT EXISTS asset_rules_json JSON NULL AFTER required_fields_json,
    ADD COLUMN IF NOT EXISTS published_url VARCHAR(1400) NULL AFTER exported_at,
    ADD COLUMN IF NOT EXISTS published_at DATETIME NULL AFTER published_url,
    ADD COLUMN IF NOT EXISTS exported_bundle_json JSON NULL AFTER published_at,
    ADD INDEX IF NOT EXISTS idx_marketing_distribution_workspace_published (workspace_id, published_at),
    ADD INDEX IF NOT EXISTS idx_marketing_distribution_workspace_url (workspace_id, published_url(191));
