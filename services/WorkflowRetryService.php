<?php

namespace CRM\Services;

use CRM\Database;

class WorkflowRetryService
{
    public function scheduleRetry(array $failure): void
    {
        $retryCount = (int) ($failure['retry_count'] ?? 0);
        $backoffSeconds = [1, 5, 15, 60][$retryCount] ?? 300;
        $retryAfter = date('Y-m-d H:i:s', time() + $backoffSeconds);

        Database::execute(
            "INSERT INTO workflow_retry_queue
             (workflow_queue_id, workflow_execution_id, workflow_id, workspace_id, node_id, action_index, retry_after, retry_count, last_error, payload_json, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
            [
                $failure['workflow_queue_id'] ?? null,
                $failure['workflow_execution_id'],
                $failure['workflow_id'],
                $this->resolveWorkspaceId($failure),
                $failure['node_id'],
                $failure['action_index'] ?? null,
                $retryAfter,
                $retryCount + 1,
                $failure['error'] ?? '',
                json_encode($failure['payload'] ?? []),
            ]
        );
    }

    public function claimDueRetries(int $limit = 20): array
    {
        $items = Database::query(
            "SELECT * FROM workflow_retry_queue
             WHERE status = 'pending' AND retry_after <= NOW()
             ORDER BY retry_after ASC
             LIMIT " . max(1, min(100, $limit)),
            []
        );

        $claimed = [];
        foreach ($items as $item) {
            $workspaceId = $this->resolveWorkspaceId($item, false);
            if ($workspaceId > 0 && (int) ($item['workspace_id'] ?? 0) <= 0) {
                Database::execute(
                    "UPDATE workflow_retry_queue
                     SET workspace_id = ?
                     WHERE id = ?
                       AND workspace_id IS NULL",
                    [$workspaceId, $item['id']]
                );
                $item['workspace_id'] = $workspaceId;
            }

            $updated = $workspaceId > 0
                ? Database::execute(
                    "UPDATE workflow_retry_queue
                     SET status = 'processing', processed_at = NOW()
                     WHERE id = ?
                       AND workspace_id = ?
                       AND status = 'pending'",
                    [$item['id'], $workspaceId]
                )
                : Database::execute(
                    "UPDATE workflow_retry_queue
                     SET status = 'processing', processed_at = NOW()
                     WHERE id = ?
                       AND workspace_id IS NULL
                       AND status = 'pending'",
                    [$item['id']]
                );
            if ($updated > 0) {
                $claimed[] = $item;
            }
        }

        return $claimed;
    }

    public function findPendingRetryForNode(int $workflowExecutionId, string $nodeId): ?array
    {
        return Database::queryOne(
            "SELECT * FROM workflow_retry_queue
             WHERE workflow_execution_id = ? AND node_id = ? AND status IN ('pending', 'failed')
             ORDER BY id DESC LIMIT 1",
            [$workflowExecutionId, $nodeId]
        ) ?: null;
    }

    public function findLatestRetryForExecution(int $workflowExecutionId): ?array
    {
        return Database::queryOne(
            "SELECT * FROM workflow_retry_queue
             WHERE workflow_execution_id = ? AND status IN ('pending', 'failed')
             ORDER BY id DESC LIMIT 1",
            [$workflowExecutionId]
        ) ?: null;
    }

    public function claimRetryById(int $retryId): ?array
    {
        $item = Database::queryOne("SELECT * FROM workflow_retry_queue WHERE id = ? LIMIT 1", [$retryId]);
        if (!$item) {
            return null;
        }

        $workspaceId = $this->resolveWorkspaceId($item, false);
        if ($workspaceId > 0 && (int) ($item['workspace_id'] ?? 0) <= 0) {
            Database::execute(
                "UPDATE workflow_retry_queue
                 SET workspace_id = ?
                 WHERE id = ?
                   AND workspace_id IS NULL",
                [$workspaceId, $retryId]
            );
            $item['workspace_id'] = $workspaceId;
        }

        $updated = $workspaceId > 0
            ? Database::execute(
                "UPDATE workflow_retry_queue
                 SET status = 'processing', processed_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?
                   AND status IN ('pending', 'failed')",
                [$retryId, $workspaceId]
            )
            : Database::execute(
                "UPDATE workflow_retry_queue
                 SET status = 'processing', processed_at = NOW()
                 WHERE id = ?
                   AND workspace_id IS NULL
                   AND status IN ('pending', 'failed')",
                [$retryId]
            );

        return $updated > 0 ? $item : null;
    }

    public function finalizeRetry(array $result): void
    {
        $workspaceId = (int) ($result['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            Database::execute(
                "UPDATE workflow_retry_queue
                 SET status = ?, last_error = ?, processed_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?",
                [
                    $result['status'] ?? 'completed',
                    $result['error'] ?? null,
                    $result['id'],
                    $workspaceId,
                ]
            );
            return;
        }

        Database::execute(
            "UPDATE workflow_retry_queue
             SET status = ?, last_error = ?, processed_at = NOW()
             WHERE id = ?
               AND workspace_id IS NULL",
            [
                $result['status'] ?? 'completed',
                $result['error'] ?? null,
                $result['id'],
            ]
        );
    }

    private function resolveWorkspaceId(array $failure, bool $allowContextFallback = true): ?int
    {
        $workspaceId = (int) ($failure['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        $contextWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($allowContextFallback && $contextWorkspaceId > 0) {
            return $contextWorkspaceId;
        }

        $executionId = (int) ($failure['workflow_execution_id'] ?? 0);
        if ($executionId > 0) {
            $execution = Database::queryOne(
                "SELECT workspace_id
                 FROM workflow_executions
                 WHERE id = ?
                 LIMIT 1",
                [$executionId]
            );
            $workspaceId = (int) ($execution['workspace_id'] ?? 0);
            if ($workspaceId > 0) {
                return $workspaceId;
            }
        }

        $workflowId = (int) ($failure['workflow_id'] ?? 0);
        if ($workflowId > 0) {
            $workflow = Database::queryOne(
                "SELECT workspace_id
                 FROM workflows
                 WHERE id = ?
                 LIMIT 1",
                [$workflowId]
            );
            $workspaceId = (int) ($workflow['workspace_id'] ?? 0);
            if ($workspaceId > 0) {
                return $workspaceId;
            }
        }

        return null;
    }
}
