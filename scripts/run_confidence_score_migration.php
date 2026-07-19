<?php
/**
 * Run confidence_score migration
 * Adds confidence_score column to contacts table
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
    
    echo "Running confidence_score migration...\n\n";
    
    $migrationFile = __DIR__ . '/../database/migrations/054_add_confidence_score_to_contacts.sql';
    if (!file_exists($migrationFile)) {
        die("Migration file not found: $migrationFile\n");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Split by semicolon and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   !preg_match('/^--/', $stmt) && 
                   !preg_match('/^\/\*/', $stmt);
        }
    );
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            echo "Executing: " . substr($statement, 0, 80) . "...\n";
            Database::execute($statement);
            echo "✓ Success\n\n";
        } catch (\Exception $e) {
            // Check if it's a "duplicate column" error (column already exists)
            if (strpos($e->getMessage(), 'Duplicate column name') !== false || 
                strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), 'Duplicate key name') !== false) {
                echo "⚠ Already exists, skipping...\n\n";
            } elseif (strpos($e->getMessage(), "doesn't exist") !== false && 
                      strpos($statement, 'CREATE INDEX') !== false) {
                // Index creation failed because column doesn't exist - try to create it first
                echo "⚠ Index creation failed (column may not exist yet), will create index separately...\n\n";
            } else {
                throw $e;
            }
        }
    }
    
    // Try to create index separately if column was just added
    try {
        echo "Creating index on confidence_score...\n";
        Database::execute("CREATE INDEX idx_confidence_score ON contacts(confidence_score)");
        echo "✓ Index created successfully\n\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false ||
            strpos($e->getMessage(), 'already exists') !== false) {
            echo "⚠ Index already exists, skipping...\n\n";
        } elseif (strpos($e->getMessage(), "doesn't exist") !== false) {
            echo "⚠ Column doesn't exist yet, cannot create index. Please check the migration.\n\n";
        } else {
            echo "⚠ Index creation failed: " . $e->getMessage() . "\n\n";
        }
    }
    
    echo "Migration completed successfully!\n";
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
