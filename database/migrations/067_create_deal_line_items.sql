-- Deal Line Items and Quotes
-- Migration 067: Add line items for deals

CREATE TABLE IF NOT EXISTS deal_line_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    deal_id INT NOT NULL,
    product_id INT NULL,
    description VARCHAR(500) DEFAULT NULL,
    quantity DECIMAL(12,4) DEFAULT 1,
    unit_price DECIMAL(12,2) DEFAULT 0,
    discount_percent DECIMAL(5,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_deal_id (deal_id),
    INDEX idx_product_id (product_id),
    FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE products ADD COLUMN unit_price DECIMAL(12,2) NULL;
ALTER TABLE deals ADD COLUMN quote_number VARCHAR(50) NULL;
ALTER TABLE deals ADD COLUMN quote_valid_until DATE NULL;
