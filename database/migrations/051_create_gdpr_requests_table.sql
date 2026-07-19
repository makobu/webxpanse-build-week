-- GDPR Requests Table
-- Tracks GDPR data requests (export, deletion, access, rectification)

CREATE TABLE IF NOT EXISTS gdpr_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    request_type ENUM('export', 'deletion', 'access', 'rectification') NOT NULL,
    status ENUM('pending', 'verified', 'processing', 'completed', 'failed') DEFAULT 'pending',
    verification_token VARCHAR(64) UNIQUE NOT NULL,
    verified_at DATETIME DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_verification_token (verification_token),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
