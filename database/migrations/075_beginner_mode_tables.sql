-- Beginner Mode: Idea Validation Context and Budget
-- Migration 075: Tables for Foundation Mode features
-- No FK to users(id) so this runs on schemas where users.id is not a unique/primary key.

-- Idea Validation Context: user-provided context for idea validation (Foundation Mode)
CREATE TABLE IF NOT EXISTS idea_validation_context (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    value_proposition TEXT,
    target_market TEXT,
    pain_points TEXT,
    assumptions_to_test TEXT,
    competitors TEXT,
    differentiator TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_id (user_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Beginner Budget: minimal budgeting for Foundation Mode
CREATE TABLE IF NOT EXISTS beginner_budget (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    monthly_marketing_budget DECIMAL(12,2) DEFAULT 0.00,
    monthly_fixed_costs DECIMAL(12,2) DEFAULT 0.00,
    target_deal_value DECIMAL(12,2) DEFAULT 0.00,
    target_cac DECIMAL(12,2) NULL,
    currency_code VARCHAR(3) DEFAULT 'USD',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_id (user_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
