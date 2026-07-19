-- Organization Intelligence V2 completion: workspace rollout and shadow diagnostics.

ALTER TABLE organization_intelligence_profiles
    ADD COLUMN rollout_state VARCHAR(20) NOT NULL DEFAULT 'v2_enabled' AFTER engine_version,
    ADD COLUMN promoted_at DATETIME NULL AFTER confirmed_at,
    ADD COLUMN promoted_by INT NULL AFTER promoted_at,
    ADD COLUMN rollback_until DATETIME NULL AFTER promoted_by,
    ADD COLUMN last_shadow_comparison_at DATETIME NULL AFTER rollback_until,
    ADD COLUMN shadow_comparison_json JSON NULL AFTER last_shadow_comparison_at,
    ADD KEY idx_oi_profile_rollout_state (rollout_state, engine_version),
    ADD CONSTRAINT fk_oi_profile_promoted_by FOREIGN KEY (promoted_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE organization_intelligence_profiles
SET rollout_state = CASE
        WHEN engine_version = 'v1' THEN 'rolled_back'
        ELSE 'v2_enabled'
    END
WHERE rollout_state IS NULL OR rollout_state = '';
