<?php
/**
 * Workflow Queue Processor (one-shot)
 *
 * SiteGround-safe cron entrypoint for workflow queue execution.
 * Usage: php cli/process_workflow_queue.php [batch_size]
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
use CRM\Services\WorkflowQueueService;
use CRM\Services\QueueWorkerHeartbeatService;

Database::init(require __DIR__ . '/../config/database.php');

$batchSize = isset($argv[1]) ? (int) $argv[1] : 50;
$queueService = new WorkflowQueueService();
$heartbeat = new QueueWorkerHeartbeatService('workflow_queue_worker');
$heartbeat->start(['mode' => 'one_shot', 'batch_size' => $batchSize]);

try {
    $processed = $queueService->processQueue($batchSize);
    $heartbeat->stop('stopped', ['processed' => $processed]);
    echo sprintf("[%s] Workflow queue complete: processed=%d\n", date('Y-m-d H:i:s'), $processed);
} catch (\Throwable $e) {
    $heartbeat->stop('failed', ['last_error' => substr($e->getMessage(), 0, 300)]);
    fwrite(STDERR, sprintf("[%s] Error: %s\n", date('Y-m-d H:i:s'), $e->getMessage()));
    exit(1);
}
