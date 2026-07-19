<?php

namespace CRM\Services;

use CRM\Database;

class SMSQueue
{
    public function push(int $messageId, int $priority = 0, ?string $scheduledAt = null): void
    {
        $message = Database::queryOne("SELECT workspace_id FROM sms_messages WHERE id = ? LIMIT 1", [$messageId]);
        $workspaceId = (int) ($message['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('SMS queue message is missing a valid workspace.');
        }

        Database::execute(
            "INSERT INTO sms_queue (message_id, workspace_id, priority, scheduled_at, status)
             VALUES (?, ?, ?, ?, 'pending')",
            [$messageId, $workspaceId, $priority, $scheduledAt]
        );
    }

    public function pop(?int $workspaceId = null, ?int $queueId = null, bool $includeFailed = false): ?array
    {
        $now = date('Y-m-d H:i:s');
        $claimToken = $this->newClaimToken();
        $workerId = $this->workerId();
        $leaseSeconds = $this->leaseSeconds();

        Database::beginTransaction();
        try {
            $failedScope = '';
            $params = [$now, $now];
            if ($includeFailed) {
                $failedScope = " OR q.status = 'failed'";
            }
            $whereParams = [];
            if ($workspaceId !== null && $workspaceId > 0) {
                $scopeWorkspace = ' AND (q.workspace_id = ? OR (q.workspace_id IS NULL AND m.workspace_id = ?))';
                $whereParams[] = $workspaceId;
                $whereParams[] = $workspaceId;
            } else {
                $scopeWorkspace = '';
            }
            if ($queueId !== null && $queueId > 0) {
                $scopeWorkspace .= ' AND q.id = ?';
                $whereParams[] = $queueId;
            }

            $job = Database::queryOne(
                "SELECT q.id AS queue_id, q.workspace_id AS queue_workspace_id, q.message_id,
                        q.priority, q.scheduled_at, q.attempts, q.max_attempts,
                        q.last_attempt_at, q.status AS queue_status, q.created_at AS queue_created_at, m.*
                 FROM sms_queue q
                 INNER JOIN sms_messages m
                    ON m.id = q.message_id
                   AND (q.workspace_id IS NULL OR m.workspace_id = q.workspace_id)
                 WHERE (
                       (q.attempts < q.max_attempts AND (
                           (q.status = 'pending' AND (q.scheduled_at IS NULL OR q.scheduled_at <= ?))
                           OR (q.status = 'processing' AND q.lease_expires_at IS NOT NULL AND q.lease_expires_at <= ?)
                       ))
                       {$failedScope}
                   )
                   {$scopeWorkspace}
                 ORDER BY q.priority DESC, q.created_at ASC, q.id ASC
                 LIMIT 1
                 FOR UPDATE",
                array_merge($params, $whereParams)
            );

            if (!$job) {
                Database::rollBack();
                return null;
            }

            $resolvedWorkspaceId = (int) ($job['queue_workspace_id'] ?? $job['workspace_id'] ?? 0);
            if ($resolvedWorkspaceId <= 0) {
                throw new \RuntimeException('SMS queue item is missing a valid workspace.');
            }
            if ((int) ($job['queue_workspace_id'] ?? 0) <= 0) {
                Database::execute(
                    "UPDATE sms_queue SET workspace_id = ? WHERE id = ? AND workspace_id IS NULL",
                    [$resolvedWorkspaceId, $job['queue_id']]
                );
                $job['queue_workspace_id'] = $resolvedWorkspaceId;
            }

            $updated = Database::execute(
                "UPDATE sms_queue
                 SET status = 'processing', attempts = attempts + 1, last_attempt_at = NOW(),
                     claim_token = ?, claimed_at = NOW(),
                     lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND), worker_id = ?,
                     error_message = NULL, processed_at = NULL
                 WHERE id = ? AND workspace_id = ?",
                [$claimToken, $leaseSeconds, $workerId, $job['queue_id'], $resolvedWorkspaceId]
            );
            if ($updated !== 1) {
                throw new \RuntimeException('The selected SMS queue row could not be claimed.');
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

    public function complete(int $queueId, ?int $workspaceId = null, ?string $claimToken = null): void
    {
        $this->requireClaimToken($claimToken);
        $updated = Database::execute(
            "UPDATE sms_queue
             SET status = 'completed', last_attempt_at = NOW(), processed_at = NOW(), error_message = NULL,
                 claim_token = NULL, claimed_at = NULL, lease_expires_at = NULL, worker_id = NULL
             WHERE id = ? AND workspace_id = ? AND status = 'processing' AND claim_token = ?",
            [$queueId, (int) $workspaceId, $claimToken]
        );
        if ($updated !== 1) {
            throw new \RuntimeException('SMS queue claim is no longer owned by this worker.');
        }
    }

    public function fail(int $queueId, string $errorMessage, ?int $workspaceId = null, ?string $claimToken = null): void
    {
        $this->requireClaimToken($claimToken);
        $queue = Database::queryOne(
            "SELECT message_id, attempts, max_attempts
             FROM sms_queue
             WHERE id = ? AND workspace_id = ? AND status = 'processing' AND claim_token = ?
             LIMIT 1",
            [$queueId, (int) $workspaceId, $claimToken]
        );
        if (!$queue) {
            throw new \RuntimeException('SMS queue claim is no longer owned by this worker.');
        }

        $terminal = (int) $queue['attempts'] >= (int) $queue['max_attempts'];
        $status = $terminal ? 'failed' : 'pending';
        $updated = Database::execute(
            "UPDATE sms_queue
             SET status = ?, last_attempt_at = NOW(), processed_at = ?, error_message = ?,
                 scheduled_at = CASE WHEN ? = 'pending' THEN DATE_ADD(NOW(), INTERVAL 60 SECOND) ELSE scheduled_at END,
                 claim_token = NULL, claimed_at = NULL, lease_expires_at = NULL, worker_id = NULL
             WHERE id = ? AND workspace_id = ? AND status = 'processing' AND claim_token = ?",
            [
                $status,
                $terminal ? date('Y-m-d H:i:s') : null,
                substr($errorMessage, 0, 500),
                $status,
                $queueId,
                (int) $workspaceId,
                $claimToken,
            ]
        );
        if ($updated !== 1) {
            throw new \RuntimeException('SMS queue claim is no longer owned by this worker.');
        }

        if ($terminal) {
            Database::execute(
                "UPDATE sms_messages SET status = 'failed', error_message = ? WHERE workspace_id = ? AND id = ?",
                [substr($errorMessage, 0, 500), (int) $workspaceId, (int) $queue['message_id']]
            );
        }
    }

    private function requireClaimToken(?string $claimToken): void
    {
        if ($claimToken === null || trim($claimToken) === '') {
            throw new \InvalidArgumentException('An SMS queue claim token is required.');
        }
    }

    private function leaseSeconds(): int
    {
        $configured = (int) (getenv('SMS_QUEUE_LEASE_SECONDS') ?: ($_ENV['SMS_QUEUE_LEASE_SECONDS'] ?? 300));
        return min(max($configured, 30), 3600);
    }

    private function workerId(): string
    {
        $configured = trim((string) (getenv('QUEUE_WORKER_ID') ?: ($_ENV['QUEUE_WORKER_ID'] ?? '')));
        return substr($configured !== '' ? $configured : ((gethostname() ?: 'unknown-host') . ':' . getmypid()), 0, 191);
    }

    private function newClaimToken(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
