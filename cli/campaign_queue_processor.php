<?php
/**
 * Campaign Queue Processor Worker
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
use CRM\Services\CampaignQueueProcessor;

Database::init(require __DIR__ . '/../config/database.php');

echo "Starting campaign queue processor...\n";
echo "Press Ctrl+C to stop.\n\n";

$processor = new CampaignQueueProcessor();
$sleepInterval = (int) ($_ENV['CAMPAIGN_QUEUE_INTERVAL'] ?? 15);

while (true) {
    try {
        $processed = $processor->processQueue(100);
        if ($processed > 0) {
            echo '[' . date('Y-m-d H:i:s') . "] Processed $processed campaign job(s)\n";
        }
        sleep($sleepInterval);
    } catch (\Throwable $e) {
        echo '[' . date('Y-m-d H:i:s') . '] Error: ' . $e->getMessage() . "\n";
        sleep($sleepInterval);
    }
}
