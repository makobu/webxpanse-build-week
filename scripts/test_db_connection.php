<?php
/**
 * Test database connection - run on server to verify config and charset
 * Usage: php scripts/test_db_connection.php
 */

// Same path as login.php
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

$config = require __DIR__ . '/../config/database.php';

echo "=== DB Config ===\n";
echo ".env: " . ($envFile && file_exists($envFile) ? $envFile : 'NOT FOUND') . "\n";
echo "Host: " . ($config['host'] ?? 'null') . "\n";
echo "DB Name: " . ($config['name'] ?? 'null') . "\n";
echo "Charset: " . (isset($config['charset']) ? var_export($config['charset'], true) : 'null') . "\n";
echo "DB_CHARSET from env: " . (isset($_ENV['DB_CHARSET']) ? "'" . $_ENV['DB_CHARSET'] . "'" : 'not set') . "\n\n";

$dsnBase = 'mysql:host=' . $config['host'] . ';dbname=' . $config['name'];
$dsns = [
    'with charset' => $dsnBase . (isset($config['charset']) && $config['charset'] ? ';charset=' . $config['charset'] : ''),
    'latin1' => $dsnBase . ';charset=latin1',
    'utf8' => $dsnBase . ';charset=utf8',
    'no charset' => $dsnBase,
];

foreach ($dsns as $label => $dsn) {
    echo "Trying: $label\n";
    echo "DSN: " . preg_replace('/pass[^;]*/', 'pass=***', $dsn) . "\n";
    try {
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "SUCCESS with $label\n";
        break;
    } catch (PDOException $ex) {
        echo "FAILED: " . $ex->getMessage() . "\n\n";
    }
}
