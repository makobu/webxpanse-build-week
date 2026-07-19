<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/constants.php';

use CRM\Database;

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

Database::init(require __DIR__ . '/../config/database.php');

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

out('Fixing tasks table IDs + PK + AUTO_INCREMENT...');

$schema = Database::queryOne('SELECT DATABASE() AS db');
out('DB: ' . ($schema['db'] ?? 'unknown'));

$exists = Database::queryOne("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks'");
if ((int)($exists['c'] ?? 0) === 0) {
    throw new RuntimeException('tasks table not found');
}

$bad = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM tasks WHERE id <= 0 OR id IS NULL")['c'] ?? 0);
$maxPos = (int) (Database::queryOne("SELECT COALESCE(MAX(id), 0) AS m FROM tasks WHERE id > 0")['m'] ?? 0);
$nextId = $maxPos + 1;
out("Initial bad IDs: {$bad}, max positive id: {$maxPos}");

while ($bad > 0) {
    $affected = Database::execute(
        "UPDATE tasks
         SET id = ?
         WHERE id <= 0 OR id IS NULL
         ORDER BY created_at ASC, title ASC
         LIMIT 1",
        [$nextId]
    );
    if ($affected <= 0) {
        break;
    }
    $nextId++;
    $bad = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM tasks WHERE id <= 0 OR id IS NULL")['c'] ?? 0);
}
out('Bad IDs remaining after repair: ' . $bad);

$dups = Database::query("SELECT id, COUNT(*) AS c FROM tasks GROUP BY id HAVING COUNT(*) > 1 ORDER BY id ASC");
if (!empty($dups)) {
    out('Resolving duplicate IDs: ' . count($dups));
}
foreach ($dups as $dup) {
    $id = (int) $dup['id'];
    $count = (int) $dup['c'];
    for ($i = 1; $i < $count; $i++) {
        $affected = Database::execute(
            "UPDATE tasks
             SET id = ?
             WHERE id = ?
             ORDER BY created_at ASC, title ASC
             LIMIT 1",
            [$nextId, $id]
        );
        if ($affected <= 0) {
            break;
        }
        $nextId++;
    }
}

$dupCheck = Database::query("SELECT id, COUNT(*) AS c FROM tasks GROUP BY id HAVING COUNT(*) > 1");
out('Duplicate ID rows remaining: ' . count($dupCheck));

$hasPk = (int) (Database::queryOne(
    "SELECT COUNT(*) AS c
     FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'tasks'
       AND CONSTRAINT_TYPE = 'PRIMARY KEY'"
)['c'] ?? 0);
if ($hasPk === 0) {
    out('Adding PRIMARY KEY(id)...');
    Database::execute("ALTER TABLE tasks ADD PRIMARY KEY (id)");
} else {
    out('PRIMARY KEY already present.');
}

$isAuto = (int) (Database::queryOne(
    "SELECT COUNT(*) AS c
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'tasks'
       AND COLUMN_NAME = 'id'
       AND EXTRA LIKE '%auto_increment%'"
)['c'] ?? 0);
if ($isAuto === 0) {
    out('Setting id AUTO_INCREMENT...');
    Database::execute("ALTER TABLE tasks MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT");
} else {
    out('id already AUTO_INCREMENT.');
}

$nextAuto = (int) (Database::queryOne("SELECT COALESCE(MAX(id), 0) + 1 AS n FROM tasks")['n'] ?? 1);
Database::execute("ALTER TABLE tasks AUTO_INCREMENT = {$nextAuto}");
out('AUTO_INCREMENT aligned to: ' . $nextAuto);

$verifyBad = Database::queryOne("SELECT COUNT(*) AS c FROM tasks WHERE id <= 0 OR id IS NULL");
$verifyDup = Database::query("SELECT id, COUNT(*) AS c FROM tasks GROUP BY id HAVING COUNT(*) > 1");
out('Verify bad IDs: ' . (int)($verifyBad['c'] ?? 0));
out('Verify duplicate IDs: ' . count($verifyDup));

$create = Database::queryOne('SHOW CREATE TABLE tasks');
out('Final schema:');
out((string)($create['Create Table'] ?? 'N/A'));

out('tasks table fix complete.');
