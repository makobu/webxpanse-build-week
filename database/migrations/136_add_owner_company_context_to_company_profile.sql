-- Optional owner-provided company context for AI guidance and drafting.

ALTER TABLE company_profile
    ADD COLUMN owner_company_context TEXT NULL AFTER company_values;
