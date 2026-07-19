<?php
/**
 * Guardian Detector CLI
 *
 * Runs Guardian checks (conversion collapse, high-value leads ignored,
 * pipeline drop, stale deals) and creates alerts when triggers fire.
 * Schedule via cron every 6-12 hours: php cli/guardian_detector.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Modules\GuardianDetector;

$dbConfig = require __DIR__ . '/../config/database.php';
Database::init($dbConfig);

$detector = new GuardianDetector();

echo "Guardian Detector running...\n";

try {
    $alerts = $detector->runChecks();
    if (empty($alerts)) {
        echo "No Guardian alerts triggered.\n";
    } else {
        echo count($alerts) . " Guardian alert(s) created.\n";
    }
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    error_log('GuardianDetector CLI error: ' . $e->getMessage());
    exit(1);
}
