<?php
/**
 * Email Queue Service
 * 
 * Manages email queue for async sending
 */

namespace CRM\Services;

use CRM\Database;

class EmailQueue
{
    /**
     * Add email to queue
     */
    public function push(int $emailId, int $priority = 0, ?string $scheduledAt = null, ?int $workspaceId = null): int
    {
        if ($workspaceId === null || $workspaceId <= 0) {
            $email = Database::queryOne(
                "SELECT workspace_id
                 FROM emails
                 WHERE id = ?
                 LIMIT 1",
                [$emailId]
            );
            $workspaceId = (int) ($email['workspace_id'] ?? 0);
        }

        $emailScope = Database::queryOne(
            "SELECT demo_visibility, demo_session_id FROM emails WHERE id = ? LIMIT 1",
            [$emailId]
        ) ?: [];
        $hasDemoColumns = Database::columnExists('email_queue', 'demo_visibility') && Database::columnExists('email_queue', 'demo_session_id');
        if ($hasDemoColumns) {
            Database::execute(
                "INSERT INTO email_queue (workspace_id, demo_visibility, demo_session_id, email_id, priority, scheduled_at, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')",
                [
                    $workspaceId > 0 ? $workspaceId : null,
                    $emailScope['demo_visibility'] ?? null,
                    $emailScope['demo_session_id'] ?? null,
                    $emailId,
                    $priority,
                    $scheduledAt,
                ]
            );
        } else {
            Database::execute(
                "INSERT INTO email_queue (workspace_id, email_id, priority, scheduled_at, status)
                 VALUES (?, ?, ?, ?, 'pending')",
                [$workspaceId > 0 ? $workspaceId : null, $emailId, $priority, $scheduledAt]
            );
        }
        
        $queueId = (int) Database::lastInsertId();
        if (!empty($emailScope['demo_session_id'])) {
            (new DemoSessionScopeService())->registerEntity((int) $emailScope['demo_session_id'], (int) $workspaceId, 'email_queue', $queueId);
        }
        return $queueId;
    }
    
    /**
     * Get next email from queue
     */
    public function pop(?int $workspaceId = null, ?int $queueId = null): ?array
    {
        $now = date('Y-m-d H:i:s');
        $claimToken = $this->newClaimToken();
        $workerId = $this->workerId();
        $leaseSeconds = $this->leaseSeconds();

        Database::beginTransaction();
        try {
            $scopeSql = '';
            $queryParams = [$now, $now];
            if ($workspaceId !== null && $workspaceId > 0) {
                $scopeSql .= ' AND (eq.workspace_id = ? OR (eq.workspace_id IS NULL AND e.workspace_id = ?))';
                $queryParams[] = $workspaceId;
                $queryParams[] = $workspaceId;
            }
            if ($queueId !== null && $queueId > 0) {
                $scopeSql .= ' AND eq.id = ?';
                $queryParams[] = $queueId;
            }

            $job = Database::queryOne(
                "SELECT
                    eq.id AS queue_id,
                    eq.workspace_id AS queue_workspace_id,
                    eq.email_id,
                    eq.priority,
                    eq.attempts,
                    eq.max_attempts,
                    eq.scheduled_at,
                    eq.processed_at,
                    eq.status AS queue_status,
                    eq.error_message AS queue_error_message,
                    eq.created_at AS queue_created_at,
                    e.*
                 FROM email_queue eq
                 JOIN emails e
                   ON eq.email_id = e.id
                  AND (eq.workspace_id IS NULL OR e.workspace_id = eq.workspace_id)
                 WHERE eq.attempts < eq.max_attempts
                   AND (
                       (eq.status = 'pending' AND (eq.scheduled_at IS NULL OR eq.scheduled_at <= ?))
                       OR (eq.status = 'processing' AND eq.lease_expires_at IS NOT NULL AND eq.lease_expires_at <= ?)
                   )" . $scopeSql . "
                 ORDER BY eq.priority DESC, eq.created_at ASC, eq.id ASC
                 LIMIT 1
                 FOR UPDATE",
                $queryParams
            );

            if (!$job) {
                Database::rollBack();
                return null;
            }

            $resolvedWorkspaceId = (int) ($job['queue_workspace_id'] ?? $job['workspace_id'] ?? 0);
            if ($resolvedWorkspaceId > 0 && (int) ($job['queue_workspace_id'] ?? 0) <= 0) {
                Database::execute(
                    "UPDATE email_queue
                     SET workspace_id = ?
                     WHERE id = ?
                       AND workspace_id IS NULL",
                    [$resolvedWorkspaceId, $job['queue_id']]
                );
                $job['queue_workspace_id'] = $resolvedWorkspaceId;
            }

            $claimSql = "UPDATE email_queue
                         SET status = 'processing',
                             attempts = attempts + 1,
                             claim_token = ?,
                             claimed_at = NOW(),
                             lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                             worker_id = ?,
                             processed_at = NULL,
                             error_message = NULL
                         WHERE id = ?";
            $claimParams = [$claimToken, $leaseSeconds, $workerId, $job['queue_id']];
            if ($resolvedWorkspaceId > 0) {
                $claimSql .= ' AND workspace_id = ?';
                $claimParams[] = $resolvedWorkspaceId;
            } else {
                $claimSql .= ' AND workspace_id IS NULL';
            }
            if (Database::execute($claimSql, $claimParams) !== 1) {
                throw new \RuntimeException('The selected email queue row could not be claimed.');
            }

            Database::commit();
            $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
            $job['claim_token'] = $claimToken;
            $job['worker_id'] = $workerId;
            $job['lease_seconds'] = $leaseSeconds;
            return $job;
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Mark job as completed
     */
    public function ack(int $queueId, ?int $workspaceId = null, ?string $claimToken = null): void
    {
        $this->updateQueueRowStatus($queueId, 'completed', null, $workspaceId, $claimToken);
    }
    
    /**
     * Mark job as failed
     */
    public function nack(int $queueId, string $error, ?int $workspaceId = null, ?string $claimToken = null): void
    {
        $this->updateQueueRowStatus($queueId, 'failed', substr($error, 0, 500), $workspaceId, $claimToken);
    }
    
    /**
     * Retry failed job
     */
    public function retry(int $queueId, ?int $workspaceId = null, ?string $claimToken = null): void
    {
        $resolvedWorkspaceId = $this->resolveQueueWorkspaceId($queueId, $workspaceId);
        $sql = "SELECT * FROM email_queue WHERE id = ?";
        $params = [$queueId];
        if ($resolvedWorkspaceId !== null && $resolvedWorkspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $resolvedWorkspaceId;
        } else {
            $sql .= " AND workspace_id IS NULL";
        }
        $job = Database::queryOne($sql, $params);
        
        if ($job && $job['attempts'] < $job['max_attempts']) {
            $updateSql = "UPDATE email_queue
                          SET status = 'pending', error_message = NULL, processed_at = NULL,
                              claim_token = NULL, claimed_at = NULL, lease_expires_at = NULL, worker_id = NULL
                          WHERE id = ?";
            $updateParams = [$queueId];
            if ($resolvedWorkspaceId !== null && $resolvedWorkspaceId > 0) {
                $updateSql .= " AND workspace_id = ?";
                $updateParams[] = $resolvedWorkspaceId;
            } else {
                $updateSql .= " AND workspace_id IS NULL";
            }
            if ($claimToken !== null && $claimToken !== '') {
                $updateSql .= " AND claim_token = ? AND status = 'processing'";
                $updateParams[] = $claimToken;
            }
            $updated = Database::execute($updateSql, $updateParams);
            if ($claimToken !== null && $claimToken !== '' && $updated !== 1) {
                throw new \RuntimeException('Email queue claim is no longer owned by this worker.');
            }
        }
    }
    
    /**
     * Get queue statistics
     */
    public function getStats(?int $workspaceId = null): array
    {
        $sql = "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM email_queue";
        $params = [];
        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " WHERE workspace_id = ?";
            $params[] = $workspaceId;
        }

        $stats = Database::queryOne(
            $sql,
            $params
        );
        
        return $stats ?: [
            'total' => 0,
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0
        ];
    }

    private function updateQueueRowStatus(
        int $queueId,
        string $status,
        ?string $errorMessage,
        ?int $workspaceId = null,
        ?string $claimToken = null
    ): void
    {
        $resolvedWorkspaceId = $this->resolveQueueWorkspaceId($queueId, $workspaceId);
        $updates = ['status = ?'];
        $params = [$status];

        if ($errorMessage !== null || $status === 'completed') {
            $updates[] = 'error_message = ?';
            $params[] = $errorMessage;
        }

        $updates[] = 'processed_at = NOW()';
        $updates[] = 'claim_token = NULL';
        $updates[] = 'claimed_at = NULL';
        $updates[] = 'lease_expires_at = NULL';
        $params[] = $queueId;

        $sql = "UPDATE email_queue SET " . implode(', ', $updates) . " WHERE id = ?";
        if ($resolvedWorkspaceId !== null && $resolvedWorkspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $resolvedWorkspaceId;
        } else {
            $sql .= " AND workspace_id IS NULL";
        }

        if ($claimToken !== null && $claimToken !== '') {
            $sql .= " AND claim_token = ? AND status = 'processing'";
            $params[] = $claimToken;
        }

        $updated = Database::execute($sql, $params);
        if ($claimToken !== null && $claimToken !== '' && $updated !== 1) {
            throw new \RuntimeException('Email queue claim is no longer owned by this worker.');
        }
    }

    private function resolveQueueWorkspaceId(int $queueId, ?int $workspaceId = null): ?int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            Database::execute(
                "UPDATE email_queue
                 SET workspace_id = ?
                 WHERE id = ?
                   AND workspace_id IS NULL",
                [$workspaceId, $queueId]
            );
            return $workspaceId;
        }

        $queue = Database::queryOne(
            "SELECT eq.workspace_id, e.workspace_id AS email_workspace_id
             FROM email_queue eq
             LEFT JOIN emails e ON e.id = eq.email_id
             WHERE eq.id = ?
             LIMIT 1",
            [$queueId]
        );

        $resolvedWorkspaceId = (int) ($queue['workspace_id'] ?? $queue['email_workspace_id'] ?? 0);
        if ($resolvedWorkspaceId > 0 && (int) ($queue['workspace_id'] ?? 0) <= 0) {
            Database::execute(
                "UPDATE email_queue
                 SET workspace_id = ?
                 WHERE id = ?
                   AND workspace_id IS NULL",
                [$resolvedWorkspaceId, $queueId]
            );
        }

        return $resolvedWorkspaceId > 0 ? $resolvedWorkspaceId : null;
    }

    private function leaseSeconds(): int
    {
        $configured = (int) (getenv('EMAIL_QUEUE_LEASE_SECONDS') ?: ($_ENV['EMAIL_QUEUE_LEASE_SECONDS'] ?? 300));
        return min(max($configured, 30), 3600);
    }

    private function workerId(): string
    {
        $configured = trim((string) (getenv('QUEUE_WORKER_ID') ?: ($_ENV['QUEUE_WORKER_ID'] ?? '')));
        if ($configured !== '') {
            return substr($configured, 0, 191);
        }

        return substr((gethostname() ?: 'unknown-host') . ':' . (string) getmypid(), 0, 191);
    }

    private function newClaimToken(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
