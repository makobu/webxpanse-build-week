<?php
/**
 * Database Configuration
 */

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string) $line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || getenv($key) !== false || array_key_exists($key, $_ENV)) {
            continue;
        }

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

$strictModeValue = getenv('DB_STRICT_MODE');
if ($strictModeValue === false) {
    $strictModeValue = $_ENV['DB_STRICT_MODE'] ?? 'true';
}
$strictModeEnabled = filter_var($strictModeValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => false,
];
if ($strictModeEnabled && defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
    $pdoOptions[PDO::MYSQL_ATTR_INIT_COMMAND] =
        "SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, " .
        "'STRICT_TRANS_TABLES', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION')";
}

return [
    'host' => getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : ($_ENV['DB_HOST'] ?? 'localhost'),
    'name' => getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : ($_ENV['DB_NAME'] ?? 'crm_db'),
    'user' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : ($_ENV['DB_USER'] ?? 'root'),
    'pass' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? ''),
    // Omit charset by default for compatibility with older MySQL. Set DB_CHARSET=utf8mb4 in .env for full Unicode (emoji).
    'charset' => trim(getenv('DB_CHARSET') !== false ? (string) getenv('DB_CHARSET') : ($_ENV['DB_CHARSET'] ?? '')) ?: null,
    'options' => $pdoOptions,
    'strict_mode' => $strictModeEnabled,
];
