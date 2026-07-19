<?php
/**
 * AI Auto-responder Queue Processor (one-shot)
 * Use this via cron on shared hosting environments (e.g., SiteGround).
 *
 * Example:
 * php /path/to/crm/cli/process_ai_autoresponder_queue.php 20
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\AsyncWorkspaceRunner;
use CRM\Services\AIAutoResponderQueueService;
use CRM\Services\AIAutoResponderService;

Database::init(require __DIR__ . '/../config/database.php');

$limitArg = isset($argv[1]) ? (int) $argv[1] : 20;
$batchSize = max(1, min(100, $limitArg));

$queueService = new AIAutoResponderQueueService();
$autoResponder = new AIAutoResponderService();

$items = $queueService->fetchPending($batchSize);
$processed = 0;
$autoSent = 0;
$drafted = 0;
$blocked = 0;
$failed = 0;

foreach ($items as $item) {
    $queueId = (int) ($item['id'] ?? 0);
    if ($queueId <= 0) {
        continue;
    }

    try {
        $workspaceId = (int) ($item['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            $queueService->markFailed($queueId, 'Queue item is missing a valid workspace.', 30, $workspaceId);
            $failed++;
            $processed++;
            continue;
        }

        $queueService->markProcessing($queueId, $workspaceId);
        $result = AsyncWorkspaceRunner::runWithWorkspace(
            $workspaceId,
            function () use ($autoResponder, $item) {
                return $autoResponder->processQueueItem($item);
            },
            null,
            'Queue item is missing a valid workspace.'
        );
        $action = (string) ($result['action'] ?? 'failed');

        if ($action === 'auto_sent') {
            $queueService->markCompleted($queueId, $workspaceId);
            $autoSent++;
        } elseif (in_array($action, ['draft', 'blocked', 'skipped', 'review'], true)) {
            $queueService->markSkipped($queueId, (string) ($result['reason'] ?? $action), $workspaceId);
            if ($action === 'draft' || $action === 'review') {
                $drafted++;
            } elseif ($action === 'blocked' || $action === 'skipped') {
                $blocked++;
            }
        } else {
            $queueService->markFailed($queueId, (string) ($result['error'] ?? 'Unknown processing error'), 30, $workspaceId);
            $failed++;
        }
    } catch (\Throwable $e) {
        $queueService->markFailed($queueId, $e->getMessage(), 30, (int) ($item['workspace_id'] ?? 0));
        $failed++;
        error_log("AI auto-responder queue item {$queueId} failed: " . $e->getMessage());
    } finally {
    }
    $processed++;
}

echo sprintf(
    "AI queue run complete: processed=%d auto_sent=%d drafted=%d blocked_or_skipped=%d failed=%d\n",
    $processed,
    $autoSent,
    $drafted,
    $blocked,
    $failed
);
