<?php
/**
 * AI Auto-responder Worker
 * Processes queued inbound communications and sends/drafts AI replies.
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

echo "AI Auto-responder worker started...\n";

$queueService = new AIAutoResponderQueueService();
$autoResponder = new AIAutoResponderService();
$pollIntervalSeconds = max(2, (int) ($_ENV['AI_AUTORESPONDER_WORKER_INTERVAL'] ?? 10));
$batchSize = max(1, min(100, (int) ($_ENV['AI_AUTORESPONDER_BATCH_SIZE'] ?? 20)));

while (true) {
    try {
        $items = $queueService->fetchPending($batchSize);
        if (empty($items)) {
            sleep($pollIntervalSeconds);
            continue;
        }

        foreach ($items as $item) {
            $queueId = (int) $item['id'];
            $workspaceId = (int) ($item['workspace_id'] ?? 0);
            try {
                $queueService->markProcessing($queueId, $workspaceId);
                $result = AsyncWorkspaceRunner::runWithWorkspace(
                    $workspaceId,
                    fn() => $autoResponder->processQueueItem($item),
                    null,
                    'AI auto-responder queue item is missing a valid workspace.'
                );
                $action = (string) ($result['action'] ?? 'failed');

                if (in_array($action, ['auto_sent'], true)) {
                    $queueService->markCompleted($queueId, $workspaceId);
                } elseif (in_array($action, ['draft', 'blocked', 'skipped', 'review'], true)) {
                    $queueService->markSkipped($queueId, (string) ($result['reason'] ?? $action), $workspaceId);
                } else {
                    $queueService->markFailed($queueId, (string) ($result['error'] ?? 'Unknown processing error'), 30, $workspaceId);
                }
            } catch (\Throwable $e) {
                $queueService->markFailed($queueId, $e->getMessage(), 30, $workspaceId);
                error_log("AI auto-responder queue item {$queueId} failed: " . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        error_log("AI auto-responder worker loop failed: " . $e->getMessage());
        sleep($pollIntervalSeconds);
    }
}
