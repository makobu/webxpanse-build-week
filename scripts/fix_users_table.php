<?php
/**
 * Repair users table constraints for login reliability.
 *
 * Usage:
 *   php scripts/fix_users_table.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;

Database::init(require __DIR__ . '/../config/database.php');
$pdo = Database::getInstance();

echo "Inspecting users table...\n";

$idCol = Database::queryOne("SHOW COLUMNS FROM users LIKE 'id'");
if (!$idCol) {
    fwrite(STDERR, "ERROR: users.id column not found.\n");
    exit(1);
}

echo "Current id key: " . ($idCol['Key'] ?? '(none)') . "\n";
echo "Current id extra: " . ($idCol['Extra'] ?? '(none)') . "\n";

// Normalize email values in-place (safe, no schema change).
$pdo->exec("UPDATE users SET email = LOWER(TRIM(email)) WHERE email IS NOT NULL AND email <> LOWER(TRIM(email))");
echo "Normalized user emails to lowercase+trim.\n";

// Guardrails: never rewrite ids automatically because many tables reference users.id.
$badIds = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM users WHERE id IS NULL OR id <= 0")['c'] ?? 0);
$dupIds = (int) (Database::queryOne(
    "SELECT COUNT(*) AS c FROM (SELECT id FROM users GROUP BY id HAVING COUNT(*) > 1) t"
)['c'] ?? 0);

if ($badIds > 0 || $dupIds > 0) {
    fwrite(
        STDERR,
        "ERROR: users.id has invalid values (bad={$badIds}, duplicates={$dupIds}). " .
        "Automatic repair is blocked to avoid breaking foreign keys.\n"
    );
    exit(1);
}

// Ensure PRIMARY KEY(id).
$idCol = Database::queryOne("SHOW COLUMNS FROM users LIKE 'id'");
if (($idCol['Key'] ?? '') !== 'PRI') {
    $pdo->exec("ALTER TABLE users ADD PRIMARY KEY (id)");
    echo "Added PRIMARY KEY(id).\n";
}

// Ensure AUTO_INCREMENT on id.
$idCol = Database::queryOne("SHOW COLUMNS FROM users LIKE 'id'");
if (stripos((string) ($idCol['Extra'] ?? ''), 'auto_increment') === false) {
    $pdo->exec("ALTER TABLE users MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
    echo "Enabled AUTO_INCREMENT on users.id.\n";
}

// Index/constraint hygiene.
$indexes = Database::query("SHOW INDEX FROM users");
$keyNames = [];
foreach ($indexes as $idx) {
    $keyNames[$idx['Key_name']] = true;
}

if (!isset($keyNames['uq_users_email'])) {
    $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)");
    echo "Added uq_users_email.\n";
}

if (!isset($keyNames['uq_users_uuid'])) {
    $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uq_users_uuid (uuid)");
    echo "Added uq_users_uuid.\n";
}

if (!isset($keyNames['idx_role'])) {
    $pdo->exec("ALTER TABLE users ADD INDEX idx_role (role)");
    echo "Added idx_role.\n";
}

if (isset($keyNames['idx_users_id'])) {
    try {
        $pdo->exec("ALTER TABLE users DROP INDEX idx_users_id");
        echo "Dropped legacy idx_users_id.\n";
    } catch (\Throwable $e) {
        echo "Skipped dropping idx_users_id: " . $e->getMessage() . "\n";
    }
}

echo "Done.\n";
