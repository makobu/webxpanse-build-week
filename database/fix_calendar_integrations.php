<?php
/**
 * One-time fix: Create calendar_integrations table if missing.
 * Uses the same database config as the app.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$config = require __DIR__ . '/../config/database.php';
$dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS calendar_integrations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            provider ENUM('google', 'outlook', 'ical') NOT NULL,
            access_token TEXT NULL,
            refresh_token TEXT NULL,
            token_expires_at TIMESTAMP NULL,
            calendar_id VARCHAR(255) NULL,
            calendar_name VARCHAR(255) NULL,
            sync_enabled TINYINT(1) DEFAULT 1,
            sync_direction ENUM('both', 'to_crm', 'from_crm') DEFAULT 'both',
            last_sync_at TIMESTAMP NULL,
            settings JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_provider (provider),
            INDEX idx_sync_enabled (sync_enabled),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "calendar_integrations table created successfully in database: {$config['name']}\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
