<?php
/**
 * Conversation Threading Module
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\ConversationIntelligenceService;
use CRM\Services\WorkspaceScopeService;

class ConversationThreads
{
    private ConversationIntelligenceService $intelligence;
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->intelligence = new ConversationIntelligenceService();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function getOrCreateThread(int $contactId, string $channel, ?string $threadKey = null): array
    {
        $workspaceId = $this->workspaceId();
        $threadKey = trim((string) $threadKey);
        if ($threadKey === '') {
            $threadKey = strtolower($channel) . ':contact:' . $contactId;
        }
        $this->workspaceScope->assertSameWorkspace('contacts', $contactId, $workspaceId);

        $thread = Database::queryOne(
            "SELECT * FROM conversation_threads WHERE workspace_id = ? AND thread_key = ? LIMIT 1",
            [$workspaceId, $threadKey]
        );

        if (!$thread) {
            try {
                Database::execute(
                    "INSERT INTO conversation_threads (workspace_id, contact_id, channel, thread_key, last_message_at, last_channel, status)
                     VALUES (?, ?, ?, ?, NOW(), ?, 'open')",
                    [$workspaceId, $contactId, $channel, $threadKey, $channel]
                );
            } catch (\Throwable $e) {
                // A concurrent sync may have created the same thread_key between SELECT and INSERT.
                $thread = Database::queryOne(
                    "SELECT * FROM conversation_threads WHERE workspace_id = ? AND thread_key = ? LIMIT 1",
                    [$workspaceId, $threadKey]
                );
                if ($thread) {
                    $thread['_state'] = $this->intelligence->buildStateForThread($thread);
                    return $thread;
                }
                throw $e;
            }
            $thread = Database::queryOne(
                "SELECT * FROM conversation_threads WHERE workspace_id = ? AND id = ? LIMIT 1",
                [$workspaceId, (int) Database::lastInsertId()]
            );
        }

        if ($thread) {
            $thread['_state'] = $this->intelligence->buildStateForThread($thread);
        }

        return $thread ?: [];
    }

    public function updateThread(int $contactId, string $channel, ?int $communicationId = null): void
    {
        if ($communicationId !== null && $communicationId > 0) {
            $this->intelligence->syncForCommunication($communicationId);
            return;
        }

        $thread = $this->getOrCreateThread($contactId, $channel);
        if (!$thread) {
            return;
        }

        Database::execute(
            "UPDATE conversation_threads
             SET last_message_at = NOW(),
                 message_count = message_count + 1,
                 status = 'open',
                 is_resolved = FALSE,
                 resolved_at = NULL
             WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId(), (int) $thread['id']]
        );
    }

    public function getByContact(int $contactId): array
    {
        $rows = Database::query(
            "SELECT * FROM conversation_threads
             WHERE workspace_id = ?
               AND contact_id = ?
             ORDER BY COALESCE(response_due_at, last_message_at) ASC, last_message_at DESC",
            [$this->workspaceId(), $contactId]
        );

        foreach ($rows as &$row) {
            $row['_state'] = $this->intelligence->buildStateForThread($row);
        }
        unset($row);

        return $rows;
    }

    public function getActive(int $limit = 50, int $offset = 0): array
    {
        $rows = Database::query(
            "SELECT ct.*, c.first_name, c.last_name, c.email
             FROM conversation_threads ct
             JOIN contacts c ON ct.contact_id = c.id AND c.workspace_id = ct.workspace_id
             WHERE ct.workspace_id = ?
               AND ct.status <> 'resolved'
             ORDER BY
                CASE WHEN ct.response_due_at IS NULL THEN 1 ELSE 0 END,
                ct.response_due_at ASC,
                ct.last_message_at DESC
             LIMIT ? OFFSET ?",
            [$this->workspaceId(), $limit, $offset]
        );

        foreach ($rows as &$row) {
            $row['_state'] = $this->intelligence->buildStateForThread($row);
        }
        unset($row);

        return $rows;
    }

    public function resolveThread(int $contactId, string $channel, ?string $reason = null): void
    {
        $thread = Database::queryOne(
            "SELECT * FROM conversation_threads WHERE workspace_id = ? AND contact_id = ? AND channel = ? ORDER BY last_message_at DESC LIMIT 1",
            [$this->workspaceId(), $contactId, $channel]
        );
        if (!$thread) {
            return;
        }

        Database::execute(
            "UPDATE conversation_threads
             SET status = 'resolved',
                 is_resolved = TRUE,
                 resolved_at = NOW(),
                 resolution_reason = ?
             WHERE workspace_id = ? AND id = ?",
            [$reason, $this->workspaceId(), (int) $thread['id']]
        );
    }

    public function getByCommunication(int $communicationId): ?array
    {
        return $this->intelligence->getThreadForCommunication($communicationId);
    }

    public function findByCommunication(int $communicationId): ?array
    {
        $communication = Database::queryOne(
            "SELECT contact_id, channel, thread_key
             FROM communications
             WHERE workspace_id = ?
               AND id = ?
             LIMIT 1",
            [$this->workspaceId(), $communicationId]
        );

        if (!$communication) {
            return null;
        }

        $threadKey = trim((string) ($communication['thread_key'] ?? ''));
        if ($threadKey !== '') {
            $thread = Database::queryOne(
                "SELECT *
                 FROM conversation_threads
                 WHERE workspace_id = ?
                   AND thread_key = ?
                 LIMIT 1",
                [$this->workspaceId(), $threadKey]
            );
        } else {
            $thread = Database::queryOne(
                "SELECT *
                 FROM conversation_threads
                 WHERE workspace_id = ?
                   AND contact_id = ?
                   AND channel = ?
                 ORDER BY last_message_at DESC, id DESC
                 LIMIT 1",
                [$this->workspaceId(), (int) ($communication['contact_id'] ?? 0), (string) ($communication['channel'] ?? '')]
            );
        }

        if (!$thread) {
            return null;
        }

        $thread['_state'] = $this->intelligence->buildStateForThread($thread);
        return $thread;
    }

    public function getCommunications(int $threadId): array
    {
        $thread = Database::queryOne(
            "SELECT contact_id, channel, thread_key FROM conversation_threads WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$this->workspaceId(), $threadId]
        );

        if (!$thread) {
            return [];
        }

        $threadKey = trim((string) ($thread['thread_key'] ?? ''));
        if ($threadKey !== '') {
            return Database::query(
                "SELECT *
                 FROM communications
                 WHERE workspace_id = ?
                   AND thread_key = ?
                 ORDER BY created_at ASC",
                [$this->workspaceId(), $threadKey]
            );
        }

        return Database::query(
            "SELECT *
             FROM communications
             WHERE workspace_id = ? AND contact_id = ? AND channel = ?
             ORDER BY created_at ASC",
            [$this->workspaceId(), (int) $thread['contact_id'], (string) $thread['channel']]
        );
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }
}
