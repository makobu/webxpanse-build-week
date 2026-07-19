<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$target = $argv[1] ?? '';
if ($target === '') {
    fwrite(STDERR, "Usage: php scripts/run_sql_file.php <sql-file>\n");
    exit(1);
}

$sqlFile = $target;
if (!preg_match('/^[A-Za-z]:\\\\|^\//', $sqlFile)) {
    $sqlFile = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $target), '/');
}

if (!is_file($sqlFile)) {
    fwrite(STDERR, "SQL file not found: {$sqlFile}\n");
    exit(1);
}

// Load env variables from .env when present.
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

$config = require __DIR__ . '/../config/database.php';
$dsn = 'mysql:host=' . $config['host'] . ';dbname=' . $config['name'];
if (!empty($config['charset'])) {
    $dsn .= ';charset=' . $config['charset'];
}

$sql = (string) file_get_contents($sqlFile);

/**
 * Split SQL into statements, only on semicolons outside single-quoted strings.
 *
 * @return list<string>
 */
function splitSqlStatementsForRunner(string $sql): array
{
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $inSingleQuote = false;

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];

        if ($inSingleQuote) {
            $current .= $c;
            if ($c === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") {
                    $current .= "'";
                    $i++;
                } else {
                    $inSingleQuote = false;
                }
            }
            continue;
        }

        if ($c === "'") {
            $current .= $c;
            $inSingleQuote = true;
            continue;
        }

        if ($c === ';') {
            $statement = cleanSqlStatementForRunner($current);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
            continue;
        }

        $current .= $c;
    }

    $statement = cleanSqlStatementForRunner($current);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

function cleanSqlStatementForRunner(string $statement): string
{
    $cleaned = [];
    foreach (preg_split('/\R/', trim($statement)) ?: [] as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $cleaned[] = $line;
    }

    return trim(implode("\n", $cleaned));
}

function executeSqlStatementForRunner(PDO $pdo, string $statement): void
{
    $command = strtoupper(strtok(ltrim($statement), " \t\r\n(") ?: '');
    $returnsRows = in_array($command, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'EXECUTE', 'CALL'], true);

    if ($returnsRows) {
        $stmt = $pdo->query($statement);
        if ($stmt instanceof PDOStatement) {
            do {
                $stmt->fetchAll(PDO::FETCH_ASSOC);
            } while ($stmt->nextRowset());
            $stmt->closeCursor();
        }
        return;
    }

    $pdo->exec($statement);
}

function isIgnorableSchemaDriftError(Throwable $e): bool
{
    $message = $e->getMessage();

    return strpos($message, 'Duplicate column name') !== false
        || strpos($message, 'Duplicate key name') !== false
        || strpos($message, 'already exists') !== false
        || (strpos($message, "Can't DROP") !== false && strpos($message, 'check that') !== false && strpos($message, 'exists') !== false);
}

try {
    $pdo = new PDO($dsn, $config['user'], $config['pass'], $config['options'] ?? []);
    $executed = 0;
    $skipped = 0;
    foreach (splitSqlStatementsForRunner($sql) as $statement) {
        try {
            executeSqlStatementForRunner($pdo, $statement);
            $executed++;
        } catch (Throwable $e) {
            if (isIgnorableSchemaDriftError($e)) {
                $skipped++;
                continue;
            }
            throw $e;
        }
    }
    fwrite(STDOUT, "Executed: {$sqlFile} ({$executed} statement(s), {$skipped} duplicate schema statement(s) skipped)\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Failed: " . $e->getMessage() . "\n");
    exit(2);
}
