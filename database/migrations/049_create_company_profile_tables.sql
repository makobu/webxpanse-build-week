-- Company Profile & Products Tables
-- Migration 049: Company Profile and Products Management

-- Company Profile Table - Single record storing company information
CREATE TABLE IF NOT EXISTS company_profile (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_name VARCHAR(255) NOT NULL,
    company_tagline VARCHAR(500),
    company_description TEXT,
    company_mission TEXT,
    company_values TEXT,
    company_website VARCHAR(500),
    company_email VARCHAR(255),
    company_phone VARCHAR(50),
    company_address TEXT,
    company_location VARCHAR(255),
    company_timezone VARCHAR(50),
    company_industry VARCHAR(255),
    company_founded YEAR,
    company_size VARCHAR(50),
    company_logo_url VARCHAR(500),
    social_linkedin VARCHAR(500),
    social_twitter VARCHAR(500),
    social_facebook VARCHAR(500),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Products/Services Table - Multiple products with features and metadata
CREATE TABLE IF NOT EXISTS products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    features JSON,
    pricing_info TEXT,
    target_audience TEXT,
    use_cases TEXT,
    benefits TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_is_active (is_active),
    INDEX idx_display_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default company profile (single record)
INSERT INTO company_profile (id, company_name, is_active) 
VALUES (1, 'Your Company Name', TRUE)
ON DUPLICATE KEY UPDATE id=id;
