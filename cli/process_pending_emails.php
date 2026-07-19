<?php
/**
 * Process Pending Emails (One-time run)
 *
 * Canonical one-shot email queue processor. Uses the same workspace-safe
 * execution contract as the long-running worker, then exits.
 * Usage: php cli/process_pending_emails.php [limit]
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
use CRM\Services\AsyncWorkspaceRunner;
use CRM\Services\EmailQueue;
use CRM\Services\EmailService;
use CRM\Services\QueueWorkerHeartbeatService;

Database::init(require __DIR__ . '/../config/database.php');

$limit = max(1, min(500, isset($argv[1]) ? (int) $argv[1] : 50));
$emailService = new EmailService();
$queue = new EmailQueue();
$heartbeat = new QueueWorkerHeartbeatService('email_worker');
$heartbeat->start(['mode' => 'one_shot', 'limit' => $limit]);
$processed = 0;
$sent = 0;
$failed = 0;

for ($i = 0; $i < $limit; $i++) {
    $heartbeat->touch();
    $job = $queue->pop();

    if (!$job) {
        $orphan = Database::queryOne(
            "SELECT e.id, e.workspace_id
             FROM emails e
             LEFT JOIN email_queue eq ON e.id = eq.email_id
             WHERE e.status = 'pending'
               AND eq.id IS NULL
             ORDER BY e.created_at ASC
             LIMIT 1"
        );
        if (!$orphan || empty($orphan['id'])) {
            break;
        }

        $job = [
            'queue_id' => null,
            'email_id' => (int) $orphan['id'],
            'workspace_id' => (int) ($orphan['workspace_id'] ?? 0),
            'attempts' => 0,
            'max_attempts' => 3,
        ];
    }

    $processed++;
    $workspaceId = (int) ($job['workspace_id'] ?? $job['queue_workspace_id'] ?? 0);

    try {
        $success = AsyncWorkspaceRunner::runWithWorkspace(
            $workspaceId,
            function () use ($emailService, $job, $workspaceId): bool {
                return $emailService->processEmail((int) $job['email_id'], runtimeOptions: ['queue_claim_managed' => true], workspaceId: $workspaceId);
            },
            null,
            'Email job is missing a valid workspace.'
        );

        if ($success) {
            if (($job['queue_id'] ?? null) !== null) {
                $queue->ack((int) $job['queue_id'], $workspaceId, $job['claim_token'] ?? null);
            }
            $sent++;
            continue;
        }

        if (($job['queue_id'] ?? null) !== null) {
            if ((int) ($job['attempts'] ?? 0) < (int) ($job['max_attempts'] ?? 3)) {
                $queue->retry((int) $job['queue_id'], $workspaceId, $job['claim_token'] ?? null);
            } else {
                $queue->nack((int) $job['queue_id'], 'Max attempts reached', $workspaceId, $job['claim_token'] ?? null);
            }
        }
        $failed++;
    } catch (\Throwable $e) {
        if (($job['queue_id'] ?? null) !== null) {
            if ((int) ($job['attempts'] ?? 0) < (int) ($job['max_attempts'] ?? 3)) {
                $queue->retry((int) $job['queue_id'], $workspaceId, $job['claim_token'] ?? null);
            } else {
                $queue->nack((int) $job['queue_id'], $e->getMessage(), $workspaceId, $job['claim_token'] ?? null);
            }
        }
        $failed++;
        fwrite(STDERR, sprintf("Email %d failed: %s\n", (int) $job['email_id'], $e->getMessage()));
    }
}

$heartbeat->stop('stopped', ['processed' => $processed, 'sent' => $sent, 'failed' => $failed]);

echo sprintf(
    "[%s] Pending email run complete: processed=%d sent=%d failed=%d\n",
    date('Y-m-d H:i:s'),
    $processed,
    $sent,
    $failed
);
