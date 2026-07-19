<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/constants.php';

foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v);
}

use CRM\Database;
use CRM\Services\AITaskWorkspaceAutoSeedWorkerService;
use CRM\Services\SessionAutomationStateService;
use CRM\Services\TaskCompletionScanQueueService;

Database::init(require __DIR__ . '/../config/database.php');

$queue = new TaskCompletionScanQueueService();
$queueSummary = $queue->enqueueForActiveUsers('cron');
$processSummary = $queue->processQueuedScans(25);
$aiSeedSummary = (new AITaskWorkspaceAutoSeedWorkerService())->run(100);
$prunedQueueRows = $queue->pruneOldTerminalRows(14);
$prunedAutomationRows = (new SessionAutomationStateService())->pruneOldRows(30);

$summary = [
    'queued_users' => (int) ($queueSummary['queued_users'] ?? 0),
    'eligible_users' => (int) ($queueSummary['eligible_users'] ?? 0),
    'processed_jobs' => (int) ($processSummary['processed_jobs'] ?? 0),
    'completed_jobs' => (int) ($processSummary['completed_jobs'] ?? 0),
    'failed_jobs' => (int) ($processSummary['failed_jobs'] ?? 0),
    'completed_tasks' => (int) ($processSummary['completed_tasks'] ?? 0),
    'ai_seed_checked_users' => (int) ($aiSeedSummary['checked_users'] ?? 0),
    'ai_seed_checked_memberships' => (int) ($aiSeedSummary['checked_memberships'] ?? 0),
    'ai_seed_seeded_users' => (int) ($aiSeedSummary['seeded_users'] ?? 0),
    'ai_seed_seeded_memberships' => (int) ($aiSeedSummary['seeded_memberships'] ?? 0),
    'ai_seed_created_tasks' => (int) ($aiSeedSummary['created_tasks'] ?? 0),
    'ai_seed_retired_tasks' => (int) ($aiSeedSummary['retired_tasks'] ?? 0),
    'ai_seed_blocked_by_gate' => (int) ($aiSeedSummary['blocked_by_gate'] ?? 0),
    'ai_seed_blocked_by_plan' => (int) ($aiSeedSummary['blocked_by_plan'] ?? 0),
    'ai_seed_gate_redirected' => (int) ($aiSeedSummary['gate_redirected'] ?? 0),
    'ai_seed_workspace_context_failures' => (int) ($aiSeedSummary['workspace_context_failures'] ?? 0),
    'pruned_queue_rows' => $prunedQueueRows,
    'pruned_automation_rows' => $prunedAutomationRows,
    'results' => $processSummary['results'] ?? [],
];

echo json_encode($summary, JSON_PRETTY_PRINT) . PHP_EOL;
