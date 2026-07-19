-- Outcome Layer: Event tracking

CREATE TABLE IF NOT EXISTS outcome_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    contact_id INT NULL,
    deal_id INT NULL,
    event_key VARCHAR(80) NOT NULL,
    event_source VARCHAR(60) NOT NULL DEFAULT 'system',
    event_at DATETIME NOT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_outcome_user_key_time (user_id, event_key, event_at),
    INDEX idx_outcome_contact_key_time (contact_id, event_key, event_at),
    INDEX idx_outcome_deal_key_time (deal_id, event_key, event_at),
    INDEX idx_outcome_event_time (event_at),
    CONSTRAINT fk_outcome_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_outcome_events_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_outcome_events_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
