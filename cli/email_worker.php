<?php
/**
 * Email Queue Worker
 * 
 * Processes email queue in background
 * Run: php cli/email_worker.php
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
use CRM\Services\AsyncWorkspaceRunner;
use CRM\Services\EmailService;
use CRM\Services\EmailQueue;
use CRM\Services\QueueWorkerHeartbeatService;

// Initialize database
$dbConfig = require __DIR__ . '/../config/database.php';
Database::init($dbConfig);

$emailService = new EmailService();
$queue = new EmailQueue();
$heartbeat = new QueueWorkerHeartbeatService('email_worker');
$heartbeat->start(['mode' => 'continuous']);
register_shutdown_function(static function () use ($heartbeat): void {
    try {
        $heartbeat->stop();
    } catch (\Throwable $e) {
    }
});

echo "Email worker started...\n";

// Process queue
while (true) {
    $heartbeat->touch();
    $job = $queue->pop();
    
    if (!$job) {
        // Process orphan pending emails (no queue row) so none get stuck
        $orphan = Database::queryOne(
            "SELECT e.id, e.workspace_id FROM emails e
             LEFT JOIN email_queue eq ON e.id = eq.email_id
             WHERE e.status = 'pending' AND eq.id IS NULL
             ORDER BY e.created_at ASC LIMIT 1"
        );
        if ($orphan && !empty($orphan['id'])) {
            $job = ['queue_id' => null, 'email_id' => (int) $orphan['id'], 'workspace_id' => (int) ($orphan['workspace_id'] ?? 0), 'attempts' => 0, 'max_attempts' => 3];
        }
        if (!$job) {
            sleep(5); // Wait 5 seconds if no jobs
            continue;
        }
    }
    
    echo "Processing email ID: {$job['email_id']}\n";
    
    try {
        $workspaceId = (int) ($job['workspace_id'] ?? $job['queue_workspace_id'] ?? 0);
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
            echo "Email sent successfully\n";
        } else {
            if (($job['queue_id'] ?? null) !== null) {
                if ($job['attempts'] < $job['max_attempts']) {
                    $queue->retry((int) $job['queue_id'], $workspaceId, $job['claim_token'] ?? null);
                    echo "Email failed, will retry (attempt {$job['attempts']}/{$job['max_attempts']})\n";
                } else {
                    $queue->nack((int) $job['queue_id'], 'Max attempts reached', $workspaceId, $job['claim_token'] ?? null);
                    echo "Email failed permanently\n";
                }
            } else {
                echo "Orphan email failed to send\n";
            }
        }
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        if (($job['queue_id'] ?? null) !== null && isset($job['attempts'], $job['max_attempts'])) {
            if ($job['attempts'] < $job['max_attempts']) {
                $queue->retry((int) $job['queue_id'], (int) ($job['workspace_id'] ?? $job['queue_workspace_id'] ?? 0), $job['claim_token'] ?? null);
            } else {
                $queue->nack((int) $job['queue_id'], $e->getMessage(), (int) ($job['workspace_id'] ?? $job['queue_workspace_id'] ?? 0), $job['claim_token'] ?? null);
            }
        }
    } finally {
        $heartbeat->touch(['last_queue_id' => $job['queue_id'] ?? null], true);
    }
    
    // Small delay between emails
    usleep(100000); // 0.1 seconds
}
