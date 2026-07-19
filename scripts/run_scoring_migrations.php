<?php
/**
 * Run Scoring System Database Migrations
 * Executes migrations 052 and 053 to add AI context and scoring fields
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
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

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;

$dbConfig = require __DIR__ . '/../config/database.php';
Database::init($dbConfig);

echo "Running Scoring System Database Migrations...\n\n";

$migrations = [
    '052_add_ai_context_to_contacts.sql' => __DIR__ . '/../database/migrations/052_add_ai_context_to_contacts.sql',
    '053_add_scoring_fields.sql' => __DIR__ . '/../database/migrations/053_add_scoring_fields.sql'
];

$errors = [];
$success = [];

foreach ($migrations as $migrationName => $migrationPath) {
    echo "Running migration: {$migrationName}\n";
    
    if (!file_exists($migrationPath)) {
        $errors[] = "Migration file not found: {$migrationPath}";
        echo "  ❌ File not found\n\n";
        continue;
    }
    
    $sql = file_get_contents($migrationPath);
    
    // Remove comments
    $sql = preg_replace('/--.*$/m', '', $sql);
    
    // Split SQL into individual statements, preserving order
    $statements = [];
    $currentStatement = '';
    $lines = explode("\n", $sql);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $currentStatement .= $line . "\n";
        
        // If line ends with semicolon, it's a complete statement
        if (substr(rtrim($line), -1) === ';') {
            $stmt = trim($currentStatement);
            if (!empty($stmt)) {
                $statements[] = $stmt;
            }
            $currentStatement = '';
        }
    }
    
    // Add any remaining statement
    if (!empty(trim($currentStatement))) {
        $statements[] = trim($currentStatement);
    }
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            // Handle CREATE INDEX which might fail if index already exists
            if (stripos($statement, 'CREATE INDEX') !== false) {
                // Extract index name and table
                if (preg_match('/CREATE\s+INDEX\s+(\w+)\s+ON\s+(\w+)/i', $statement, $matches)) {
                    $indexName = $matches[1];
                    $tableName = $matches[2];
                    
                    // Check if index exists
                    $indexExists = Database::queryOne(
                        "SELECT COUNT(*) as count FROM information_schema.STATISTICS 
                         WHERE TABLE_SCHEMA = DATABASE() 
                         AND TABLE_NAME = ? 
                         AND INDEX_NAME = ?",
                        [$tableName, $indexName]
                    );
                    
                    if (!empty($indexExists['count'])) {
                        echo "  ⚠ Index '{$indexName}' already exists, skipping...\n";
                        continue;
                    }
                }
            }
            
            // Handle ALTER TABLE MODIFY which might fail if column doesn't exist
            if (stripos($statement, 'ALTER TABLE') !== false && stripos($statement, 'MODIFY') !== false) {
                // Try to execute, but don't fail if column doesn't exist yet
                try {
                    Database::execute($statement);
                    echo "  ✓ Executed: " . substr($statement, 0, 60) . "...\n";
                } catch (\Exception $e) {
                    if (strpos($e->getMessage(), 'Unknown column') !== false) {
                        echo "  ⚠ Column doesn't exist yet, will be created by ADD COLUMN statement\n";
                    } else {
                        throw $e;
                    }
                }
            } else {
                Database::execute($statement);
                echo "  ✓ Executed: " . substr($statement, 0, 60) . "...\n";
            }
        } catch (\Exception $e) {
            // Check if error is because column/index already exists
            if (strpos($e->getMessage(), 'Duplicate column name') !== false || 
                strpos($e->getMessage(), 'Duplicate key name') !== false ||
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "  ⚠ Already exists: " . substr($e->getMessage(), 0, 80) . "...\n";
            } elseif (strpos($e->getMessage(), 'JSON') !== false && 
                      (strpos($e->getMessage(), 'syntax') !== false || strpos($e->getMessage(), 'CAST') !== false)) {
                // JSON index syntax error (MariaDB compatibility issue) - non-critical
                echo "  ⚠ JSON index syntax not supported (non-critical, skipping): " . substr($e->getMessage(), 0, 80) . "...\n";
            } else {
                $errors[] = "Error in {$migrationName}: " . $e->getMessage();
                echo "  ❌ Error: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n";
}

// Summary
echo "========================================\n";
echo "Migration Summary\n";
echo "========================================\n";

if (empty($errors)) {
    echo "✅ All migrations completed successfully!\n\n";
    echo "Verifying migrations...\n";
    // Run verification script
    exec('php ' . escapeshellarg(__DIR__ . '/verify_scoring_migrations.php'), $output, $returnCode);
    echo implode("\n", $output) . "\n";
    exit($returnCode);
} else {
    echo "❌ Some errors occurred:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
    exit(1);
}
