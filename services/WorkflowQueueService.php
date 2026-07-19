<?php
/**
 * Workflow Queue Service
 * Manages async workflow execution via database queue
 */

namespace CRM\Services;

use CRM\Database;

class WorkflowQueueService
{
    /**
     * Add workflow execution to queue (non-blocking)
     */
    public function addToQueue(int $workflowId, int $contactId, array $eventData, ?int $workspaceId = null): int
    {
        $workspaceId = $this->resolveWorkspaceId($workflowId, $contactId, $workspaceId);
        Database::execute(
            "INSERT INTO workflow_queue (workflow_id, contact_id, workspace_id, event_data, status) VALUES (?, ?, ?, ?, 'pending')",
            [$workflowId, $contactId, $workspaceId > 0 ? $workspaceId : null, json_encode($eventData)]
        );
        return (int) Database::lastInsertId();
    }

    /**
     * Process pending queue items (called by CLI worker)
     */
    public function processQueue(int $batchSize = 50): int
    {
        $processed = 0;
        $executionService = new WorkflowExecutionService();
        $observability = new WorkflowObservabilityService();

        $batchSize = min(max((int) $batchSize, 1), 100);
        $items = Database::query(
            "SELECT * FROM workflow_queue 
             WHERE attempts < max_attempts
               AND (
                   status = 'pending'
                   OR (status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at <= NOW())
               )
             ORDER BY created_at ASC 
             LIMIT " . $batchSize,
            []
        );

        foreach ($items as $item) {
            $workspaceId = 0;
            $claimToken = $this->newClaimToken();
            try {
                $workspaceId = $this->resolveQueueWorkspaceId($item);
                if ($workspaceId > 0 && (int) ($item['workspace_id'] ?? 0) <= 0) {
                    Database::execute(
                        "UPDATE workflow_queue
                         SET workspace_id = ?
                         WHERE id = ?
                           AND workspace_id IS NULL",
                        [$workspaceId, $item['id']]
                    );
                    $item['workspace_id'] = $workspaceId;
                }

                // Claim item atomically to avoid double-processing across workers.
                $claimed = $workspaceId > 0
                    ? Database::execute(
                        "UPDATE workflow_queue
                         SET status = 'processing', attempts = attempts + 1,
                             claim_token = ?, claimed_at = NOW(),
                             lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                             worker_id = ?, processed_at = NULL, error_message = NULL
                         WHERE id = ?
                           AND workspace_id = ?
                           AND attempts < max_attempts
                           AND (status = 'pending' OR (status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at <= NOW()))",
                        [$claimToken, $this->leaseSeconds(), $this->workerId(), $item['id'], $workspaceId]
                    )
                    : Database::execute(
                        "UPDATE workflow_queue
                         SET status = 'processing', attempts = attempts + 1,
                             claim_token = ?, claimed_at = NOW(),
                             lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                             worker_id = ?, processed_at = NULL, error_message = NULL
                         WHERE id = ?
                           AND workspace_id IS NULL
                           AND attempts < max_attempts
                           AND (status = 'pending' OR (status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at <= NOW()))",
                        [$claimToken, $this->leaseSeconds(), $this->workerId(), $item['id']]
                    );
                if ($claimed === 0) {
                    continue;
                }
                $item['claim_token'] = $claimToken;
                $item['attempts'] = (int) ($item['attempts'] ?? 0) + 1;

                AsyncWorkspaceRunner::runWithWorkspace(
                    $workspaceId,
                    function () use ($observability, $executionService, $item, $workspaceId): void {
                        $observability->recordQueueLatency((int) $item['id'], $item['created_at'], $workspaceId);

                        $eventData = json_decode($item['event_data'], true) ?? [];
                        $eventData['contact_id'] = (int) $item['contact_id'];

                        $executionService->executeWorkflowFromQueue((int) $item['workflow_id'], $eventData, (int) $item['id'], $workspaceId);
                    },
                    null,
                    'Workflow queue item is missing a valid workspace.'
                );

                $this->updateQueueStatus((int) $item['id'], $workspaceId, 'completed', null, $claimToken);
                $processed++;
            } catch (\Exception $e) {
                error_log("Workflow queue item #{$item['id']} failed: " . $e->getMessage());
                $this->updateQueueStatus((int) $item['id'], $workspaceId, 'failed', substr($e->getMessage(), 0, 500), $claimToken);
            }
        }

        return $processed;
    }

    private function updateQueueStatus(int $queueId, int $workspaceId, string $status, ?string $errorMessage, string $claimToken): void
    {
        if ($workspaceId > 0) {
            Database::execute(
                "UPDATE workflow_queue
                 SET status = ?, error_message = ?, processed_at = NOW(),
                     claim_token = NULL, claimed_at = NULL, lease_expires_at = NULL
                 WHERE id = ?
                   AND workspace_id = ?
                   AND claim_token = ?
                   AND status = 'processing'",
                [$status, $errorMessage, $queueId, $workspaceId, $claimToken]
            );
            return;
        }

        Database::execute(
            "UPDATE workflow_queue
             SET status = ?, error_message = ?, processed_at = NOW(),
                 claim_token = NULL, claimed_at = NULL, lease_expires_at = NULL
             WHERE id = ?
               AND workspace_id IS NULL
               AND claim_token = ?
               AND status = 'processing'",
            [$status, $errorMessage, $queueId, $claimToken]
        );
    }

    private function resolveQueueWorkspaceId(array $item): int
    {
        $workspaceId = (int) ($item['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        $contactId = (int) ($item['contact_id'] ?? 0);
        if ($contactId > 0) {
            $contact = Database::queryOne(
                "SELECT workspace_id
                 FROM contacts
                 WHERE id = ?
                 LIMIT 1",
                [$contactId]
            );
            $workspaceId = (int) ($contact['workspace_id'] ?? 0);
            if ($workspaceId > 0) {
                return $workspaceId;
            }
        }

        $workflowId = (int) ($item['workflow_id'] ?? 0);
        if ($workflowId > 0) {
            $workflow = Database::queryOne(
                "SELECT workspace_id
                 FROM workflows
                 WHERE id = ?
                 LIMIT 1",
                [$workflowId]
            );
            return (int) ($workflow['workspace_id'] ?? 0);
        }

        return 0;
    }

    private function resolveWorkspaceId(int $workflowId, int $contactId, ?int $workspaceId = null): int
    {
        $workflowWorkspaceId = 0;
        if ($workflowId > 0) {
            $workflow = Database::queryOne(
                "SELECT workspace_id
                 FROM workflows
                 WHERE id = ?
                 LIMIT 1",
                [$workflowId]
            );
            $workflowWorkspaceId = (int) ($workflow['workspace_id'] ?? 0);
        }

        $contactWorkspaceId = 0;
        if ($contactId > 0) {
            $contact = Database::queryOne(
                "SELECT workspace_id
                 FROM contacts
                 WHERE id = ?
                 LIMIT 1",
                [$contactId]
            );
            $contactWorkspaceId = (int) ($contact['workspace_id'] ?? 0);
        }

        if ($workflowWorkspaceId > 0 && $contactWorkspaceId > 0 && $workflowWorkspaceId !== $contactWorkspaceId) {
            throw new \RuntimeException('Workflow and contact belong to different workspaces.');
        }

        if ($workspaceId !== null && $workspaceId > 0) {
            if (($workflowWorkspaceId > 0 && $workflowWorkspaceId !== $workspaceId) || ($contactWorkspaceId > 0 && $contactWorkspaceId !== $workspaceId)) {
                throw new \RuntimeException('Queue workspace does not match workflow or contact workspace.');
            }
            return $workspaceId;
        }

        $contextWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($contextWorkspaceId > 0) {
            if (($workflowWorkspaceId > 0 && $workflowWorkspaceId !== $contextWorkspaceId) || ($contactWorkspaceId > 0 && $contactWorkspaceId !== $contextWorkspaceId)) {
                throw new \RuntimeException('Workflow queue item is outside the active workspace.');
            }
            return $contextWorkspaceId;
        }

        if ($contactWorkspaceId > 0) {
            return $contactWorkspaceId;
        }

        if ($workflowWorkspaceId > 0) {
            return $workflowWorkspaceId;
        }

        return 0;
    }

    private function leaseSeconds(): int
    {
        $configured = (int) (getenv('WORKFLOW_QUEUE_LEASE_SECONDS') ?: ($_ENV['WORKFLOW_QUEUE_LEASE_SECONDS'] ?? 600));
        return min(max($configured, 60), 3600);
    }

    private function workerId(): string
    {
        $configured = trim((string) (getenv('QUEUE_WORKER_ID') ?: ($_ENV['QUEUE_WORKER_ID'] ?? '')));
        return substr($configured ?: ((gethostname() ?: 'unknown-host') . ':' . (string) getmypid()), 0, 191);
    }

    private function newClaimToken(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
