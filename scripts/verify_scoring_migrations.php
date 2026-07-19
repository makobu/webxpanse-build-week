<?php
/**
 * Verify Scoring System Database Migrations
 * Checks if all required columns exist for the three-score system and AI context
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

echo "Verifying Scoring System Database Migrations...\n\n";

$errors = [];
$warnings = [];
$success = [];

// Check contacts table columns
$requiredColumns = [
    'contacts' => [
        'ai_context' => 'JSON',
        'engagement_score' => 'INT',
        'ai_score' => 'INT',
        'score_weights' => 'JSON',
        'recommended_weights' => 'JSON',
        'score_recalculated_at' => 'TIMESTAMP',
        'score_metadata_json' => 'JSON',
    ],
    'enrichment_config' => [
        'default_score_weights' => 'JSON'
    ],
    'workspace_scoring_config' => [
        'workspace_id' => 'INT',
        'default_score_weights' => 'JSON',
        'auto_use_recommended_weights' => 'TINYINT',
    ],
];

foreach ($requiredColumns as $table => $columns) {
    echo "Checking table: {$table}\n";
    
    // Check if table exists
    $tableExists = Database::queryOne(
        "SELECT COUNT(*) as count FROM information_schema.tables 
         WHERE table_schema = DATABASE() AND table_name = ?",
        [$table]
    );
    
    if (empty($tableExists['count'])) {
        $errors[] = "Table '{$table}' does not exist";
        echo "  ❌ Table does not exist\n";
        continue;
    }
    
    echo "  ✓ Table exists\n";
    
    // Check each column
    foreach ($columns as $column => $expectedType) {
        $columnInfo = Database::queryOne(
            "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE 
             FROM information_schema.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = ? 
             AND COLUMN_NAME = ?",
            [$table, $column]
        );
        
        if (empty($columnInfo)) {
            $errors[] = "Column '{$table}.{$column}' does not exist";
            echo "  ❌ Column '{$column}' missing\n";
        } else {
            $actualType = strtoupper($columnInfo['DATA_TYPE']);
            $expectedTypeUpper = strtoupper($expectedType);
            
            // Check type compatibility
            $typeMatch = false;
            if ($expectedTypeUpper === 'JSON') {
                $typeMatch = ($actualType === 'JSON' || $actualType === 'LONGTEXT' || $actualType === 'TEXT');
            } elseif ($expectedTypeUpper === 'INT') {
                $typeMatch = ($actualType === 'INT' || $actualType === 'INTEGER' || $actualType === 'TINYINT' || $actualType === 'SMALLINT' || $actualType === 'MEDIUMINT' || $actualType === 'BIGINT');
            } elseif ($expectedTypeUpper === 'TINYINT') {
                $typeMatch = ($actualType === 'TINYINT' || $actualType === 'BOOLEAN' || $actualType === 'BOOL');
            } elseif ($expectedTypeUpper === 'TIMESTAMP') {
                $typeMatch = ($actualType === 'TIMESTAMP' || $actualType === 'DATETIME');
            }
            
            if ($typeMatch) {
                $success[] = "Column '{$table}.{$column}' exists with compatible type";
                echo "  ✓ Column '{$column}' exists ({$columnInfo['COLUMN_TYPE']})\n";
            } else {
                $warnings[] = "Column '{$table}.{$column}' exists but type may be incompatible (expected {$expectedType}, got {$actualType})";
                echo "  ⚠ Column '{$column}' exists but type may be incompatible ({$columnInfo['COLUMN_TYPE']})\n";
            }
        }
    }
    
    echo "\n";
}

// Summary
echo "========================================\n";
echo "Verification Summary\n";
echo "========================================\n";
echo "✓ Success: " . count($success) . "\n";
echo "⚠ Warnings: " . count($warnings) . "\n";
echo "❌ Errors: " . count($errors) . "\n\n";

if (!empty($warnings)) {
    echo "Warnings:\n";
    foreach ($warnings as $warning) {
        echo "  - {$warning}\n";
    }
    echo "\n";
}

if (!empty($errors)) {
    echo "Errors (must be fixed):\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
    echo "To fix these errors, run the following migrations:\n";
    echo "  - database/migrations/052_add_ai_context_to_contacts.sql\n";
    echo "  - database/migrations/053_add_scoring_fields.sql\n";
    echo "  - database/migrations/438_workspace_scoring_config.sql\n";
    echo "\n";
    exit(1);
}

if (empty($errors) && empty($warnings)) {
    echo "✅ All migrations verified successfully!\n";
    exit(0);
} else {
    echo "⚠ Some warnings were found, but no critical errors.\n";
    exit(0);
}
