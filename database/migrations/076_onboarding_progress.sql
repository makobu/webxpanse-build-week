-- Onboarding Progress: First-week checklist for Foundation Mode
-- Migration 076: Tracks user progress through initial setup

CREATE TABLE IF NOT EXISTS onboarding_progress (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    profile_complete BOOLEAN DEFAULT FALSE,
    first_contact_added BOOLEAN DEFAULT FALSE,
    first_task_created BOOLEAN DEFAULT FALSE,
    first_deal_created BOOLEAN DEFAULT FALSE,
    first_workflow_activated BOOLEAN DEFAULT FALSE,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_id (user_id),
    CONSTRAINT fk_onboarding_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
