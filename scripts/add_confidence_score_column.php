<?php
/**
 * Add confidence_score column to contacts table
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || 
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;

try {
    $dbConfig = require __DIR__ . '/../config/database.php';
    Database::init($dbConfig);
    
    echo "Adding confidence_score column to contacts table...\n\n";
    
    // Add the column
    try {
        Database::execute(
            "ALTER TABLE contacts ADD COLUMN confidence_score INT NULL COMMENT 'Email verification confidence score from Hunter.io (0-100)'"
        );
        echo "✓ Column added successfully\n\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "⚠ Column already exists\n\n";
        } else {
            throw $e;
        }
    }
    
    // Create the index
    try {
        Database::execute("CREATE INDEX idx_confidence_score ON contacts(confidence_score)");
        echo "✓ Index created successfully\n\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "⚠ Index already exists\n\n";
        } else {
            echo "⚠ Index creation failed: " . $e->getMessage() . "\n\n";
        }
    }
    
    echo "Migration completed!\n";
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
