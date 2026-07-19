<?php
/**
 * Add ICP columns to company_profile if missing (migration 077).
 * Safe to run multiple times.
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

$columns = ['icp_job_titles', 'icp_industries', 'icp_pain_points', 'icp_channels'];
$stmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_profile'");
$existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($columns as $col) {
    if (in_array($col, $existing, true)) {
        echo "Skip $col (already exists)\n";
        continue;
    }
    $pdo->exec("ALTER TABLE company_profile ADD COLUMN $col TEXT NULL");
    echo "Added column $col\n";
}
echo "Done.\n";
