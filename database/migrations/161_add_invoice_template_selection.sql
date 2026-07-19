ALTER TABLE invoice_settings
    ADD COLUMN IF NOT EXISTS default_template_key VARCHAR(50) NOT NULL DEFAULT 'classic' AFTER visual_theme;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS template_key VARCHAR(50) NULL AFTER source_snapshot_json;

UPDATE invoice_settings
SET default_template_key = CASE
    WHEN COALESCE(NULLIF(default_template_key, ''), '') <> '' THEN default_template_key
    WHEN COALESCE(NULLIF(visual_theme, ''), '') <> '' THEN visual_theme
    ELSE 'classic'
END;
