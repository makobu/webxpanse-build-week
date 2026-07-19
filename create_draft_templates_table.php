<?php
/**
 * Create draft_templates table
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

Database::init(require __DIR__ . '/config/database.php');

$sql = file_get_contents(__DIR__ . '/database/migrations/037_create_draft_templates.sql');

// Split by semicolon and execute each statement
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    function($stmt) {
        return !empty($stmt) && strpos($stmt, '--') !== 0;
    }
);

foreach ($statements as $statement) {
    if (!empty($statement)) {
        try {
            Database::getInstance()->exec($statement);
            echo "✓ Executed: " . substr($statement, 0, 60) . "...\n";
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "⚠ Table already exists (skipped)\n";
            } else {
                echo "✗ Error: " . $e->getMessage() . "\n";
            }
        }
    }
}

echo "\n✅ Migration completed!\n";
