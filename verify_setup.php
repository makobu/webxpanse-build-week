<?php

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$diagnosticsEnabled = ($_ENV['APP_ENV'] ?? 'production') === 'development'
    || filter_var($_ENV['ENABLE_PUBLIC_DIAGNOSTICS'] ?? false, FILTER_VALIDATE_BOOL);

if (!$isCli && (!$isLocalRequest || !$diagnosticsEnabled)) {
    http_response_code(404);
    echo "Not found\n";
    exit;
}

echo "Verifying CRM Setup...\n\n";

try {
    $dbConfig = require __DIR__ . '/config/database.php';
    $dsn = 'mysql:host=' . $dbConfig['host'] . ';dbname=' . $dbConfig['name'];
    if (!empty($dbConfig['charset'])) {
        $dsn .= ';charset=' . $dbConfig['charset'];
    }

    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], $dbConfig['options'] ?? []);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $result = $pdo->query('SHOW TABLES');
    $tables = [];
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    echo "OK Database connection successful!\n";
    echo "OK Total tables: " . count($tables) . "\n\n";

    $keyTables = ['users', 'contacts', 'activities', 'emails', 'communications'];
    echo "Key tables status:\n";
    foreach ($keyTables as $table) {
        if (in_array($table, $tables, true)) {
            echo "  OK $table\n";
        } else {
            echo "  MISSING $table\n";
        }
    }

    echo "\nOK Setup verification complete!\n";
} catch (Exception $e) {
    echo "ERROR Database connection failed. Check configured DB_HOST, DB_NAME, DB_USER, DB_PASS, and DB_CHARSET.\n";
    if ($isCli || $diagnosticsEnabled) {
        echo "Diagnostic detail: " . $e->getMessage() . "\n";
    }
    exit(1);
}
