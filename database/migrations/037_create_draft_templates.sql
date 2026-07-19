-- Create draft_templates table for AI-generated draft templates
CREATE TABLE IF NOT EXISTS draft_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL, -- 'email' or 'whatsapp'
    purpose VARCHAR(100) NOT NULL, -- 'welcome', 'follow_up', 'proposal', etc.
    tone VARCHAR(50) DEFAULT 'professional', -- 'professional', 'friendly', 'casual', etc.
    subject VARCHAR(500) NULL, -- For email templates
    body TEXT NOT NULL,
    variables JSON NULL, -- Available variables for personalization
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_type (type),
    INDEX idx_purpose (purpose),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create draft_reviews table for draft review and editing
CREATE TABLE IF NOT EXISTS draft_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    draft_type VARCHAR(50) NOT NULL, -- 'email' or 'whatsapp'
    contact_id INT NULL,
    subject VARCHAR(500) NULL,
    body TEXT NOT NULL,
    original_body TEXT NULL, -- Original AI-generated draft
    tone VARCHAR(50) DEFAULT 'professional',
    status VARCHAR(50) DEFAULT 'draft', -- 'draft', 'reviewed', 'approved', 'sent'
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_contact_id (contact_id),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
