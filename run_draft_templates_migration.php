<?php
/**
 * Quick migration runner for draft_templates table
 */

require_once __DIR__ . '/vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/config/constants.php';
use CRM\Database;

try {
    Database::init(require __DIR__ . '/config/database.php');
    
    $sql = file_get_contents(__DIR__ . '/database/migrations/037_create_draft_templates.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && strpos($stmt, '--') !== 0 && strlen(trim($stmt)) > 10;
        }
    );
    
    $pdo = Database::getInstance();
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;
        
        try {
            $pdo->exec($statement);
            echo "✓ Executed successfully\n";
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "⚠ Table already exists (skipped)\n";
            } else {
                echo "✗ Error: " . $e->getMessage() . "\n";
                echo "Statement: " . substr($statement, 0, 100) . "...\n";
            }
        }
    }
    
    echo "\n✅ Migration completed!\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
