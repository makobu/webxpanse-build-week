<?php
/**
 * Campaign Queue Processor (one-shot)
 *
 * SiteGround-safe cron entrypoint for pending campaign queue jobs.
 * Usage: php cli/process_campaign_queue.php [batch_size]
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
use CRM\Services\CampaignQueueProcessor;

Database::init(require __DIR__ . '/../config/database.php');

$batchSize = isset($argv[1]) ? (int) $argv[1] : 100;
$processor = new CampaignQueueProcessor();

try {
    $processed = $processor->processQueue($batchSize);
    echo sprintf("[%s] Campaign queue complete: processed=%d\n", date('Y-m-d H:i:s'), $processed);
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf("[%s] Error: %s\n", date('Y-m-d H:i:s'), $e->getMessage()));
    exit(1);
}
