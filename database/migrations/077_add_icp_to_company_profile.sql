-- ICP (Ideal Customer Profile) fields for company_profile
-- Migration 077: Used by AI Coach for segmentation and targeting advice

ALTER TABLE company_profile ADD COLUMN icp_job_titles TEXT NULL;
ALTER TABLE company_profile ADD COLUMN icp_industries TEXT NULL;
ALTER TABLE company_profile ADD COLUMN icp_pain_points TEXT NULL;
ALTER TABLE company_profile ADD COLUMN icp_channels TEXT NULL;
