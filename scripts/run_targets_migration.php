<?php
/**
 * Run Targets Migration
 * Creates the targets, target_reminders, and target_advice tables
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

try {
    Database::init(require __DIR__ . '/../config/database.php');
    
    echo "=== Running Targets Migration ===\n\n";
    
    $sql = file_get_contents(__DIR__ . '/../database/migrations/055_create_targets_tables.sql');
    
    if (empty($sql)) {
        die("❌ Migration file is empty\n");
    }
    
    // Remove comments and split by semicolon
    $lines = explode("\n", $sql);
    $cleanedSql = '';
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comment lines
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }
        $cleanedSql .= $line . "\n";
    }
    
    // Split by semicolon and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $cleanedSql)),
        function($stmt) {
            $stmt = trim($stmt);
            return !empty($stmt) && strlen($stmt) > 20; // Minimum length for a valid CREATE TABLE
        }
    );
    
    $statementCount = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;
        
        $statementCount++;
        try {
            Database::execute($statement);
            // Extract table name from CREATE TABLE statement for better feedback
            if (preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $statement, $matches)) {
                echo "✓ Created table: {$matches[1]}\n";
            } else {
                echo "✓ Executed statement #{$statementCount}\n";
            }
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "⚠ Table/column already exists (skipped)\n";
            } else {
                echo "✗ Error: " . $e->getMessage() . "\n";
                throw $e;
            }
        }
    }
    
    if ($statementCount === 0) {
        echo "⚠ No statements found to execute. This might mean the tables already exist.\n";
    }
    
    echo "\n✅ Targets migration completed successfully!\n";
    echo "\nTables created:\n";
    echo "  - targets\n";
    echo "  - target_reminders\n";
    echo "  - target_advice\n";
    
} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
