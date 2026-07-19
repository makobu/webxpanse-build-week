<?php

require_once __DIR__ . '/_bootstrap.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\ConversationThreads;
use CRM\Modules\UnifiedInbox;
use CRM\Services\WorkspaceContext;

function mobileConversationRecord(int $communicationId): ?array
{
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    return Database::queryOne(
        "SELECT c.*, ct.email AS contact_email, ct.first_name, ct.last_name
         FROM communications c
         LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.workspace_id = c.workspace_id
         WHERE c.workspace_id = ?
           AND c.id = ?
         LIMIT 1",
        [$workspaceId, $communicationId]
    );
}

function mobileCanAccessContact(array $contact, int $viewerUserId, array $viewer): bool
{
    if (Authorization::can('contacts.view_all', $viewer)) {
        return true;
    }
    $assignedTo = (int) ($contact['assigned_to'] ?? 0);
    return $assignedTo === 0 || $assignedTo === $viewerUserId;
}

function mobileLinkConversationToContact(int $communicationId, int $contactId): void
{
    $communication = mobileConversationRecord($communicationId);
    if (!$communication) {
        throw new RuntimeException('Conversation not found.');
    }

    if (!empty($communication['thread_key'])) {
        Database::execute(
            "UPDATE communications
             SET contact_id = ?
             WHERE workspace_id = ?
               AND channel = ?
               AND thread_key = ?",
            [$contactId, (int) (WorkspaceContext::currentWorkspaceId() ?? 0), (string) ($communication['channel'] ?? ''), (string) $communication['thread_key']]
        );
    } else {
        Database::execute(
            "UPDATE communications SET contact_id = ? WHERE workspace_id = ? AND id = ?",
            [$contactId, (int) (WorkspaceContext::currentWorkspaceId() ?? 0), $communicationId]
        );
    }

    if (!empty($communication['channel'])) {
        $threads = new ConversationThreads();
        $threads->getOrCreateThread(
            $contactId,
            (string) $communication['channel'],
            !empty($communication['thread_key']) ? (string) $communication['thread_key'] : null
        );
    }
}

function mobileResolveConversationTarget(
    UnifiedInbox $inbox,
    int $communicationId,
    int $threadId,
    int $userId,
    bool $canViewAll,
    ?string $ownerScope = null
): array {
    $resolvedCommunicationId = $communicationId;
    $resolvedThreadId = $threadId;

    if ($resolvedThreadId > 0) {
        if (!$inbox->canUserAccessThread($resolvedThreadId, $userId, $canViewAll, $ownerScope)) {
            return ['communication_id' => 0, 'thread_id' => 0];
        }
        $latest = $inbox->getLatestCommunicationForThread($resolvedThreadId);
        $resolvedCommunicationId = (int) ($latest['id'] ?? $resolvedCommunicationId);
    }

    if ($resolvedCommunicationId > 0) {
        if (!$inbox->canUserAccessCommunication($resolvedCommunicationId, $userId, $canViewAll, $ownerScope)) {
            if (
                $resolvedThreadId <= 0
                && $inbox->canUserAccessThread($resolvedCommunicationId, $userId, $canViewAll, $ownerScope)
            ) {
                $resolvedThreadId = $resolvedCommunicationId;
                $latest = $inbox->getLatestCommunicationForThread($resolvedThreadId);
                $resolvedCommunicationId = (int) ($latest['id'] ?? 0);
            } else {
                return ['communication_id' => 0, 'thread_id' => 0];
            }
        }
        if ($resolvedThreadId <= 0) {
            $thread = (new ConversationThreads())->getByCommunication($resolvedCommunicationId);
            $resolvedThreadId = (int) ($thread['id'] ?? 0);
        }
    }

    return [
        'communication_id' => $resolvedCommunicationId,
        'thread_id' => $resolvedThreadId,
    ];
}
