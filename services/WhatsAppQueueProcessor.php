<?php
/**
 * WhatsApp Queue Processor
 *
 * Processes pending WhatsApp messages. Can be run from web (button click) or cron.
 * Ensures messages are marked as sent immediately after API success - never retries
 * a message that was already delivered.
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Services\AsyncWorkspaceRunner;
use CRM\Services\WorkspaceContext;

class WhatsAppQueueProcessor
{
    private WhatsAppQueue $queue;
    private ColdOutreachGovernanceService $coldOutreachGovernance;

    public function __construct()
    {
        $this->queue = new WhatsAppQueue();
        $this->coldOutreachGovernance = new ColdOutreachGovernanceService();
    }

    /**
     * Process up to $limit pending jobs. Returns stats.
     */
    public function process(int $limit = 10, ?int $workspaceId = null): array
    {
        $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];
        $queueWorkspaceScope = $workspaceId;
        if ($queueWorkspaceScope === null) {
            $activeWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
            $queueWorkspaceScope = $activeWorkspaceId > 0 ? $activeWorkspaceId : null;
        }

        for ($i = 0; $i < $limit; $i++) {
            $job = $this->queue->pop($queueWorkspaceScope);
            if (!$job) {
                break;
            }

            $stats['processed']++;

            $result = null;
            $jobWorkspaceId = (int) ($job['workspace_id'] ?? $job['queue_workspace_id'] ?? 0);
            try {
                $result = AsyncWorkspaceRunner::runWithWorkspace(
                    $jobWorkspaceId,
                    function () use ($job): array {
                        $whatsappService = $this->createWhatsAppService();
                        if ($job['message_type'] === 'template') {
                            $templateParams = json_decode($job['template_params'] ?? '{}', true) ?? [];
                            $languageCode = $job['language_code'] ?? $templateParams['_language'] ?? 'en_US';
                            $templateStructure = $whatsappService->getTemplateByName($job['template_name'], $languageCode);
                            $components = $whatsappService->buildTemplateComponents($templateParams, $templateStructure);
                            return $whatsappService->sendTemplateMessage(
                                $job['to_number'],
                                $job['template_name'],
                                $languageCode,
                                $components
                            );
                        }

                        return $whatsappService->sendTextMessage(
                            $job['to_number'],
                            $job['message_body']
                        );
                    },
                    null,
                    'WhatsApp queue item is missing a valid workspace.'
                );
            } catch (\Exception $e) {
                $stats['failed']++;
                $stats['errors'][] = "Message {$job['message_id']}: " . $e->getMessage();

                $isRateLimit = (strpos($e->getMessage(), '131056') !== false || stripos($e->getMessage(), 'rate limit') !== false);
                if ($isRateLimit && ($job['attempts'] ?? 0) < ($job['max_attempts'] ?? 3)) {
                    $this->queue->releaseForRetry($job['queue_id'], 3600, $jobWorkspaceId);
                } elseif (($job['attempts'] ?? 0) >= ($job['max_attempts'] ?? 3)) {
                    $this->queue->nack($job['queue_id'], $e->getMessage(), $jobWorkspaceId);
                    try {
                        Database::execute(
                            "UPDATE whatsapp_messages SET status = 'failed', error_message = ? WHERE workspace_id = ? AND id = ?",
                            [substr($e->getMessage(), 0, 500), $jobWorkspaceId, $job['message_id']]
                        );
                        (new WorkspaceWhatsAppCreditService())->releaseForMessage($jobWorkspaceId, (int) $job['message_id'], 'send_failed');
                        $this->coldOutreachGovernance->markByEntity('whatsapp', (int) $job['message_id'], 'failed');
                    } catch (\Throwable $_) {}
                } else {
                    $this->queue->releaseForRetry($job['queue_id'], 300, $jobWorkspaceId);
                }
                usleep(500000);
                continue;
            } finally {
            }

            if ($result === null) {
                continue;
            }

            $stats['sent']++;

            $whatsappMessageId = $result['messages'][0]['id'] ?? '';
            try {
                Database::execute(
                    "UPDATE whatsapp_messages
                     SET whatsapp_message_id = ?, status = 'sent', sent_at = NOW()
                     WHERE workspace_id = ?
                       AND id = ?",
                    [$whatsappMessageId, $jobWorkspaceId, $job['message_id']]
                );
                $this->coldOutreachGovernance->markByEntity('whatsapp', (int) $job['message_id'], 'sent');
                // Update communications metadata with whatsapp_message_id for status webhook matching
                if ($whatsappMessageId !== '') {
                    $wm = Database::queryOne(
                        "SELECT uuid FROM whatsapp_messages WHERE workspace_id = ? AND id = ?",
                        [$jobWorkspaceId, $job['message_id']]
                    );
                    if ($wm) {
                        $comm = Database::queryOne(
                            "SELECT id, metadata
                             FROM communications
                             WHERE workspace_id = ?
                               AND channel = 'whatsapp'
                               AND direction = 'outbound'
                               AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp_uuid')) = ?",
                            [$jobWorkspaceId, $wm['uuid']]
                        );
                        if ($comm) {
                            $meta = is_string($comm['metadata'] ?? '') ? json_decode($comm['metadata'], true) : ($comm['metadata'] ?? []);
                            $meta = is_array($meta) ? $meta : [];
                            $meta['whatsapp_message_id'] = $whatsappMessageId;
                            $meta['whatsapp_sent_at'] = date('Y-m-d H:i:s');
                            Database::execute(
                                "UPDATE communications SET status = 'sent', metadata = ? WHERE workspace_id = ? AND id = ?",
                                [json_encode($meta), $jobWorkspaceId, $comm['id']]
                            );
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log("WhatsAppQueueProcessor: Failed to update message {$job['message_id']}: " . $e->getMessage());
            }

            $this->queue->ack($job['queue_id'], $jobWorkspaceId);
            usleep(1000000);
        }

        return $stats;
    }

    /**
     * Process a specific queue row inside its stored workspace.
     *
     * @return array{processed:int,sent:int,failed:int,errors:array<int,string>}
     */
    public function processQueueItem(int $queueId, ?int $workspaceId = null): array
    {
        $job = $this->loadQueueJob($queueId, $workspaceId);
        if ($job === null) {
            throw new \RuntimeException('WhatsApp queue row not found for this workspace.');
        }

        $resolvedWorkspaceId = (int) ($job['workspace_id'] ?? $job['queue_workspace_id'] ?? 0);
        if ($resolvedWorkspaceId <= 0) {
            throw new \RuntimeException('WhatsApp queue item is missing a valid workspace.');
        }

        $claimed = Database::execute(
            "UPDATE whatsapp_queue
             SET status = 'processing', attempts = attempts + 1, processed_at = NULL
             WHERE id = ?
               AND workspace_id = ?
               AND status IN ('failed', 'pending')",
            [$queueId, $resolvedWorkspaceId]
        );

        if ($claimed === 0) {
            throw new \RuntimeException('WhatsApp queue row is not replayable in its current state.');
        }

        $job['queue_workspace_id'] = $resolvedWorkspaceId;
        $job['workspace_id'] = $resolvedWorkspaceId;

        return $this->processClaimedJob($job);
    }

    public function getPendingCount(): int
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $sql = "SELECT COUNT(*) as c FROM whatsapp_queue wq
             JOIN whatsapp_messages wm ON wq.message_id = wm.id
             WHERE wq.status = 'pending' AND (wq.scheduled_at IS NULL OR wq.scheduled_at <= NOW())";
        $params = [];
        if ($workspaceId > 0) {
            $sql .= " AND wq.workspace_id = ?";
            $params[] = $workspaceId;
        }

        $row = Database::queryOne(
            $sql,
            $params
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Clear all pending messages from the queue without sending.
     * Removes queue entries and marks related whatsapp_messages as failed (cancelled).
     */
    public function clearPending(): int
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $sql = "SELECT message_id FROM whatsapp_queue WHERE status = 'pending'";
        $params = [];
        if ($workspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $workspaceId;
        }
        $rows = Database::query($sql, $params);
        $messageIds = array_column($rows, 'message_id');
        if (empty($messageIds)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $deleteSql = "DELETE FROM whatsapp_queue WHERE status = 'pending'";
        $deleteParams = [];
        if ($workspaceId > 0) {
            $deleteSql .= " AND workspace_id = ?";
            $deleteParams[] = $workspaceId;
        }
        Database::execute($deleteSql, $deleteParams);
        Database::execute(
            "UPDATE whatsapp_messages
             SET status = 'failed', error_message = 'Cancelled by user'
             WHERE id IN ($placeholders)
               AND status = 'pending'" . ($workspaceId > 0 ? " AND workspace_id = ?" : ""),
            $workspaceId > 0 ? array_merge($messageIds, [$workspaceId]) : $messageIds
        );
        foreach ($messageIds as $messageId) {
            try {
                $releaseWorkspaceId = $workspaceId;
                if ($releaseWorkspaceId <= 0) {
                    $message = Database::queryOne(
                        "SELECT workspace_id FROM whatsapp_messages WHERE id = ? LIMIT 1",
                        [$messageId]
                    );
                    $releaseWorkspaceId = (int) ($message['workspace_id'] ?? 0);
                }
                if ($releaseWorkspaceId > 0) {
                    (new WorkspaceWhatsAppCreditService())->releaseForMessage($releaseWorkspaceId, (int) $messageId, 'cancelled');
                }
            } catch (\Throwable $_) {
            }
            $this->coldOutreachGovernance->markByEntity('whatsapp', (int) $messageId, 'cancelled');
        }
        return count($messageIds);
    }

    private function loadQueueJob(int $queueId, ?int $workspaceId = null): ?array
    {
        $sql = "SELECT
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
                JOIN whatsapp_messages wm
                  ON wm.id = wq.message_id
                 AND (wq.workspace_id IS NULL OR wm.workspace_id = wq.workspace_id)
                WHERE wq.id = ?";
        $params = [$queueId];
        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " AND wq.workspace_id = ?";
            $params[] = $workspaceId;
        }
        $sql .= " LIMIT 1";

        $job = Database::queryOne($sql, $params);
        if (!$job) {
            return null;
        }

        $resolvedWorkspaceId = (int) ($job['queue_workspace_id'] ?? $job['workspace_id'] ?? 0);
        if ($resolvedWorkspaceId > 0 && (int) ($job['queue_workspace_id'] ?? 0) <= 0) {
            Database::execute(
                "UPDATE whatsapp_queue
                 SET workspace_id = ?
                 WHERE id = ?
                   AND workspace_id IS NULL",
                [$resolvedWorkspaceId, $queueId]
            );
            $job['queue_workspace_id'] = $resolvedWorkspaceId;
        }

        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array{processed:int,sent:int,failed:int,errors:array<int,string>}
     */
    private function processClaimedJob(array $job): array
    {
        $stats = ['processed' => 1, 'sent' => 0, 'failed' => 0, 'errors' => []];
        $workspaceId = (int) ($job['workspace_id'] ?? $job['queue_workspace_id'] ?? 0);
        $result = null;

        try {
            $result = AsyncWorkspaceRunner::runWithWorkspace(
                $workspaceId,
                function () use ($job): array {
                    $whatsappService = $this->createWhatsAppService();
                    if ($job['message_type'] === 'template') {
                        $templateParams = json_decode($job['template_params'] ?? '{}', true) ?? [];
                        $languageCode = $job['language_code'] ?? $templateParams['_language'] ?? 'en_US';
                        $templateStructure = $whatsappService->getTemplateByName($job['template_name'], $languageCode);
                        $components = $whatsappService->buildTemplateComponents($templateParams, $templateStructure);
                        return $whatsappService->sendTemplateMessage(
                            $job['to_number'],
                            $job['template_name'],
                            $languageCode,
                            $components
                        );
                    }

                    return $whatsappService->sendTextMessage(
                        $job['to_number'],
                        $job['message_body']
                    );
                },
                null,
                'WhatsApp queue item is missing a valid workspace.'
            );
        } catch (\Exception $e) {
            $stats['failed']++;
            $stats['errors'][] = "Message {$job['message_id']}: " . $e->getMessage();

            $isRateLimit = (strpos($e->getMessage(), '131056') !== false || stripos($e->getMessage(), 'rate limit') !== false);
            if ($isRateLimit && ($job['attempts'] ?? 0) < ($job['max_attempts'] ?? 3)) {
                $this->queue->releaseForRetry((int) $job['queue_id'], 3600, $workspaceId);
            } elseif (($job['attempts'] ?? 0) >= ($job['max_attempts'] ?? 3)) {
                $this->queue->nack((int) $job['queue_id'], $e->getMessage(), $workspaceId);
                try {
                    Database::execute(
                        "UPDATE whatsapp_messages SET status = 'failed', error_message = ? WHERE workspace_id = ? AND id = ?",
                        [substr($e->getMessage(), 0, 500), $workspaceId, $job['message_id']]
                    );
                    (new WorkspaceWhatsAppCreditService())->releaseForMessage($workspaceId, (int) $job['message_id'], 'send_failed');
                    $this->coldOutreachGovernance->markByEntity('whatsapp', (int) $job['message_id'], 'failed');
                } catch (\Throwable $_) {
                }
            } else {
                $this->queue->releaseForRetry((int) $job['queue_id'], 300, $workspaceId);
            }

            return $stats;
        }

        if ($result === null) {
            return $stats;
        }

        $stats['sent']++;
        $whatsappMessageId = $result['messages'][0]['id'] ?? '';

        try {
            Database::execute(
                "UPDATE whatsapp_messages
                 SET whatsapp_message_id = ?, status = 'sent', sent_at = NOW()
                 WHERE workspace_id = ?
                   AND id = ?",
                [$whatsappMessageId, $workspaceId, $job['message_id']]
            );
            $this->coldOutreachGovernance->markByEntity('whatsapp', (int) $job['message_id'], 'sent');
            if ($whatsappMessageId !== '') {
                $wm = Database::queryOne("SELECT uuid FROM whatsapp_messages WHERE workspace_id = ? AND id = ?", [$workspaceId, $job['message_id']]);
                if ($wm) {
                    $comm = Database::queryOne(
                        "SELECT id, metadata
                         FROM communications
                         WHERE workspace_id = ?
                           AND channel = 'whatsapp'
                           AND direction = 'outbound'
                           AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp_uuid')) = ?",
                        [$workspaceId, $wm['uuid']]
                    );
                    if ($comm) {
                        $meta = is_string($comm['metadata'] ?? '') ? json_decode($comm['metadata'], true) : ($comm['metadata'] ?? []);
                        $meta = is_array($meta) ? $meta : [];
                        $meta['whatsapp_message_id'] = $whatsappMessageId;
                        $meta['whatsapp_sent_at'] = date('Y-m-d H:i:s');
                        Database::execute(
                            "UPDATE communications SET status = 'sent', metadata = ? WHERE workspace_id = ? AND id = ?",
                            [json_encode($meta), $workspaceId, $comm['id']]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("WhatsAppQueueProcessor: Failed to update message {$job['message_id']}: " . $e->getMessage());
        }

        $this->queue->ack((int) $job['queue_id'], $workspaceId);
        return $stats;
    }

    protected function createWhatsAppService(): WhatsAppService
    {
        // Construct only after AsyncWorkspaceRunner activates the job workspace.
        return new WhatsAppService();
    }
}
