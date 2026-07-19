<?php
/**
 * Workflow Scheduler Processor (one-shot)
 *
 * SiteGround-safe cron entrypoint for scheduled workflow actions and retries.
 * Usage: php cli/process_workflow_scheduler.php
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
use CRM\Services\AutomationJobHealthService;
use CRM\Services\WorkflowScheduler;

Database::init(require __DIR__ . '/../config/database.php');

$scheduler = new WorkflowScheduler();
$jobHealth = new AutomationJobHealthService();
$jobHealth->markStarted('workflow_scheduler');
$startedAt = microtime(true);

try {
    $processed = $scheduler->processScheduledActions();
    $retried = $scheduler->processRetryQueue();
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $jobHealth->markSuccess(
        'workflow_scheduler',
        sprintf('Processed %d scheduled and %d retry action(s).', $processed, $retried),
        $durationMs,
        [
            'processed' => $processed,
            'retried' => $retried,
            'mode' => 'one_shot',
        ]
    );

    echo sprintf(
        "[%s] Workflow scheduler complete: processed=%d retried=%d\n",
        date('Y-m-d H:i:s'),
        $processed,
        $retried
    );
} catch (\Throwable $e) {
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $jobHealth->markFailure('workflow_scheduler', $e->getMessage(), $durationMs);
    fwrite(STDERR, sprintf("[%s] Error: %s\n", date('Y-m-d H:i:s'), $e->getMessage()));
    exit(1);
}
