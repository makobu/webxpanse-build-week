-- Currencies Table
CREATE TABLE IF NOT EXISTS currencies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(3) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    symbol VARCHAR(10) NOT NULL,
    symbol_position ENUM('before', 'after') DEFAULT 'before',
    decimal_places INT DEFAULT 2,
    thousands_separator VARCHAR(1) DEFAULT ',',
    decimal_separator VARCHAR(1) DEFAULT '.',
    is_active TINYINT(1) DEFAULT 1,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_is_active (is_active),
    INDEX idx_is_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert common currencies
INSERT INTO currencies (code, name, symbol, symbol_position, decimal_places, is_default) VALUES
('USD', 'US Dollar', '$', 'before', 2, 1),
('EUR', 'Euro', '€', 'before', 2, 0),
('GBP', 'British Pound', '£', 'before', 2, 0),
('JPY', 'Japanese Yen', '¥', 'before', 0, 0),
('AUD', 'Australian Dollar', 'A$', 'before', 2, 0),
('CAD', 'Canadian Dollar', 'C$', 'before', 2, 0),
('CHF', 'Swiss Franc', 'CHF', 'after', 2, 0),
('CNY', 'Chinese Yuan', '¥', 'before', 2, 0),
('INR', 'Indian Rupee', '₹', 'before', 2, 0),
('SGD', 'Singapore Dollar', 'S$', 'before', 2, 0),
('HKD', 'Hong Kong Dollar', 'HK$', 'before', 2, 0),
('NZD', 'New Zealand Dollar', 'NZ$', 'before', 2, 0),
('MXN', 'Mexican Peso', '$', 'before', 2, 0),
('BRL', 'Brazilian Real', 'R$', 'before', 2, 0),
('ZAR', 'South African Rand', 'R', 'before', 2, 0),
('KRW', 'South Korean Won', '₩', 'before', 0, 0),
('RUB', 'Russian Ruble', '₽', 'after', 2, 0),
('TRY', 'Turkish Lira', '₺', 'before', 2, 0),
('AED', 'UAE Dirham', 'د.إ', 'before', 2, 0),
('SAR', 'Saudi Riyal', '﷼', 'before', 2, 0)
ON DUPLICATE KEY UPDATE name=VALUES(name), symbol=VALUES(symbol);
