-- Add confidence_score column to contacts table for Hunter.io email verification confidence
-- This stores the confidence score (0-100) indicating how likely the email is correct and deliverable

-- Add the column first
ALTER TABLE contacts ADD COLUMN confidence_score INT NULL COMMENT 'Email verification confidence score from Hunter.io (0-100)';

-- Then create the index (will fail if column doesn't exist, but that's handled by migration script)
