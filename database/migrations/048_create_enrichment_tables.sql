-- AI-Powered Data Enrichment System Tables

-- Enrichment sources - Track data sources used for enrichment
CREATE TABLE IF NOT EXISTS enrichment_sources (
    id INT PRIMARY KEY AUTO_INCREMENT,
    contact_id INT NOT NULL,
    source_type ENUM('web', 'email', 'social', 'api', 'inference', 'validation') NOT NULL,
    source_url VARCHAR(500) NULL,
    raw_data JSON NULL,
    extracted_data JSON NULL,
    ai_model_used VARCHAR(100) NULL,
    confidence_score DECIMAL(3,2) DEFAULT 0.0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    INDEX idx_contact_id (contact_id),
    INDEX idx_source_type (source_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrichment history - Log all enrichment operations
CREATE TABLE IF NOT EXISTS enrichment_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    contact_id INT NOT NULL,
    enrichment_type ENUM('extract', 'infer', 'validate', 'merge') NOT NULL,
    fields_updated JSON NULL,
    ai_prompt_used TEXT NULL,
    ai_response JSON NULL,
    cost DECIMAL(10,4) DEFAULT 0.0000,
    status ENUM('success', 'failed', 'partial') DEFAULT 'success',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    INDEX idx_contact_id (contact_id),
    INDEX idx_enrichment_type (enrichment_type),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrichment configuration
CREATE TABLE IF NOT EXISTS enrichment_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    auto_enrich_on_create BOOLEAN DEFAULT FALSE,
    auto_enrich_on_update BOOLEAN DEFAULT FALSE,
    auto_enrich_on_email BOOLEAN DEFAULT FALSE,
    enrichment_providers JSON NULL,
    confidence_threshold DECIMAL(3,2) DEFAULT 0.7,
    max_cost_per_contact DECIMAL(10,4) DEFAULT 0.10,
    daily_cost_limit DECIMAL(10,2) DEFAULT 10.00,
    monthly_cost_limit DECIMAL(10,2) DEFAULT 100.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default configuration
INSERT INTO enrichment_config (id, auto_enrich_on_create, auto_enrich_on_update, auto_enrich_on_email, enrichment_providers, confidence_threshold, max_cost_per_contact) 
VALUES (1, FALSE, FALSE, FALSE, '{"extract_web": true, "extract_email": true, "infer_fields": true, "validate_data": true}', 0.7, 0.10)
ON DUPLICATE KEY UPDATE id=id;

-- Add enrichment fields to contacts table (using separate ALTER statements)
-- Note: These will fail silently if columns already exist, which is fine
ALTER TABLE contacts ADD COLUMN company_website VARCHAR(500) NULL;
ALTER TABLE contacts ADD COLUMN company_size VARCHAR(50) NULL;
ALTER TABLE contacts ADD COLUMN company_industry VARCHAR(255) NULL;
ALTER TABLE contacts ADD COLUMN company_description TEXT NULL;
ALTER TABLE contacts ADD COLUMN company_founded YEAR NULL;
ALTER TABLE contacts ADD COLUMN company_revenue VARCHAR(100) NULL;
ALTER TABLE contacts ADD COLUMN job_title VARCHAR(255) NULL;
ALTER TABLE contacts ADD COLUMN linkedin_url VARCHAR(500) NULL;
ALTER TABLE contacts ADD COLUMN twitter_url VARCHAR(500) NULL;
ALTER TABLE contacts ADD COLUMN location VARCHAR(255) NULL;
ALTER TABLE contacts ADD COLUMN timezone VARCHAR(50) NULL;
ALTER TABLE contacts ADD COLUMN email_verified BOOLEAN DEFAULT FALSE;
ALTER TABLE contacts ADD COLUMN email_verification_status VARCHAR(50) NULL;
ALTER TABLE contacts ADD COLUMN last_enriched_at TIMESTAMP NULL;
ALTER TABLE contacts ADD COLUMN enrichment_score INT DEFAULT 0;
ALTER TABLE contacts ADD COLUMN enrichment_confidence DECIMAL(3,2) DEFAULT 0.0;

-- Add indexes (will fail if they exist, but that's okay - migration runner will continue)
CREATE INDEX idx_company_website ON contacts(company_website);
CREATE INDEX idx_last_enriched_at ON contacts(last_enriched_at);
CREATE INDEX idx_enrichment_score ON contacts(enrichment_score);
CREATE INDEX idx_company_industry ON contacts(company_industry);
CREATE INDEX idx_job_title ON contacts(job_title);
