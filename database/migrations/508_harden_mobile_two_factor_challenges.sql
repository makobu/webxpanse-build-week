ALTER TABLE mobile_auth_challenges
    ADD COLUMN IF NOT EXISTS purpose VARCHAR(32) NOT NULL DEFAULT 'login_2fa' AFTER challenge_hash,
    ADD COLUMN IF NOT EXISTS failed_attempts INT NOT NULL DEFAULT 0 AFTER consumed_at,
    ADD KEY IF NOT EXISTS idx_mobile_auth_challenges_purpose (purpose),
    ADD KEY IF NOT EXISTS idx_mobile_auth_challenges_lookup (challenge_hash, purpose, consumed_at, expires_at);
