<?php

namespace CRM\Services;

use CRM\Database;

class WorkflowObservabilityService
{
    public function startNodeRun(int $executionId, int $workflowId, array $node, array $input, int $attemptCount = 1): int
    {
        Database::execute(
            "INSERT INTO workflow_node_runs
             (workflow_execution_id, workflow_id, node_id, node_type, node_label, status, attempt_count, started_at, input_snapshot_json)
             VALUES (?, ?, ?, ?, ?, 'running', ?, NOW(), ?)",
            [
                $executionId,
                $workflowId,
                $node['id'],
                $node['type'] ?? 'action',
                $node['label'] ?? ($node['subtype'] ?? $node['id']),
                $attemptCount,
                json_encode($input),
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function completeNodeRun(int $nodeRunId, array $output = []): void
    {
        Database::execute(
            "UPDATE workflow_node_runs
             SET status = 'completed', completed_at = NOW(),
                 duration_ms = TIMESTAMPDIFF(MICROSECOND, started_at, NOW()) DIV 1000,
                 output_snapshot_json = ?
             WHERE id = ?",
            [json_encode($output), $nodeRunId]
        );
    }

    public function failNodeRun(int $nodeRunId, string $error): void
    {
        Database::execute(
            "UPDATE workflow_node_runs
             SET status = 'failed', completed_at = NOW(),
                 duration_ms = TIMESTAMPDIFF(MICROSECOND, started_at, NOW()) DIV 1000,
                 error_message = ?
             WHERE id = ?",
            [$error, $nodeRunId]
        );
    }

    public function recordQueueLatency(int $queueId, string $createdAt, ?int $workspaceId = null): void
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            Database::execute(
                "UPDATE workflow_queue
                 SET started_at = NOW(),
                     queue_latency_ms = TIMESTAMPDIFF(MICROSECOND, ?, NOW()) DIV 1000
                 WHERE id = ?
                   AND workspace_id = ?",
                [$createdAt, $queueId, $workspaceId]
            );
            return;
        }

        Database::execute(
            "UPDATE workflow_queue
             SET started_at = NOW(),
                 queue_latency_ms = TIMESTAMPDIFF(MICROSECOND, ?, NOW()) DIV 1000
             WHERE id = ?
               AND workspace_id IS NULL",
            [$createdAt, $queueId]
        );
    }
}
