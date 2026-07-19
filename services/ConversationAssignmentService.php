<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\ConversationThreads;
use CRM\Modules\Notifications;

class ConversationAssignmentService
{
    /**
     * @param array<string,mixed> $actorUser
     * @return array<string,mixed>
     */
    public function assignToUser(int $communicationId, int $actorUserId, array $actorUser, ?int $threadId = null): array
    {
        if ($communicationId <= 0 || $actorUserId <= 0) {
            throw new \RuntimeException('Conversation assignment requires a valid user and message.', 422);
        }

        $communication = $this->getCommunication($communicationId);
        if (!$communication) {
            throw new \RuntimeException('Conversation not found.', 404);
        }

        $workspaceId = (int) ($communication['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        }
        $contactId = (int) ($communication['contact_id'] ?? 0);
        $contactAssignedTo = 0;

        $thread = $this->resolveThread($communication, $threadId);
        if (!$thread) {
            throw new \RuntimeException('This conversation cannot be assigned yet.', 422);
        }

        Database::beginTransaction();
        try {
            $thread = Database::queryOne(
                "SELECT id, metadata_json, current_owner_id
                 FROM conversation_threads
                 WHERE workspace_id = ? AND id = ?
                 LIMIT 1
                 FOR UPDATE",
                [$workspaceId, (int) ($thread['id'] ?? 0)]
            ) ?: null;
            if (!$thread) {
                throw new \RuntimeException('This conversation cannot be assigned yet.', 422);
            }

            $currentOwnerId = (int) ($thread['current_owner_id'] ?? 0);
            $canTransferConversation = $currentOwnerId === 0
                || $currentOwnerId === $actorUserId
                || Authorization::can('conversations.view_all', $actorUser)
                || Authorization::can('contacts.view_all', $actorUser);
            if (!$canTransferConversation) {
                throw new \RuntimeException('Conversation is already claimed by another user.', 409);
            }

            if ($contactId > 0) {
                $contact = Database::queryOne(
                    "SELECT id, assigned_to
                     FROM contacts
                     WHERE workspace_id = ? AND id = ?
                     LIMIT 1
                     FOR UPDATE",
                    [$workspaceId, $contactId]
                ) ?: [];
                $contactAssignedTo = (int) ($contact['assigned_to'] ?? 0);
                $canTransferContact = $contactAssignedTo === 0
                    || $contactAssignedTo === $actorUserId
                    || Authorization::can('contacts.view_all', $actorUser);

                if (!$canTransferContact) {
                    throw new \RuntimeException('Contact is assigned to another user.', 403);
                }
            }

            $metadata = ConversationIntelligenceService::setManualOwnerOverrideMetadata(
                $this->decodeJson($thread['metadata_json'] ?? null),
                $actorUserId
            );

            Database::execute(
                "UPDATE conversation_threads
                 SET current_owner_id = ?,
                     status = 'open',
                     is_resolved = 0,
                     resolved_at = NULL,
                     metadata_json = ?,
                     lock_version = lock_version + 1
                 WHERE workspace_id = ? AND id = ?",
                [$actorUserId, json_encode($metadata), $workspaceId, (int) ($thread['id'] ?? 0)]
            );

            if ($contactId > 0 && $contactAssignedTo !== $actorUserId) {
                Database::execute(
                    "UPDATE contacts
                     SET assigned_to = ?,
                         lock_version = lock_version + 1
                     WHERE workspace_id = ? AND id = ?",
                    [$actorUserId, $workspaceId, $contactId]
                );

                if ($contactAssignedTo === 0) {
                    (new Notifications())->retireContactScopeNotifications($contactId);
                }
            }

            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'thread_id' => (int) ($thread['id'] ?? 0),
            'contact_id' => $contactId > 0 ? $contactId : null,
            'owner_id' => $actorUserId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function unassign(int $communicationId, ?int $threadId = null): array
    {
        if ($communicationId <= 0) {
            throw new \RuntimeException('Conversation assignment requires a valid message.', 422);
        }

        $communication = $this->getCommunication($communicationId);
        if (!$communication) {
            throw new \RuntimeException('Conversation not found.', 404);
        }
        $workspaceId = (int) ($communication['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        }

        $thread = $this->resolveThread($communication, $threadId);
        if (!$thread) {
            throw new \RuntimeException('This conversation cannot be assigned yet.', 422);
        }

        Database::beginTransaction();
        try {
            $thread = Database::queryOne(
                "SELECT id, metadata_json
                 FROM conversation_threads
                 WHERE workspace_id = ? AND id = ?
                 LIMIT 1
                 FOR UPDATE",
                [$workspaceId, (int) ($thread['id'] ?? 0)]
            ) ?: null;
            if (!$thread) {
                throw new \RuntimeException('This conversation cannot be assigned yet.', 422);
            }
            $metadata = ConversationIntelligenceService::setManualOwnerOverrideMetadata(
                $this->decodeJson($thread['metadata_json'] ?? null),
                null
            );

            Database::execute(
                "UPDATE conversation_threads
                 SET current_owner_id = NULL,
                     status = 'open',
                     is_resolved = 0,
                     resolved_at = NULL,
                     metadata_json = ?,
                     lock_version = lock_version + 1
                 WHERE workspace_id = ? AND id = ?",
                [json_encode($metadata), $workspaceId, (int) ($thread['id'] ?? 0)]
            );

            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'thread_id' => (int) ($thread['id'] ?? 0),
            'contact_id' => !empty($communication['contact_id']) ? (int) $communication['contact_id'] : null,
            'owner_id' => null,
        ];
    }

    /**
     * Assign or unassign every conversation thread for one contact as a single
     * inbox unit. The ownership check and writes are atomic.
     *
     * @param array<string,mixed> $actorUser
     * @return array<string,mixed>
     */
    public function setContactConversationOwner(
        int $contactId,
        ?int $ownerId,
        int $actorUserId,
        array $actorUser
    ): array {
        if ($contactId <= 0 || $actorUserId <= 0 || ($ownerId !== null && $ownerId <= 0)) {
            throw new \RuntimeException('Contact conversation assignment requires a valid contact and user.', 422);
        }

        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        Database::beginTransaction();
        try {
            $contact = Database::queryOne(
                "SELECT id, assigned_to
                 FROM contacts
                 WHERE workspace_id = ? AND id = ?
                 LIMIT 1 FOR UPDATE",
                [$workspaceId, $contactId]
            ) ?: null;
            if (!$contact) {
                throw new \RuntimeException('Contact conversation not found.', 404);
            }

            $threads = Database::query(
                "SELECT id, current_owner_id, metadata_json
                 FROM conversation_threads
                 WHERE workspace_id = ? AND contact_id = ?
                 FOR UPDATE",
                [$workspaceId, $contactId]
            );
            $canOverride = Authorization::can('conversations.view_all', $actorUser)
                || Authorization::can('contacts.view_all', $actorUser);
            $contactOwnerId = (int) ($contact['assigned_to'] ?? 0);
            if ($contactOwnerId > 0 && $contactOwnerId !== $actorUserId && !$canOverride) {
                throw new \RuntimeException('Contact is assigned to another user.', 403);
            }
            foreach ($threads as $thread) {
                $threadOwnerId = (int) ($thread['current_owner_id'] ?? 0);
                if ($threadOwnerId > 0 && $threadOwnerId !== $actorUserId && !$canOverride) {
                    throw new \RuntimeException('One or more conversations are claimed by another user.', 409);
                }
            }

            Database::execute(
                "UPDATE contacts
                 SET assigned_to = ?, lock_version = lock_version + 1
                 WHERE workspace_id = ? AND id = ?",
                [$ownerId, $workspaceId, $contactId]
            );

            foreach ($threads as $thread) {
                $metadata = ConversationIntelligenceService::setManualOwnerOverrideMetadata(
                    $this->decodeJson($thread['metadata_json'] ?? null),
                    $ownerId
                );
                Database::execute(
                    "UPDATE conversation_threads
                     SET current_owner_id = ?,
                         status = 'open',
                         is_resolved = 0,
                         resolved_at = NULL,
                         metadata_json = ?,
                         lock_version = lock_version + 1
                     WHERE workspace_id = ? AND id = ?",
                    [$ownerId, json_encode($metadata), $workspaceId, (int) $thread['id']]
                );
            }

            if ($ownerId !== null && $contactOwnerId === 0) {
                (new Notifications())->retireContactScopeNotifications($contactId);
            }
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'contact_id' => $contactId,
            'owner_id' => $ownerId,
            'thread_count' => count($threads),
        ];
    }

    /**
     * @param array<string,mixed> $communication
     * @return array<string,mixed>|null
     */
    private function resolveThread(array $communication, ?int $threadId = null): ?array
    {
        if (($threadId ?? 0) > 0) {
            return Database::queryOne(
                "SELECT *
                 FROM conversation_threads
                 WHERE workspace_id = ?
                   AND id = ?
                 LIMIT 1",
                [(int) ($communication['workspace_id'] ?? 0), $threadId]
            ) ?: null;
        }

        $threads = new ConversationThreads();
        $thread = method_exists($threads, 'findByCommunication')
            ? $threads->findByCommunication((int) ($communication['id'] ?? 0))
            : $threads->getByCommunication((int) ($communication['id'] ?? 0));
        if ($thread) {
            return $thread;
        }

        if (empty($communication['contact_id']) || empty($communication['channel'])) {
            return null;
        }

        return $threads->getOrCreateThread(
            (int) $communication['contact_id'],
            (string) $communication['channel'],
            !empty($communication['thread_key']) ? (string) $communication['thread_key'] : null
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getCommunication(int $communicationId): ?array
    {
        return Database::queryOne(
            "SELECT id, workspace_id, contact_id, channel, thread_key
             FROM communications
             WHERE workspace_id = ?
               AND id = ?
             LIMIT 1",
            [(int) (WorkspaceContext::currentWorkspaceId() ?? 0), $communicationId]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
