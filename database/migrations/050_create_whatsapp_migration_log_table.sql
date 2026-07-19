-- WhatsApp Migration Log Table
-- Stores migration history and metadata for On-Premises to Cloud API migration

CREATE TABLE IF NOT EXISTS whatsapp_migration_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    step VARCHAR(50) NOT NULL,
    metadata_hash VARCHAR(255),
    api_status VARCHAR(50),
    api_version VARCHAR(20),
    success BOOLEAN DEFAULT FALSE,
    error_message TEXT,
    data_localization_region VARCHAR(2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_step (step),
    INDEX idx_success (success),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
