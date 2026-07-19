-- Companies / Organizations Entity
-- Migration 066: First-class company entity for B2B CRM

CREATE TABLE IF NOT EXISTS companies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(36) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    website VARCHAR(500) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    industry VARCHAR(255) DEFAULT NULL,
    size VARCHAR(50) DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_assigned_to (assigned_to),
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE contacts ADD COLUMN company_id INT NULL;
ALTER TABLE contacts ADD INDEX idx_contacts_company_id (company_id);

ALTER TABLE deals ADD COLUMN company_id INT NULL;
ALTER TABLE deals ADD INDEX idx_deals_company_id (company_id);
