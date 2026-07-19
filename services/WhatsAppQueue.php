<?php
/**
 * WhatsApp Queue Service
 */

namespace CRM\Services;

use CRM\Database;

class WhatsAppQueue
{
    /**
     * Add message to queue
     */
    public function push(int $messageId, int $priority = 0, ?string $scheduledAt = null): int
    {
        $message = Database::queryOne(
            "SELECT workspace_id
             FROM whatsapp_messages
             WHERE id = ?
             LIMIT 1",
            [$messageId]
        );
        $workspaceId = (int) ($message['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            (new WorkspaceWhatsAppCreditService())->reserveForMessage($workspaceId, $messageId, (int) ($_SESSION['user_id'] ?? 0) ?: null);
        }

        Database::execute(
            "INSERT INTO whatsapp_queue (message_id, workspace_id, priority, scheduled_at, status) 
             VALUES (?, ?, ?, ?, 'pending')",
            [$messageId, $workspaceId ?: null, $priority, $scheduledAt]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get next message from queue
     * Uses transaction + FOR UPDATE to prevent race condition when multiple workers run
     */
    public function pop(?int $workspaceId = null): ?array
    {
        $now = date('Y-m-d H:i:s');

        Database::beginTransaction();
        try {
            $workspaceSql = $workspaceId !== null && $workspaceId > 0 ? ' AND wq.workspace_id = ?' : '';
            $params = [$now];
            if ($workspaceSql !== '') {
                $params[] = $workspaceId;
            }
            $job = Database::queryOne(
                "SELECT
                    wq.id AS queue_id,
                    wq.workspace_id AS queue_workspace_id,
                    wq.message_id,
                    wq.priority,
                    wq.attempts,
                    wq.max_attempts,
                    wq.scheduled_at,
                    wq.processed_at,
                    wq.status AS queue_status,
                    wq.error_message AS queue_error_message,
                    wq.created_at AS queue_created_at,
                    wm.*
                 FROM whatsapp_queue wq
                 JOIN whatsapp_messages wm ON wq.message_id = wm.id
                 WHERE wq.status = 'pending'
                 AND (wq.scheduled_at IS NULL OR wq.scheduled_at <= ?)
                 {$workspaceSql}
                 ORDER BY wq.priority DESC, wq.created_at DESC, wq.id DESC
                 LIMIT 1
                 FOR UPDATE",
                $params
            );

            if (!$job) {
                Database::rollBack();
                return null;
            }

            $workspaceId = (int) ($job['queue_workspace_id'] ?? $job['workspace_id'] ?? 0);
            if ($workspaceId > 0 && (int) ($job['queue_workspace_id'] ?? 0) <= 0) {
                Database::execute(
                    "UPDATE whatsapp_queue
                     SET workspace_id = ?
                     WHERE id = ?
                       AND workspace_id IS NULL",
                    [$workspaceId, $job['queue_id']]
                );
                $job['queue_workspace_id'] = $workspaceId;
            }

            if ($workspaceId > 0) {
                Database::execute(
                    "UPDATE whatsapp_queue
                     SET status = 'processing', attempts = attempts + 1
                     WHERE id = ?
                       AND workspace_id = ?",
                    [$job['queue_id'], $workspaceId]
                );
            } else {
                Database::execute(
                    "UPDATE whatsapp_queue
                     SET status = 'processing', attempts = attempts + 1
                     WHERE id = ?
                       AND workspace_id IS NULL",
                    [$job['queue_id']]
                );
            }

            Database::commit();
            return $job;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }
    
    /**
     * Mark job as completed
     */
    public function ack(int $queueId, ?int $workspaceId = null): void
    {
        $resolvedWorkspaceId = $this->resolveQueueWorkspaceId($queueId, $workspaceId);
        $sql = "UPDATE whatsapp_queue SET status = 'completed', processed_at = NOW() WHERE id = ?";
        $params = [$queueId];
        if ($resolvedWorkspaceId !== null && $resolvedWorkspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $resolvedWorkspaceId;
        } else {
            $sql .= " AND workspace_id IS NULL";
        }
        Database::execute($sql, $params);
    }
    
    /**
     * Mark job as failed
     */
    public function nack(int $queueId, string $error, ?int $workspaceId = null): void
    {
        $resolvedWorkspaceId = $this->resolveQueueWorkspaceId($queueId, $workspaceId);
        $sql = "UPDATE whatsapp_queue SET status = 'failed', error_message = ?, processed_at = NOW() WHERE id = ?";
        $params = [substr($error, 0, 500), $queueId];
        if ($resolvedWorkspaceId !== null && $resolvedWorkspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $resolvedWorkspaceId;
        } else {
            $sql .= " AND workspace_id IS NULL";
        }
        Database::execute($sql, $params);
    }

    /**
     * Put job back to pending for retry after rate limit (e.g. error 131056)
     */
    public function releaseForRetry(int $queueId, int $delaySeconds = 3600, ?int $workspaceId = null): void
    {
        $resolvedWorkspaceId = $this->resolveQueueWorkspaceId($queueId, $workspaceId);
        $sql = "UPDATE whatsapp_queue SET status = 'pending', error_message = NULL, scheduled_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?";
        $params = [$delaySeconds, $queueId];
        if ($resolvedWorkspaceId !== null && $resolvedWorkspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $resolvedWorkspaceId;
        } else {
            $sql .= " AND workspace_id IS NULL";
        }
        Database::execute($sql, $params);
    }

    private function resolveQueueWorkspaceId(int $queueId, ?int $workspaceId = null): ?int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return $workspaceId;
        }

        $queue = Database::queryOne(
            "SELECT wq.workspace_id, wm.workspace_id AS message_workspace_id
             FROM whatsapp_queue wq
             LEFT JOIN whatsapp_messages wm ON wm.id = wq.message_id
             WHERE wq.id = ?
             LIMIT 1",
            [$queueId]
        );

        $resolvedWorkspaceId = (int) ($queue['workspace_id'] ?? $queue['message_workspace_id'] ?? 0);
        if ($resolvedWorkspaceId > 0 && (int) ($queue['workspace_id'] ?? 0) <= 0) {
            Database::execute(
                "UPDATE whatsapp_queue
                 SET workspace_id = ?
                 WHERE id = ?
                   AND workspace_id IS NULL",
                [$resolvedWorkspaceId, $queueId]
            );
        }

        return $resolvedWorkspaceId > 0 ? $resolvedWorkspaceId : null;
    }
}
