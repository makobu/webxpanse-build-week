<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/constants.php';
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v);
}
CRM\Database::init(require __DIR__ . '/../config/database.php');
$row = CRM\Database::queryOne('SHOW CREATE TABLE tasks');
echo ($row['Create Table'] ?? 'n/a') . PHP_EOL;
