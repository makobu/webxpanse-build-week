<?php
/**
 * Ensure database is fully configured with all required tables.
 * Run from project root: php scripts/ensure_database.php
 *
 * 1. Runs all SQL migrations in database/migrations/ (in order).
 * 2. Runs verify_inbox_columns.php if present (adds missing inbox columns).
 * 3. Verifies that critical tables exist.
 */

$root = dirname(__DIR__);

// Load environment
$envFile = $root . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'crm_db';
$dbUser = $_ENV['DB_USER'] ?? 'root';
$dbPass = $_ENV['DB_PASS'] ?? '';

echo "=== Ensure CRM Database ===\n\n";

// 1. Run SQL migrations
$migrateScript = $root . '/database/migrations/migrate.php';
if (!file_exists($migrateScript)) {
    echo "[FAIL] Migration script not found: database/migrations/migrate.php\n";
    exit(1);
}
echo "Running SQL migrations...\n";
passthru('php ' . escapeshellarg($migrateScript), $migrateExit);
if ($migrateExit !== 0) {
    echo "\n[FAIL] Migrations exited with code {$migrateExit}\n";
    exit(1);
}

// 2. Run inbox columns verification (adds missing columns to communications etc.)
$inboxVerify = $root . '/database/migrations/verify_inbox_columns.php';
if (file_exists($inboxVerify)) {
    echo "\nRunning inbox columns verification...\n";
    passthru('php ' . escapeshellarg($inboxVerify), $inboxExit);
    if ($inboxExit !== 0) {
        echo "[WARN] Inbox verify exited with code {$inboxExit} (non-fatal)\n";
    } else {
        echo "Inbox columns OK.\n";
    }
}

// 3. Verify critical tables exist
require_once $root . '/config/constants.php';
$dbConfig = require $root . '/config/database.php';

// Tables required for core CRM and email system
$requiredTables = [
    'users',
    'contacts',
    'activities',
    'emails',
    'email_tracking',
    'email_queue',
    'communications',
    'conversation_threads',
    'migrations',
    'email_templates',
    'email_signatures',
    'deals',
    'tasks',
    'events',
    'notes',
    'workflows',
    'email_fetch_log',
    'email_fetch_log_details',
    'ai_autoresponder_queue',
    'ai_autoresponder_logs',
    'ai_autoresponder_config',
];
// Optional feature tables (reported if missing but do not fail)
$optionalTables = ['draft_templates', 'email_assistant_messages', 'email_assistant_fetch_log', 'sms_messages'];

try {
    $pdo = new PDO(
        'mysql:host=' . $dbConfig['host'] . ';dbname=' . $dbConfig['name'] . ';charset=' . ($dbConfig['charset'] ?? 'utf8mb4'),
        $dbConfig['user'],
        $dbConfig['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    echo "\n[FAIL] Could not connect to database: " . $e->getMessage() . "\n";
    exit(1);
}

$stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($dbConfig['name']));
$existing = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $existing[$row['TABLE_NAME']] = true;
}

$missingRequired = [];
foreach ($requiredTables as $table) {
    if (empty($existing[$table])) {
        $missingRequired[] = $table;
    }
}

$missingOptional = [];
foreach ($optionalTables as $table) {
    if (empty($existing[$table])) {
        $missingOptional[] = $table;
    }
}

if (!empty($missingRequired)) {
    echo "\n[FAIL] Missing required tables: " . implode(', ', $missingRequired) . "\n";
    echo "Run: php database/migrations/migrate.php\n";
    exit(1);
}

echo "\n[OK] All required tables present (" . count($requiredTables) . " checked).\n";
if (!empty($missingOptional)) {
    echo "[INFO] Optional tables not present (OK to ignore): " . implode(', ', $missingOptional) . "\n";
}
echo "Database is fully configured.\n";
exit(0);
