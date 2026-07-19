-- Versioned per-user strategy snapshots for AI Coach and HR Analytics.

SET @db_name = DATABASE();

SET @has_strategy_market_view = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'user_strategy_profiles'
      AND COLUMN_NAME = 'market_view'
);
SET @sql = IF(
    @has_strategy_market_view = 0,
    'ALTER TABLE user_strategy_profiles ADD COLUMN market_view TEXT NULL AFTER positioning_notes',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_strategy_hypothesis = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'user_strategy_profiles'
      AND COLUMN_NAME = 'strategy_hypothesis'
);
SET @sql = IF(
    @has_strategy_hypothesis = 0,
    'ALTER TABLE user_strategy_profiles ADD COLUMN strategy_hypothesis TEXT NULL AFTER market_view',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS user_strategy_snapshots (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    strategy_profile_id INT NULL,
    idea_validation_id INT NULL,
    version INT NOT NULL DEFAULT 1,
    source_hash CHAR(64) NOT NULL,
    status ENUM('active', 'superseded') NOT NULL DEFAULT 'active',
    target_market_focus TEXT NULL,
    ideal_customer_profile TEXT NULL,
    offer_angle TEXT NULL,
    segment_focus TEXT NULL,
    sales_motion TEXT NULL,
    deal_movement_strategy TEXT NULL,
    outreach_posture TEXT NULL,
    positioning_notes TEXT NULL,
    market_view TEXT NULL,
    strategy_hypothesis TEXT NULL,
    value_proposition TEXT NULL,
    idea_target_market TEXT NULL,
    pain_points TEXT NULL,
    assumptions_to_test TEXT NULL,
    competitors TEXT NULL,
    differentiator TEXT NULL,
    source_json JSON NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user_strategy_snapshots_workspace_user_status (workspace_id, user_id, status),
    KEY idx_user_strategy_snapshots_workspace_started (workspace_id, started_at),
    KEY idx_user_strategy_snapshots_hash (workspace_id, user_id, source_hash),
    UNIQUE KEY uk_user_strategy_snapshots_workspace_user_version (workspace_id, user_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO user_strategy_snapshots (
    workspace_id, user_id, strategy_profile_id, idea_validation_id, version, source_hash, status,
    target_market_focus, ideal_customer_profile, offer_angle, segment_focus, sales_motion,
    deal_movement_strategy, outreach_posture, positioning_notes, market_view, strategy_hypothesis,
    value_proposition, idea_target_market, pain_points, assumptions_to_test, competitors, differentiator,
    source_json, started_at, created_at, updated_at
)
SELECT
    pairs.workspace_id,
    pairs.user_id,
    sp.id AS strategy_profile_id,
    iv.id AS idea_validation_id,
    1 AS version,
    SHA2(CONCAT_WS('|',
        COALESCE(sp.target_market_focus, ''),
        COALESCE(sp.ideal_customer_profile, ''),
        COALESCE(sp.offer_angle, ''),
        COALESCE(sp.segment_focus, ''),
        COALESCE(sp.sales_motion, ''),
        COALESCE(sp.deal_movement_strategy, ''),
        COALESCE(sp.outreach_posture, ''),
        COALESCE(sp.positioning_notes, ''),
        COALESCE(sp.market_view, ''),
        COALESCE(sp.strategy_hypothesis, ''),
        COALESCE(iv.value_proposition, ''),
        COALESCE(iv.target_market, ''),
        COALESCE(iv.pain_points, ''),
        COALESCE(iv.assumptions_to_test, ''),
        COALESCE(iv.competitors, ''),
        COALESCE(iv.differentiator, '')
    ), 256) AS source_hash,
    'active' AS status,
    sp.target_market_focus,
    sp.ideal_customer_profile,
    sp.offer_angle,
    sp.segment_focus,
    sp.sales_motion,
    sp.deal_movement_strategy,
    sp.outreach_posture,
    sp.positioning_notes,
    sp.market_view,
    sp.strategy_hypothesis,
    iv.value_proposition,
    iv.target_market,
    iv.pain_points,
    iv.assumptions_to_test,
    iv.competitors,
    iv.differentiator,
    JSON_OBJECT(
        'target_market_focus', COALESCE(sp.target_market_focus, ''),
        'ideal_customer_profile', COALESCE(sp.ideal_customer_profile, ''),
        'offer_angle', COALESCE(sp.offer_angle, ''),
        'segment_focus', COALESCE(sp.segment_focus, ''),
        'sales_motion', COALESCE(sp.sales_motion, ''),
        'deal_movement_strategy', COALESCE(sp.deal_movement_strategy, ''),
        'outreach_posture', COALESCE(sp.outreach_posture, ''),
        'positioning_notes', COALESCE(sp.positioning_notes, ''),
        'market_view', COALESCE(sp.market_view, ''),
        'strategy_hypothesis', COALESCE(sp.strategy_hypothesis, ''),
        'value_proposition', COALESCE(iv.value_proposition, ''),
        'target_market', COALESCE(iv.target_market, ''),
        'pain_points', COALESCE(iv.pain_points, ''),
        'assumptions_to_test', COALESCE(iv.assumptions_to_test, ''),
        'competitors', COALESCE(iv.competitors, ''),
        'differentiator', COALESCE(iv.differentiator, '')
    ) AS source_json,
    COALESCE(sp.updated_at, iv.updated_at, NOW()) AS started_at,
    NOW() AS created_at,
    NOW() AS updated_at
FROM (
    SELECT workspace_id, user_id FROM user_strategy_profiles
    UNION
    SELECT workspace_id, user_id FROM idea_validation_context
) pairs
LEFT JOIN user_strategy_profiles sp
    ON sp.workspace_id = pairs.workspace_id
   AND sp.user_id = pairs.user_id
LEFT JOIN idea_validation_context iv
    ON iv.workspace_id = pairs.workspace_id
   AND iv.user_id = pairs.user_id
WHERE NOT EXISTS (
    SELECT 1
    FROM user_strategy_snapshots existing
    WHERE existing.workspace_id = pairs.workspace_id
      AND existing.user_id = pairs.user_id
)
  AND CONCAT_WS('',
    COALESCE(sp.target_market_focus, ''),
    COALESCE(sp.ideal_customer_profile, ''),
    COALESCE(sp.offer_angle, ''),
    COALESCE(sp.segment_focus, ''),
    COALESCE(sp.sales_motion, ''),
    COALESCE(sp.deal_movement_strategy, ''),
    COALESCE(sp.outreach_posture, ''),
    COALESCE(sp.positioning_notes, ''),
    COALESCE(sp.market_view, ''),
    COALESCE(sp.strategy_hypothesis, ''),
    COALESCE(iv.value_proposition, ''),
    COALESCE(iv.target_market, ''),
    COALESCE(iv.pain_points, ''),
    COALESCE(iv.assumptions_to_test, ''),
    COALESCE(iv.competitors, ''),
    COALESCE(iv.differentiator, '')
  ) <> '';
