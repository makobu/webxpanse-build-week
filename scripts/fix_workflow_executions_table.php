<?php
/**
 * Create workflow_executions table if missing (e.g. migration 010 was skipped or table was dropped).
 * Uses same DB config as the app (.env / config/database.php).
 */

$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'crm_db';
$dbUser = $_ENV['DB_USER'] ?? 'root';
$dbPass = $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// Check if workflow_executions exists
$stmt = $pdo->query("SHOW TABLES LIKE 'workflow_executions'");
if ($stmt->rowCount() > 0) {
    echo "Table workflow_executions already exists.\n";
    exit(0);
}

echo "Creating table workflow_executions...\n";

$pdo->exec("
    CREATE TABLE workflow_executions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        workflow_id INT NOT NULL,
        contact_id INT NOT NULL,
        status ENUM('pending','running','completed','failed') DEFAULT 'pending',
        error_message TEXT,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        execution_time_ms INT,
        actions_completed INT DEFAULT 0,
        actions_failed INT DEFAULT 0,
        error_details JSON,
        INDEX idx_workflow_id (workflow_id),
        INDEX idx_contact_id (contact_id),
        INDEX idx_workflow_exec (workflow_id, executed_at DESC),
        FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "✓ workflow_executions created.\n";

// Ensure scheduled_workflow_actions exists (depends on workflow_executions)
$stmt = $pdo->query("SHOW TABLES LIKE 'scheduled_workflow_actions'");
if ($stmt->rowCount() === 0) {
    echo "Creating table scheduled_workflow_actions...\n";
    $pdo->exec("
        CREATE TABLE scheduled_workflow_actions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            workflow_id INT NOT NULL,
            execution_id INT NOT NULL,
            action_index INT NOT NULL,
            scheduled_for DATETIME NOT NULL,
            timezone VARCHAR(50),
            status ENUM('pending','executed','failed','cancelled') DEFAULT 'pending',
            retry_count INT DEFAULT 0,
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
            FOREIGN KEY (execution_id) REFERENCES workflow_executions(id) ON DELETE CASCADE,
            INDEX idx_scheduled (scheduled_for, status),
            INDEX idx_workflow_exec (workflow_id, execution_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ scheduled_workflow_actions created.\n";
}

echo "\nDone. workflow_executions is ready.\n";
