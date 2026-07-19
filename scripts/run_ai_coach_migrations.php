<?php
/**
 * Run only AI Coach migrations (056, 057, 058, 075).
 * 075 creates idea_validation_context and beginner_budget for Foundation Mode / Idea Validation.
 */
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}
$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? 'crm_db';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$migrations = [
    '056_create_user_preferences.sql',
    '057_create_task_subtasks.sql',
    '058_create_feature_usage_tracking.sql',
    '075_beginner_mode_tables.sql',
];
foreach ($migrations as $f) {
    $path = dirname(__DIR__) . '/database/migrations/' . $f;
    if (!file_exists($path)) { echo "Skip $f (missing)\n"; continue; }
    $sql = file_get_contents($path);
    $pdo->exec($sql);
    $stmt = $pdo->prepare("INSERT IGNORE INTO migrations (migration_name) VALUES (?)");
    $stmt->execute([$f]);
    echo "OK $f\n";
}
echo "Done.\n";
