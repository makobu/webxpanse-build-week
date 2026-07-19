<?php
/**
 * Verify idea_validation_context and beginner_budget tables exist (migration 075).
 */
$base = dirname(__DIR__);
$envFile = $base . '/.env';
if (!file_exists($envFile)) {
    echo "No .env found\n";
    exit(1);
}
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
    list($k, $v) = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v);
}
$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'crm') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = ['idea_validation_context', 'beginner_budget'];
$ok = true;
foreach ($tables as $t) {
    $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t));
    $exists = $stmt->rowCount() > 0;
    echo $exists ? "OK  Table $t exists\n" : "MISSING  Table $t\n";
    if (!$exists) $ok = false;
}
echo $ok ? "\nAll tables present. Idea Validation save will work.\n" : "\nRun: php scripts/run_ai_coach_migrations.php\n";
exit($ok ? 0 : 1);
