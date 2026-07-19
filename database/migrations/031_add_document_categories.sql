-- Document Categories Table
CREATE TABLE IF NOT EXISTS document_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    color VARCHAR(7) DEFAULT '#3B82F6',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_created_by (created_by),
    UNIQUE KEY unique_name (name),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add category_id to documents table
ALTER TABLE documents 
ADD COLUMN category_id INT NULL AFTER entity_id,
ADD INDEX idx_category_id (category_id),
ADD FOREIGN KEY (category_id) REFERENCES document_categories(id) ON DELETE SET NULL;
