<?php
require_once __DIR__ . '/../vendor/autoload.php';
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}
require_once __DIR__ . '/../config/constants.php';
\CRM\Database::init(require __DIR__ . '/../config/database.php');
$sql = file_get_contents(__DIR__ . '/../database/migrations/078_create_email_assistant_tables.sql');
\CRM\Database::getInstance()->exec($sql);
echo "Migration 078 applied.\n";
