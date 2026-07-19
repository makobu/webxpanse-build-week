<?php
/**
 * Verify Email System Setup (Phase 1)
 * Run from project root: php scripts/verify_email_setup.php
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

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

require_once $root . '/config/constants.php';

$dbConfig = require $root . '/config/database.php';
$errors = [];
$ok = [];

// DB connection
try {
    $pdo = new PDO(
        'mysql:host=' . $dbConfig['host'] . ';dbname=' . $dbConfig['name'] . ';charset=' . ($dbConfig['charset'] ?? 'utf8mb4'),
        $dbConfig['user'],
        $dbConfig['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $ok[] = 'Database connection OK';
} catch (Exception $e) {
    $errors[] = 'Database: ' . $e->getMessage();
    // Can't continue without DB
    echo implode("\n", array_merge($errors, $ok));
    exit(1);
}

// Required tables
$tables = ['emails', 'email_queue', 'communications', 'contacts'];
foreach ($tables as $table) {
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        $ok[] = "Table `$table` exists";
    } catch (Exception $e) {
        $errors[] = "Table `$table`: " . $e->getMessage();
    }
}

// communications.uuid should be CHAR(36)
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM communications WHERE Field = 'uuid'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($col && (stripos($col['Type'], 'char(36)') !== false || stripos($col['Type'], 'varchar(36)') !== false)) {
        $ok[] = "communications.uuid is 36-char compatible";
    } else {
        $errors[] = "communications.uuid should be CHAR(36) or VARCHAR(36), got: " . ($col['Type'] ?? 'missing');
    }
} catch (Exception $e) {
    $errors[] = "communications.uuid check: " . $e->getMessage();
}

// PHP IMAP
if (function_exists('imap_open')) {
    $ok[] = 'PHP IMAP extension loaded';
} else {
    $errors[] = 'PHP IMAP extension not loaded (required for inbound email)';
}

// Env hints (do not print values)
$envVars = ['DB_HOST', 'DB_NAME', 'SMTP_HOST', 'SMTP_FROM_EMAIL', 'IMAP_ENABLED', 'APP_URL'];
foreach ($envVars as $v) {
    if (!empty($_ENV[$v])) {
        $ok[] = "Env $v is set";
    } else {
        $errors[] = "Env $v is missing or empty";
    }
}

echo "--- Email setup verification ---\n";
foreach ($errors as $line) {
    echo "[FAIL] " . $line . "\n";
}
foreach ($ok as $line) {
    echo "[OK] " . $line . "\n";
}
echo count($errors) ? "\nFix the [FAIL] items and re-run.\n" : "\nAll checks passed.\n";
exit(count($errors) > 0 ? 1 : 0);
