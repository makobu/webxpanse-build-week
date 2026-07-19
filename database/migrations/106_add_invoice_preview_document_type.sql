-- Migration 106: Add preview document type to invoice settings

ALTER TABLE invoice_settings
    ADD COLUMN preview_document_type ENUM('quote','proforma','invoice') NOT NULL DEFAULT 'invoice' AFTER visual_theme;

UPDATE invoice_settings
SET visual_theme = CASE
        WHEN visual_theme IN ('classic', 'minimal', 'bold') THEN visual_theme
        ELSE 'classic'
    END,
    preview_document_type = CASE
        WHEN preview_document_type IN ('quote', 'proforma', 'invoice') THEN preview_document_type
        ELSE 'invoice'
    END
WHERE id = 1;
