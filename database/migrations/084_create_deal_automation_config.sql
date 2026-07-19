-- Deal Automation Configuration
-- Singleton table for AI deal-stage automation settings

DROP TABLE IF EXISTS deal_automation_config;

CREATE TABLE deal_automation_config (
    id INT PRIMARY KEY,
    enabled TINYINT(1) DEFAULT 0,
    mode VARCHAR(20) DEFAULT 'suggest_only',
    min_confidence DECIMAL(3,2) DEFAULT 0.85,
    lookback_days INT DEFAULT 14,
    cooldown_hours INT DEFAULT 24,
    require_approval_terminal TINYINT(1) DEFAULT 1,
    min_terminal_confidence DECIMAL(3,2) DEFAULT 0.92,
    inactivity_days_for_loss INT DEFAULT 14,
    allow_multi_stage_jump TINYINT(1) DEFAULT 0,
    dry_run TINYINT(1) DEFAULT 0,
    config_json JSON NULL,
    schema_version INT DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO deal_automation_config (id, enabled, mode, min_confidence, lookback_days, cooldown_hours, require_approval_terminal, min_terminal_confidence, inactivity_days_for_loss, allow_multi_stage_jump, dry_run, config_json, schema_version)
VALUES (1, 0, 'suggest_only', 0.85, 14, 24, 1, 0.92, 14, 0, 0, '{"transitions":[{"from":"prospecting","to":"qualification","enabled":true,"min_confidence":0.8,"rules":[{"type":"intent_count","intent":"purchase","min":1,"window_days":14},{"type":"intent_count","intent":"inquiry","min":1,"window_days":14},{"type":"lead_score_min","value":30},{"type":"no_negative_trend","window_days":7}],"require_all":false},{"from":"qualification","to":"proposal","enabled":true,"min_confidence":0.82,"rules":[{"type":"proposal_sent","required":true}],"require_all":true},{"from":"proposal","to":"negotiation","enabled":true,"min_confidence":0.82,"rules":[{"type":"intent_count","intent":"purchase","min":1,"window_days":14},{"type":"bidirectional_exchange","min_messages":2,"window_days":14}],"require_all":false},{"from":"negotiation","to":"closed_won","enabled":true,"min_confidence":0.92,"terminal":true,"rules":[{"type":"explicit_acceptance","required":true},{"type":"no_negative_in_window","window_days":7}],"require_all":true},{"from":"any_open","to":"closed_lost","enabled":true,"min_confidence":0.9,"terminal":true,"rules":[{"type":"explicit_rejection","required":false},{"type":"inactivity_timeout","days":14},{"type":"no_positive_in_window","window_days":14}],"require_all":false}],"schema_version":1}', 1);
