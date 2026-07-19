<?php
/**
 * Deal Automation Inactivity Sweep
 *
 * Scheduled job to suggest closed_lost for deals with no recent activity.
 * Run via cron: 0 2 * * * php /path/to/cli/deal_automation_inactivity_sweep.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\DealAutomationOrchestrator;

try {
    $dbConfig = require __DIR__ . '/../config/database.php';
    Database::init($dbConfig);
} catch (\Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$orchestrator = new DealAutomationOrchestrator();
$result = $orchestrator->runInactivitySweep();

echo "[" . date('Y-m-d H:i:s') . "] Deal automation inactivity sweep: "
    . "processed={$result['processed']}, suggestions={$result['suggestions']}, applied={$result['applied']}\n";

exit(0);
