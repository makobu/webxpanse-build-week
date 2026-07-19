<?php
$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}
require_once $root . '/config/constants.php';
$db = require $root . '/config/database.php';
$pdo = new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['pass']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$schema = $db['name'];

echo "communications ENGINE: " . $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$schema' AND TABLE_NAME='communications'")->fetchColumn() . "\n";
echo "emails ENGINE: " . $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$schema' AND TABLE_NAME='emails'")->fetchColumn() . "\n";

echo "FKs on communications:\n";
$fks = $pdo->query("SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '$schema' AND TABLE_NAME='communications' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
print_r($fks);

$orphaned = $pdo->query("SELECT COUNT(*) FROM communications WHERE email_id IS NOT NULL AND email_id NOT IN (SELECT id FROM emails)")->fetchColumn();
echo "Orphaned email_id count: $orphaned\n";
$zero = $pdo->query("SELECT COUNT(*) FROM communications WHERE email_id = 0")->fetchColumn();
echo "email_id=0 count: $zero\n";

$ec = $pdo->query("SELECT COLUMN_TYPE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$schema' AND TABLE_NAME = 'emails' AND COLUMN_NAME = 'id'")->fetch(PDO::FETCH_ASSOC);
$cc = $pdo->query("SELECT COLUMN_TYPE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$schema' AND TABLE_NAME = 'communications' AND COLUMN_NAME = 'email_id'")->fetch(PDO::FETCH_ASSOC);
echo "emails.id: " . json_encode($ec) . "\n";
echo "communications.email_id: " . json_encode($cc) . "\n";
