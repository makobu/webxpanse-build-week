-- Email Verification
-- Migration 065: Add email verification support

ALTER TABLE users 
ADD COLUMN email_verified_at TIMESTAMP NULL AFTER last_login,
ADD COLUMN email_verification_token VARCHAR(64) NULL AFTER email_verified_at;
