<?php

require_once __DIR__ . '/_serializers.php';
require_once __DIR__ . '/ai/_helpers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\ConversationThreads;
use CRM\Modules\Deals;
use CRM\Modules\Documents;
use CRM\Modules\Notes;
use CRM\Modules\Tasks;
use CRM\Modules\UnifiedInbox;
use CRM\Services\WorkspaceScopeService;

function mobileConversationDetailData(
    int $communicationId,
    ?int $viewerUserId = null,
    ?int $threadId = null,
    bool $includeAi = false,
    ?int $contactConversationId = null,
    array $visibilityFilters = []
): array
{
    $workspaceScope = new WorkspaceScopeService();
    $threadWorkspace = $workspaceScope->workspaceClause();
    $communicationWorkspace = $workspaceScope->workspaceClause('c.');
    $threadModule = new ConversationThreads();
    $resolvedThread = null;

    if (($contactConversationId ?? 0) > 0) {
        $messages = (new UnifiedInbox())->getContactConversationMessages(
            (int) $contactConversationId,
            $visibilityFilters
        );
        if ($messages !== []) {
            $latestMessage = end($messages);
            $communicationId = (int) ($latestMessage['id'] ?? $communicationId);
            $resolvedThread = $threadModule->getByCommunication($communicationId);
        }
    } elseif (($threadId ?? 0) > 0) {
        $resolvedThread = Database::queryOne(
            "SELECT *
             FROM conversation_threads
             WHERE {$threadWorkspace['sql']}
               AND id = ?
             LIMIT 1",
            array_merge($threadWorkspace['params'], [$threadId])
        ) ?: null;
    }

    if (!$resolvedThread && $communicationId > 0) {
        $resolvedThread = $threadModule->getByCommunication($communicationId);
    }

    $messages = $messages ?? [];
    if ($messages === [] && !empty($resolvedThread['id'])) {
        $messages = $threadModule->getCommunications((int) $resolvedThread['id']);
    }

    if ($messages === [] && $communicationId > 0) {
        $communication = Database::queryOne(
            "SELECT c.*
             FROM communications c
             WHERE {$communicationWorkspace['sql']}
               AND c.id = ?
             LIMIT 1",
            array_merge($communicationWorkspace['params'], [$communicationId])
        );

        if ($communication) {
            $messages = Database::query(
                "SELECT *
                 FROM communications
                 WHERE workspace_id = ?
                   AND contact_id <=> ? AND channel = ?
                 ORDER BY created_at ASC
                 LIMIT 100",
                [$workspaceScope->requireActiveWorkspaceId(), $communication['contact_id'] ?? null, $communication['channel'] ?? '']
            );
        }
    }

    if ($messages === []) {
        throw new RuntimeException('Conversation not found.');
    }

    $canonicalMessage = mobileResolveCanonicalConversationMessage(
        $messages,
        $communicationId
    );
    $communication = Database::queryOne(
        "SELECT c.*, ct.first_name, ct.last_name, ct.email AS contact_email
         FROM communications c
         LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.workspace_id = c.workspace_id
         WHERE {$communicationWorkspace['sql']} AND c.id = ? LIMIT 1",
        array_merge($communicationWorkspace['params'], [(int) ($canonicalMessage['id'] ?? 0)])
    );

    if (!$communication) {
        throw new RuntimeException('Conversation not found.');
    }

    $thread = $resolvedThread ?: ($communicationId > 0
        ? $threadModule->getByCommunication($communicationId)
        : null);
    $messageIds = array_values(array_filter(array_map(
        static fn(array $row): int => (int) ($row['id'] ?? 0),
        $messages
    )));

    $contactId = !empty($communication['contact_id']) ? (int) $communication['contact_id'] : 0;
    $contacts = new Contacts();
    $deals = new Deals();
    $notes = new Notes();
    $tasks = new Tasks();
    $documents = new Documents();
    $viewerUser = $viewerUserId !== null && $viewerUserId > 0
        ? (Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$viewerUserId]) ?? [])
        : [];
    $canViewAllTasks = $viewerUser !== [] && Authorization::can('tasks.view_all', $viewerUser);
    $taskFilters = $contactId > 0
        ? array_filter([
            'contact_id' => $contactId,
            'assigned_to' => $canViewAllTasks ? null : $viewerUserId,
        ], static fn($value): bool => $value !== null)
        : [];
    $internalNotes = $contactId > 0
        ? array_map(
            'mobileNoteSummary',
            $notes->getEntityNotes('contact', $contactId, false, $viewerUserId)
        )
        : [];

    $payload = [
        'conversation' => mobileConversationSummary([
            'id' => $communication['id'],
            'latest_communication_id' => $communication['id'],
            'contact_id' => $communication['contact_id'],
            'channel' => $communication['channel'],
            'direction' => $communication['direction'],
            'subject' => $communication['subject'],
            'from_email' => $communication['from_email'],
            'body_preview' => mb_substr((string) ($communication['body'] ?? ''), 0, 180),
            'body_length' => mb_strlen((string) ($communication['body'] ?? '')),
            'read_at' => $communication['read_at'],
            'created_at' => $communication['created_at'],
            'contact_name' => trim((string) (($communication['first_name'] ?? '') . ' ' . ($communication['last_name'] ?? ''))),
            'contact_email' => $communication['contact_email'] ?? '',
            'thread_key' => $thread['thread_key'] ?? $communication['thread_key'] ?? '',
            'thread_id' => $thread['id'] ?? null,
            'thread_status' => $thread['status'] ?? '',
            'thread_owner_id' => $thread['current_owner_id'] ?? null,
            'thread_priority' => $thread['priority'] ?? '',
            'thread_response_due_at' => $thread['response_due_at'] ?? '',
            'thread_unread_count' => mobileConversationUnreadCount($messages),
            'thread_message_count' => count($messages),
            'thread_channels' => mobileConversationChannelList($messages),
        ]),
        'messages' => array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'direction' => (string) ($row['direction'] ?? ''),
                'channel' => (string) ($row['channel'] ?? ''),
                'subject' => (string) ($row['subject'] ?? ''),
                'from_email' => (string) ($row['from_email'] ?? ''),
                'body' => (string) ($row['body'] ?? ''),
                'read_at' => (string) ($row['read_at'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }, $messages),
        'contact' => $contactId > 0 ? mobileContactSummary($contacts->getById($contactId) ?? []) : null,
        'deals' => $contactId > 0 ? array_map('mobileDealSummary', $deals->getAll(10, 0, ['contact_id' => $contactId])) : [],
        'internal_notes' => $internalNotes,
        'tasks' => $contactId > 0 ? array_map('mobileTaskSummary', $tasks->getAll($taskFilters, 10, 0)) : [],
        'attachments' => array_map('mobileDocumentSummary', mobileConversationAttachments($documents, $messageIds)),
        'ai_status' => $includeAi ? 'ready' : 'deferred',
        'generated_at' => gmdate('c'),
        'stage_options' => ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'],
    ];

    if ($includeAi) {
        $payload['ai'] = mobileSafeConversationInsights(
            (int) $communication['id'],
            (int) ($viewerUserId ?? 0),
            (int) ($thread['id'] ?? 0)
        );
    }

    return $payload;
}

function mobileResolveCanonicalConversationMessage(array $messages, int $preferredCommunicationId = 0): array
{
    if ($preferredCommunicationId > 0) {
        foreach ($messages as $message) {
            if ((int) ($message['id'] ?? 0) === $preferredCommunicationId) {
                return $message;
            }
        }
    }

    $latest = end($messages);
    return is_array($latest) ? $latest : ($messages[0] ?? []);
}

function mobileConversationUnreadCount(array $messages): int
{
    return count(array_filter(
        $messages,
        static fn(array $row): bool => empty($row['read_at'])
    ));
}

function mobileConversationChannelList(array $messages): array
{
    $channels = [];
    foreach ($messages as $message) {
        $channel = trim((string) ($message['channel'] ?? ''));
        if ($channel !== '') {
            $channels[$channel] = true;
        }
    }

    return array_keys($channels);
}

function mobileConversationAttachments(Documents $documents, array $messageIds): array
{
    if ($messageIds === []) {
        return [];
    }

    if (count($messageIds) === 1) {
        return $documents->getEntityDocuments('communication', $messageIds[0]);
    }

    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $workspaceScope = new WorkspaceScopeService();
    $attachmentWorkspace = $workspaceScope->workspaceClause('d.');

    return Database::query(
        "SELECT d.*, u.email AS uploaded_by_email
         FROM documents d
         LEFT JOIN users u ON u.id = d.uploaded_by
         WHERE {$attachmentWorkspace['sql']}
           AND d.entity_type = 'communication'
           AND d.entity_id IN ($placeholders)
         ORDER BY d.created_at DESC, d.id DESC",
        array_merge($attachmentWorkspace['params'], $messageIds)
    );
}

function mobileSafeConversationInsights(int $communicationId, int $viewerUserId, int $threadId = 0): array
{
    try {
        return mobileAiConversationInsights($communicationId, $viewerUserId, $threadId);
    } catch (Throwable $e) {
        return [
            'thread_summary' => '',
            'suggested_reply' => [
                'subject' => '',
                'body' => '',
                'explanation' => '',
                'source' => 'fallback',
            ],
            'next_steps' => [],
            'risk_flags' => [],
            'recommended_actions' => [],
        ];
    }
}
