<?php
/**
 * Workflow Queue Processor
 * Background worker to process queued workflow executions
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
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
use CRM\Services\WorkflowQueueService;
use CRM\Services\QueueWorkerHeartbeatService;

Database::init(require __DIR__ . '/../config/database.php');

echo "Starting workflow queue processor...\n";
echo "Press Ctrl+C to stop.\n\n";

$queueService = new WorkflowQueueService();
$heartbeat = new QueueWorkerHeartbeatService('workflow_queue_worker');
$heartbeat->start(['mode' => 'continuous']);
register_shutdown_function(static function () use ($heartbeat): void {
    try {
        $heartbeat->stop();
    } catch (\Throwable $e) {
    }
});
$sleepInterval = (int) ($_ENV['WORKFLOW_QUEUE_INTERVAL'] ?? 30);

while (true) {
    $heartbeat->touch();
    try {
        $processed = $queueService->processQueue(50);

        if ($processed > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] Processed $processed workflow execution(s)\n";
        }

        $heartbeat->touch(['last_processed' => $processed], true);

        sleep($sleepInterval);
    } catch (\Exception $e) {
        $heartbeat->touch(['last_error' => substr($e->getMessage(), 0, 300)], true);
        echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
        sleep($sleepInterval);
    }
}
