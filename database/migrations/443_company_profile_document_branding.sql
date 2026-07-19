-- Migration 443: Company Profile document branding source

ALTER TABLE company_profile
    ADD COLUMN company_legal_name VARCHAR(255) NULL AFTER company_name;

ALTER TABLE company_profile
    ADD COLUMN company_tax_id VARCHAR(100) NULL AFTER company_address;

UPDATE company_profile cp
LEFT JOIN invoice_settings inv ON inv.id = 1
SET cp.company_legal_name = COALESCE(
        NULLIF(TRIM(cp.company_legal_name), ''),
        NULLIF(TRIM(inv.company_legal_name), ''),
        CASE
            WHEN TRIM(cp.company_name) <> 'Your Company Name' THEN NULLIF(TRIM(cp.company_name), '')
            ELSE NULL
        END
    ),
    cp.company_tax_id = COALESCE(
        NULLIF(TRIM(cp.company_tax_id), ''),
        NULLIF(TRIM(inv.company_tax_id), '')
    ),
    cp.company_logo_url = COALESCE(
        NULLIF(TRIM(cp.company_logo_url), ''),
        NULLIF(TRIM(inv.logo_asset_path), '')
    )
WHERE cp.is_active = 1;
