CREATE TABLE IF NOT EXISTS ai_advice_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guidance_run_id INT NULL,
    user_id INT NOT NULL,
    surface VARCHAR(32) NOT NULL,
    feedback_type VARCHAR(32) NOT NULL,
    recommendation_key VARCHAR(120) NULL,
    message_hash VARCHAR(64) NULL,
    linked_task_id INT NULL,
    linked_contact_id INT NULL,
    linked_deal_id INT NULL,
    feedback_notes TEXT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_advice_feedback_guidance_surface_created (guidance_run_id, surface, created_at),
    INDEX idx_ai_advice_feedback_user_surface_created (user_id, surface, created_at),
    INDEX idx_ai_advice_feedback_recommendation_key (recommendation_key),
    INDEX idx_ai_advice_feedback_message_hash (message_hash)
);
