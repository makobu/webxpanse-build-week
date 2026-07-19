<?php
/**
 * Alert Checker - Runs system health checks and creates alerts
 * 
 * Call periodically via cron (e.g. every 15 minutes) to check:
 * - Database connection/performance
 * - Email queue backlog
 * - Error rates
 * - Disk/memory usage
 * - Slow queries
 * 
 * Usage: php cli/alert_checker.php
 * Cron example: every 15 minutes, run this script from cron and append output to a log file.
 */

// Load environment
$baseDir = dirname(__DIR__);
require_once $baseDir . '/vendor/autoload.php';

$envFile = $baseDir . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once $baseDir . '/config/constants.php';

use CRM\Database;
use CRM\Modules\AlertingSystem;

try {
    Database::init(require $baseDir . '/config/database.php');
    
    $alerting = new AlertingSystem();
    $alerting->checkSystemAlerts();
    
    echo date('Y-m-d H:i:s') . " - Alert check completed\n";
} catch (\Exception $e) {
    echo date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n";
    exit(1);
}
