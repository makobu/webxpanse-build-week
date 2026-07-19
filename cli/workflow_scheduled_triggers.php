<?php
/**
 * Workflow Scheduled Triggers
 * Cron job to fire time-based and condition-based workflow triggers
 * Run via cron every minute: * * * * * php /path/to/crm/cli/workflow_scheduled_triggers.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\WorkflowScheduledTriggerService;

Database::init(require __DIR__ . '/../config/database.php');

$service = new WorkflowScheduledTriggerService();
$fired = $service->processScheduledTriggers();

if (php_sapi_name() === 'cli' && $fired > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] Fired {$fired} scheduled workflow trigger(s)\n";
}
