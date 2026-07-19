CREATE TABLE IF NOT EXISTS user_strategy_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    target_market_focus TEXT,
    ideal_customer_profile TEXT,
    offer_angle TEXT,
    segment_focus TEXT,
    sales_motion TEXT,
    deal_movement_strategy TEXT,
    outreach_posture TEXT,
    positioning_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_strategy_profiles_user_id (user_id),
    INDEX idx_user_strategy_profiles_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
