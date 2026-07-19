<?php
/**
 * Safe Migration Runner
 * Runs SQL migrations and handles existing tables gracefully
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

function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $inSingleQuote = false;

    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];

        if ($inSingleQuote) {
            $current .= $char;
            if ($char === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") {
                    $current .= "'";
                    $i++;
                } else {
                    $inSingleQuote = false;
                }
            }
            continue;
        }

        if ($char === "'") {
            $current .= $char;
            $inSingleQuote = true;
            continue;
        }

        if ($char === ';') {
            $statement = cleanSqlStatement($current);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $statement = cleanSqlStatement($current);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

function cleanSqlStatement(string $statement): string
{
    $statement = trim($statement);
    if ($statement === '') {
        return '';
    }

    $cleaned = [];
    foreach (preg_split('/\R/', $statement) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) {
            continue;
        }
        $cleaned[] = $line;
    }

    return trim(implode("\n", $cleaned));
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;

Database::init(require __DIR__ . '/../config/database.php');

echo "=== Safe Migration Runner ===\n\n";

// Get migration directory
$migrationsDir = __DIR__ . '/../database/migrations';
if (!is_dir($migrationsDir)) {
    die("❌ Migrations directory not found: {$migrationsDir}\n");
}

// Get all SQL migration files
$migrationFiles = glob($migrationsDir . '/*.sql');
sort($migrationFiles);

if (empty($migrationFiles)) {
    die("❌ No migration files found in {$migrationsDir}\n");
}

echo "Found " . count($migrationFiles) . " migration files.\n\n";

// Ask which migration to run
if ($argc > 1) {
    $targetFile = $argv[1];
    if (!file_exists($targetFile)) {
        die("❌ File not found: {$targetFile}\n");
    }
    $migrationFiles = [$targetFile];
} else {
    echo "Running all migrations...\n\n";
}

$successCount = 0;
$skipCount = 0;
$errorCount = 0;

foreach ($migrationFiles as $file) {
    $fileName = basename($file);
    echo "Processing: {$fileName}...\n";
    
    $sql = file_get_contents($file);
    
    if (empty($sql)) {
        echo "  ⚠️  File is empty, skipping.\n\n";
        $skipCount++;
        continue;
    }
    
    $statements = splitSqlStatements($sql);
    
    foreach ($statements as $statement) {
        if (empty($statement)) continue;
        
        // Convert CREATE TABLE to CREATE TABLE IF NOT EXISTS unless the
        // migration is already idempotent.
        $statement = preg_replace(
            '/^CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\b)`?(\w+)`?/i',
            'CREATE TABLE IF NOT EXISTS `$1`',
            $statement
        );
        
        // Convert ALTER TABLE ADD COLUMN to check if column exists first
        // (This is more complex, so we'll just try-catch it)
        
        try {
            Database::execute($statement);
            echo "  ✓ Executed statement\n";
            $successCount++;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            // Check if it's a "table already exists" error
            if (strpos($errorMessage, 'already exists') !== false) {
                echo "  ⚠️  Table already exists, skipping.\n";
                $skipCount++;
            }
            // Check if it's a "duplicate column" error
            elseif (strpos($errorMessage, 'Duplicate column') !== false) {
                echo "  ⚠️  Column already exists, skipping.\n";
                $skipCount++;
            }
            // Check if it's a "duplicate key" error
            elseif (strpos($errorMessage, 'Duplicate key') !== false) {
                echo "  ⚠️  Key already exists, skipping.\n";
                $skipCount++;
            }
            else {
                echo "  ❌ Error: {$errorMessage}\n";
                $errorCount++;
            }
        }
    }
    
    echo "\n";
}

echo "=== Summary ===\n";
echo "Successfully executed: {$successCount}\n";
echo "Skipped (already exists): {$skipCount}\n";
echo "Errors: {$errorCount}\n\n";

if ($errorCount === 0) {
    echo "✅ Migration completed successfully!\n";
} else {
    echo "⚠️  Some errors occurred. Check the output above.\n";
}
