<?php
/**
 * Campaign Scheduler Worker
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\CampaignScheduler;

Database::init(require __DIR__ . '/../config/database.php');

echo "Starting campaign scheduler...\n";
echo "Press Ctrl+C to stop.\n\n";

$scheduler = new CampaignScheduler();
$sleepInterval = (int) ($_ENV['CAMPAIGN_SCHEDULER_INTERVAL'] ?? 30);

while (true) {
    try {
        $scheduled = $scheduler->scheduleDueEnrollments(200);
        if ($scheduled > 0) {
            echo '[' . date('Y-m-d H:i:s') . "] Scheduled $scheduled campaign job(s)\n";
        }
        sleep($sleepInterval);
    } catch (\Throwable $e) {
        echo '[' . date('Y-m-d H:i:s') . '] Error: ' . $e->getMessage() . "\n";
        sleep($sleepInterval);
    }
}
