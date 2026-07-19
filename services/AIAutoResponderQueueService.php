<?php
/**
 * AI Auto-responder Queue Service
 * Enqueues normalized inbound communications and processes pending items.
 */

namespace CRM\Services;

use CRM\Database;

class AIAutoResponderQueueService
{
    private const STALE_PROCESSING_MINUTES = 15;

    /**
     * Queue contract fields expected by the worker:
     * communication_id, contact_id, channel, direction, message_text, normalized_payload.
     */
    public function enqueueInboundCommunication(
        int $communicationId,
        int $contactId,
        string $channel,
        string $messageText,
        array $metadata = []
    ): ?int {
        if ($communicationId <= 0 || $contactId <= 0 || trim($messageText) === '') {
            return null;
        }

        $workspaceId = $this->resolveWorkspaceId($communicationId, $contactId);
        if ($workspaceId <= 0) {
            return null;
        }

        $channel = $this->normalizeChannel($channel);
        $normalizedPayload = [
            'workspace_id' => $workspaceId,
            'communication_id' => $communicationId,
            'contact_id' => $contactId,
            'channel' => $channel,
            'direction' => 'inbound',
            'message_text' => trim($messageText),
            'metadata' => $metadata,
        ];

        $externalId = '';
        if (isset($metadata['whatsapp_message_id'])) {
            $externalId = (string) $metadata['whatsapp_message_id'];
        } elseif (isset($metadata['message_sid'])) {
            $externalId = (string) $metadata['message_sid'];
        } elseif (isset($metadata['message_id'])) {
            $externalId = (string) $metadata['message_id'];
        }

        $dedupeKey = hash('sha256', implode('|', [
            $workspaceId,
            $communicationId,
            $contactId,
            $channel,
            strtolower(trim($externalId)),
            strtolower(trim(substr($messageText, 0, 200))),
        ]));

        $existing = Database::queryOne(
            "SELECT id
             FROM ai_autoresponder_queue
             WHERE workspace_id = ?
               AND dedupe_key = ?
             LIMIT 1",
            [$workspaceId, $dedupeKey]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        Database::execute(
            "INSERT INTO ai_autoresponder_queue
             (workspace_id, communication_id, contact_id, channel, direction, message_text, normalized_payload, dedupe_key, status, available_at)
             VALUES (?, ?, ?, ?, 'inbound', ?, ?, ?, 'pending', NOW())",
            [
                $workspaceId,
                $communicationId,
                $contactId,
                $channel,
                trim($messageText),
                json_encode($normalizedPayload),
                $dedupeKey,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function fetchPending(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $this->requeueStaleProcessing();
        return Database::query(
            "SELECT *
             FROM ai_autoresponder_queue
             WHERE status = 'pending'
               AND available_at <= NOW()
             ORDER BY created_at ASC
             LIMIT {$limit}"
        );
    }

    public function markProcessing(int $queueId, ?int $workspaceId = null): void
    {
        $sql = "UPDATE ai_autoresponder_queue
                SET status = 'processing', attempts = attempts + 1, updated_at = NOW()
                WHERE id = ?";
        $params = [$queueId];
        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $workspaceId;
        }
        Database::execute($sql, $params);
    }

    public function markCompleted(int $queueId, ?int $workspaceId = null): void
    {
        $sql = "UPDATE ai_autoresponder_queue
                SET status = 'completed', processed_at = NOW(), updated_at = NOW()
                WHERE id = ?";
        $params = [$queueId];
        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $workspaceId;
        }
        Database::execute($sql, $params);
    }

    public function markSkipped(int $queueId, string $reason, ?int $workspaceId = null): void
    {
        $sql = "UPDATE ai_autoresponder_queue
                SET status = 'skipped', error_message = ?, processed_at = NOW(), updated_at = NOW()
                WHERE id = ?";
        $params = [substr($reason, 0, 500), $queueId];
        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $workspaceId;
        }
        Database::execute($sql, $params);
    }

    public function markFailed(int $queueId, string $errorMessage, int $retryDelaySeconds = 30, ?int $workspaceId = null): void
    {
        $sql = "SELECT attempts, max_attempts FROM ai_autoresponder_queue WHERE id = ?";
        $params = [$queueId];
        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $workspaceId;
        }
        $row = Database::queryOne($sql, $params);
        $attempts = (int) ($row['attempts'] ?? 0);
        $maxAttempts = (int) ($row['max_attempts'] ?? 3);

        if ($attempts >= $maxAttempts) {
            $updateSql = "UPDATE ai_autoresponder_queue
                          SET status = 'failed', error_message = ?, processed_at = NOW(), updated_at = NOW()
                          WHERE id = ?";
            $updateParams = [substr($errorMessage, 0, 500), $queueId];
            if ($workspaceId !== null && $workspaceId > 0) {
                $updateSql .= " AND workspace_id = ?";
                $updateParams[] = $workspaceId;
            }
            Database::execute($updateSql, $updateParams);
            return;
        }

        $retryDelaySeconds = max(5, min(600, $retryDelaySeconds));
        $updateSql = "UPDATE ai_autoresponder_queue
                      SET status = 'pending',
                          error_message = ?,
                          available_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                          updated_at = NOW()
                      WHERE id = ?";
        $updateParams = [substr($errorMessage, 0, 500), $retryDelaySeconds, $queueId];
        if ($workspaceId !== null && $workspaceId > 0) {
            $updateSql .= " AND workspace_id = ?";
            $updateParams[] = $workspaceId;
        }
        Database::execute($updateSql, $updateParams);
    }

    public function requeueStaleProcessing(int $staleMinutes = self::STALE_PROCESSING_MINUTES): int
    {
        $staleMinutes = max(1, min(120, $staleMinutes));
        return Database::execute(
            "UPDATE ai_autoresponder_queue
             SET status = 'pending',
                 error_message = CASE
                     WHEN error_message IS NULL OR error_message = ''
                     THEN 'Recovered from stale processing state'
                     ELSE error_message
                 END,
                 available_at = NOW(),
                 updated_at = NOW()
             WHERE status = 'processing'
               AND updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$staleMinutes]
        );
    }

    private function normalizeChannel(string $channel): string
    {
        $normalized = strtolower(trim($channel));
        return in_array($normalized, ['email', 'whatsapp', 'sms', 'web_chat'], true) ? $normalized : 'email';
    }

    private function resolveWorkspaceId(int $communicationId, int $contactId): int
    {
        $contextWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($contextWorkspaceId > 0) {
            return $contextWorkspaceId;
        }

        $communication = Database::queryOne(
            "SELECT workspace_id
             FROM communications
             WHERE id = ?
             LIMIT 1",
            [$communicationId]
        );
        $workspaceId = (int) ($communication['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        $contact = Database::queryOne(
            "SELECT workspace_id
             FROM contacts
             WHERE id = ?
             LIMIT 1",
            [$contactId]
        );

        return (int) ($contact['workspace_id'] ?? 0);
    }
}
