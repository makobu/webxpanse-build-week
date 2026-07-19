<?php
/**
 * Drop orphaned PHPUnit databases created by CRM\DatabaseTestCase.
 *
 * By default this script is a dry run. Pass --drop to delete databases whose
 * names match the generated test patterns and have no active MySQL connection.
 * Pass --limit=N to cap the number of dropped databases in one run.
 */

$rootDir = dirname(__DIR__);

loadEnv($rootDir . '/.env.testing', false);
loadEnv($rootDir . '/.env', false);

$drop = in_array('--drop', $argv, true);
$limit = cliLimit($argv);
$host = envValue('DB_HOST', 'localhost');
$user = envValue('DB_USER', 'root');
$pass = envValue('DB_PASS', '');

try {
    $pdo = new PDO(
        'mysql:host=' . $host . ';charset=utf8mb4',
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to connect to MySQL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$activeDatabases = [];
try {
    $rows = $pdo->query(
        "SELECT DISTINCT DB
         FROM information_schema.PROCESSLIST
         WHERE DB IS NOT NULL AND DB <> ''"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($rows as $databaseName) {
        $activeDatabases[(string) $databaseName] = true;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to inspect active MySQL connections: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$databases = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
$candidates = array_values(array_filter(array_map('strval', $databases), 'isGeneratedTestDatabaseName'));
sort($candidates, SORT_NATURAL);

$dropped = [];
$skipped = [];
$failed = [];

foreach ($candidates as $databaseName) {
    if ($drop && $limit !== null && count($dropped) >= $limit) {
        break;
    }

    if (isset($activeDatabases[$databaseName])) {
        $skipped[] = $databaseName;
        continue;
    }

    if ($drop) {
        try {
            $pdo->exec('DROP DATABASE IF EXISTS `' . quoteIdentifier($databaseName) . '`');
            $dropped[] = $databaseName;
        } catch (Throwable $e) {
            $failed[$databaseName] = $e->getMessage();
        }
    }
}

if ($drop) {
    echo 'Dropped ' . count($dropped) . ' unused test database(s).' . PHP_EOL;
    foreach ($dropped as $databaseName) {
        echo '  - ' . $databaseName . PHP_EOL;
    }
    if ($limit !== null && count($dropped) >= $limit) {
        echo 'Limit reached. Run again to continue cleanup.' . PHP_EOL;
    }
} else {
    $available = array_values(array_diff($candidates, $skipped));
    if ($limit !== null) {
        $available = array_slice($available, 0, $limit);
    }
    echo 'Dry run: ' . count($available) . ' unused test database(s) can be dropped.' . PHP_EOL;
    foreach ($available as $databaseName) {
        echo '  - ' . $databaseName . PHP_EOL;
    }
    echo 'Run with --drop to delete them.' . PHP_EOL;
}

if ($skipped !== []) {
    echo 'Skipped ' . count($skipped) . ' active test database(s).' . PHP_EOL;
    foreach ($skipped as $databaseName) {
        echo '  - ' . $databaseName . PHP_EOL;
    }
}

if ($failed !== []) {
    echo 'Failed to drop ' . count($failed) . ' test database(s).' . PHP_EOL;
    foreach ($failed as $databaseName => $message) {
        echo '  - ' . $databaseName . ': ' . $message . PHP_EOL;
    }
    exit(2);
}

function loadEnv(string $path, bool $overwrite): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '' || (!$overwrite && (getenv($key) !== false || array_key_exists($key, $_ENV)))) {
            continue;
        }

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function envValue(string $key, string $default): string
{
    $value = getenv($key);
    if ($value !== false) {
        return (string) $value;
    }

    return (string) ($_ENV[$key] ?? $default);
}

function cliLimit(array $argv): ?int
{
    foreach ($argv as $arg) {
        if (strpos($arg, '--limit=') !== 0) {
            continue;
        }

        $limit = (int) substr($arg, strlen('--limit='));
        return $limit > 0 ? $limit : null;
    }

    return null;
}

function isGeneratedTestDatabaseName(string $databaseName): bool
{
    return preg_match('/^crm_test_(?:template_)?[A-Fa-f0-9]{16}$/', $databaseName) === 1;
}

function quoteIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }

    return $identifier;
}
