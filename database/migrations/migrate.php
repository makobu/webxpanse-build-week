<?php
/**
 * Database Migration Runner
 * 
 * Run all pending migrations in order
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment variables
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = trim($value);
        }
    }
}

$dbHost = getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : ($_ENV['DB_HOST'] ?? 'localhost');
$dbName = getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : ($_ENV['DB_NAME'] ?? 'crm_db');
$dbUser = getenv('DB_USER') !== false ? (string) getenv('DB_USER') : ($_ENV['DB_USER'] ?? 'root');
$dbPass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? '');

/**
 * Split SQL into statements, only on semicolons outside single-quoted strings.
 * Prevents breaking INSERT/UPDATE values that contain ; (e.g. CSS).
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $inSingleQuote = false;
    $i = 0;
    while ($i < $len) {
        $c = $sql[$i];
        if ($inSingleQuote) {
            $current .= $c;
            if ($c === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") {
                    $current .= "'";
                    $i++;
                } else {
                    $inSingleQuote = false;
                }
            }
            $i++;
            continue;
        }
        if ($c === "'") {
            $current .= $c;
            $inSingleQuote = true;
            $i++;
            continue;
        }
        if ($c === ';') {
            $stmt = trim($current);
            if ($stmt !== '') {
                $cleaned = [];
                foreach (preg_split('/\R/', $stmt) as $line) {
                    $trimmed = ltrim($line);
                    if ($trimmed === '' || strpos($trimmed, '--') === 0) {
                        continue;
                    }
                    $cleaned[] = $line;
                }
                $stmt = trim(implode("\n", $cleaned));
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
            }
            $current = '';
            $i++;
            continue;
        }
        $current .= $c;
        $i++;
    }
    $stmt = trim($current);
    if ($stmt !== '') {
        $cleaned = [];
        foreach (preg_split('/\R/', $stmt) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0) {
                continue;
            }
            $cleaned[] = $line;
        }
        $stmt = trim(implode("\n", $cleaned));
        if ($stmt !== '') {
            $statements[] = $stmt;
        }
    }
    return $statements;
}

/**
 * Execute SQL while consuming result sets from procedural statements like
 * EXECUTE, SHOW, or SELECT so later statements do not fail with active cursor
 * errors.
 */
function executeMigrationStatement(PDO $pdo, string $statement): void
{
    $statement = \CRM\MigrationStatementNormalizer::normalize($pdo, $statement);
    $trimmed = ltrim($statement);
    if ($trimmed === '') {
        return;
    }

    $command = strtoupper(strtok($trimmed, " \t\r\n(") ?: '');
    $returnsRows = in_array($command, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'EXECUTE', 'CALL'], true);

    if ($returnsRows) {
        $stmt = $pdo->query($statement);
        if ($stmt instanceof PDOStatement) {
            do {
                $stmt->fetchAll(PDO::FETCH_ASSOC);
            } while ($stmt->nextRowset());
            $stmt->closeCursor();
        }
        return;
    }

    $pdo->exec($statement);
}

try {
    // Connect to MySQL (without database)
    $pdo = new PDO(
        "mysql:host={$dbHost};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
        ]
    );
    
    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    // Migration DDL is not safely concurrent. Serialize runners at the database
    // boundary so cron, deployment hooks, or two operators cannot apply the same
    // migration at once.
    $migrationLockName = 'crm_migrations:' . substr(hash('sha256', $dbName), 0, 40);
    $lockStatement = $pdo->prepare('SELECT GET_LOCK(?, 30) AS acquired');
    $lockStatement->execute([$migrationLockName]);
    $lockResult = $lockStatement->fetch(PDO::FETCH_ASSOC);
    if ((int) ($lockResult['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Could not acquire the migration lock within 30 seconds.');
    }

    // Never allow a malformed legacy table to silently accept id=0 or truncated
    // values while migrations are executing.
    $pdo->exec(
        "SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, " .
        "'STRICT_TRANS_TABLES', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION')"
    );
    
    // Create migrations tracking table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            migration_name VARCHAR(255) UNIQUE NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Get all migration files (001_*.sql through 099_*.sql and any 100+)
    $migrationDir = __DIR__;
    $files = glob($migrationDir . '/*.sql');
    $files = $files ?: [];
    natsort($files);
    $files = array_values($files);
    
    $executed = [];
    $stmt = $pdo->query("SELECT migration_name FROM migrations");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $executed[] = $row['migration_name'];
    }
    
    echo "Running migrations...\n";
    
    foreach ($files as $file) {
        $migrationName = basename($file);
        
        if (in_array($migrationName, $executed)) {
            echo "Skipping {$migrationName} (already executed)\n";
            continue;
        }
        
        echo "Executing {$migrationName}...\n";
        
        $sql = file_get_contents($file);
        
        // Split into statements (respect semicolons inside quoted strings)
        $statements = splitSqlStatements($sql);
        
        try {
            // DDL statements (CREATE TABLE, etc.) auto-commit in MySQL
            // So we don't use transactions for migrations
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    try {
                        executeMigrationStatement($pdo, $statement);
                    } catch (PDOException $e) {
                        // Ignore "duplicate" errors for ALTER TABLE and CREATE INDEX
                        $errorCode = $e->getCode();
                        $errorMsg = $e->getMessage();
                        $normalizedStatement = ltrim($statement);
                        $isAlterTable = stripos($normalizedStatement, 'ALTER TABLE') === 0;
                        if (strpos($errorMsg, 'Duplicate column name') !== false ||
                            strpos($errorMsg, 'Duplicate key name') !== false ||
                            strpos($errorMsg, 'already exists') !== false ||
                            (strpos($errorMsg, "Can't DROP") !== false && strpos($errorMsg, 'check that') !== false && strpos($errorMsg, 'exists') !== false)) {
                            // Column/index already exists or the old index was already dropped, which is fine.
                            continue;
                        }
                        // Some installations may not include optional module tables yet (e.g. sms_messages).
                        // Keep migration forward-compatible by skipping ALTER TABLE on missing targets.
                        if ($isAlterTable && strpos($errorMsg, 'Base table or view not found') !== false) {
                            continue;
                        }
                        throw $e;
                    }
                }
            }
            
            // Record migration
            $stmt = $pdo->prepare("INSERT INTO migrations (migration_name) VALUES (?)");
            $stmt->execute([$migrationName]);

            if ($migrationName === '423_workspace_sms_channel_configs.sql') {
                try {
                    \CRM\Database::init([
                        'host' => $dbHost,
                        'name' => $dbName,
                        'user' => $dbUser,
                        'pass' => $dbPass,
                        'charset' => 'utf8mb4',
                    ]);
                    $imported = (new \CRM\Services\WorkspaceSmsChannelConfigService())->importLegacyEnvForInstalledWorkspaces();
                    if ($imported > 0) {
                        echo "  Backfilled {$imported} SMS workspace config(s) from legacy Twilio env values\n";
                    }
                } catch (Throwable $e) {
                    echo "  Warning: SMS workspace config env backfill skipped: " . $e->getMessage() . "\n";
                }
            }
            
            echo "✓ {$migrationName} completed\n";
        } catch (Exception $e) {
            echo "✗ Error in {$migrationName}: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $releaseStatement->execute([$migrationLockName]);
    
    echo "\nAll migrations completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}
