<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';
require_once __DIR__ . '/_conversation_detail.php';
require_once __DIR__ . '/_conversation_resolver.php';
require_once __DIR__ . '/../../services/ConversationAssignmentService.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\Deals;
use CRM\Modules\Notes;
use CRM\Modules\UnifiedInbox;
use CRM\Services\ConversationAssignmentService;
use CRM\Services\WorkspaceContext;

$auth = mobileRequireAuth();
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $auth['user_id']]) ?? [];
$userId = (int) $auth['user_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$inbox = new UnifiedInbox();
$canViewAll = Authorization::can('conversations.view_all', $user);
$requestedOwnerScope = mobileResolveConversationOwnerScope($user, $_GET['owner_scope'] ?? null);

if ($method === 'POST') {
    $input = mobileRequestBody();
    $action = (string) ($input['action'] ?? '');
    $contactConversationId = (int) ($input['contact_id'] ?? 0);
    $id = (int) ($input['id'] ?? 0);
    $threadId = (int) ($input['thread_id'] ?? 0);
    $ownerScope = mobileResolveConversationOwnerScope($user, $input['owner_scope'] ?? $_GET['owner_scope'] ?? null);
    $visibilityFilters = [
        'viewer_user_id' => $userId,
        'can_view_all_conversations' => $canViewAll,
        'owner_scope' => $ownerScope,
    ];

    if ($contactConversationId > 0 && in_array($action, ['mark_read', 'mark_unread', 'archive', 'assign_to_me', 'unassign'], true)) {
        $visibleMessages = $inbox->getContactConversationMessages($contactConversationId, $visibilityFilters);
        if ($visibleMessages === []) {
            mobileJson(['error' => 'Contact conversation not found or not accessible.'], 404);
        }
        $latest = end($visibleMessages);
        $id = (int) ($latest['id'] ?? 0);

        if ($action === 'mark_read') {
            $inbox->markContactAsRead($contactConversationId, $visibilityFilters);
        } elseif ($action === 'mark_unread') {
            $inbox->markContactAsUnread($contactConversationId, $visibilityFilters);
        } elseif ($action === 'archive') {
            $inbox->archiveContact($contactConversationId, $visibilityFilters);
            mobileJson([
                'success' => true,
                'data' => ['contact_id' => $contactConversationId, 'action' => 'archive'],
            ]);
        } else {
            $assignment = new ConversationAssignmentService();
            try {
                $assignment->setContactConversationOwner(
                    $contactConversationId,
                    $action === 'assign_to_me' ? $userId : null,
                    $userId,
                    $user
                );
            } catch (\RuntimeException $e) {
                $status = (int) $e->getCode();
                mobileJson(['error' => $e->getMessage()], $status >= 400 && $status <= 599 ? $status : 422);
            }
        }

        mobileJson([
            'success' => true,
            'data' => array_merge(
                mobileConversationDetailData(
                    $id,
                    $userId,
                    null,
                    false,
                    $contactConversationId,
                    $visibilityFilters
                ),
                ['owner_scope' => $ownerScope]
            ),
        ]);
    }
    $buildDetailData = static function (int $communicationId, int $viewerUserId, ?int $resolvedThreadId = null) use ($ownerScope): array {
        return array_merge(
            mobileConversationDetailData($communicationId, $viewerUserId, $resolvedThreadId),
            ['owner_scope' => $ownerScope]
        );
    };
    $resolved = mobileResolveConversationTarget($inbox, $id, $threadId, $userId, $canViewAll, $ownerScope);
    $id = (int) ($resolved['communication_id'] ?? 0);
    $threadId = (int) ($resolved['thread_id'] ?? 0);

    if ($id <= 0 && $threadId <= 0) {
        mobileJson(['error' => 'Conversation not found or not accessible.'], 404);
    }
    if ($action === 'mark_read') {
        if ($threadId > 0) {
            $inbox->markThreadAsRead($threadId);
        } else {
            $inbox->markAsRead($id);
        }
        mobileJson(['success' => true, 'data' => $buildDetailData($id, $userId, $threadId ?: null)]);
    }
    if ($action === 'mark_unread') {
        if ($threadId > 0) {
            $inbox->markThreadAsUnread($threadId);
        } else {
            $inbox->markAsUnread($id);
        }
        mobileJson(['success' => true, 'data' => $buildDetailData($id, $userId, $threadId ?: null)]);
    }
    if ($action === 'assign_to_me' || $action === 'unassign') {
        $assignment = new ConversationAssignmentService();
        try {
            $result = $action === 'assign_to_me'
                ? $assignment->assignToUser($id, $userId, $user, $threadId > 0 ? $threadId : null)
                : $assignment->unassign($id, $threadId > 0 ? $threadId : null);
        } catch (\RuntimeException $e) {
            $status = (int) $e->getCode();
            if ($status < 400 || $status > 599) {
                $status = 422;
            }
            mobileJson(['error' => $e->getMessage()], $status);
        }

        mobileJson([
            'success' => true,
            'data' => $buildDetailData(
                $id,
                $userId,
                (int) ($result['thread_id'] ?? ($threadId > 0 ? $threadId : 0))
            ),
        ]);
    }
    if ($action === 'archive') {
        $archived = $threadId > 0 ? $inbox->archiveThread($threadId) : $inbox->archive($id);
        if (!$archived) {
            mobileJson(['error' => 'This conversation could not be archived.'], 422);
        }
        mobileJson(['success' => true, 'data' => $buildDetailData($id, $userId, $threadId ?: null)]);
    }
    if ($action === 'add_note') {
        $communication = mobileConversationRecord($id);
        $contactId = (int) ($communication['contact_id'] ?? 0);
        if ($contactId <= 0) {
            mobileJson(['error' => 'Link a contact before adding internal notes.'], 422);
        }
        $content = trim((string) ($input['content'] ?? ''));
        if ($content === '') {
            mobileJson(['error' => 'Note content is required.'], 422);
        }
        $notes = new Notes();
        $notes->create([
            'entity_type' => 'contact',
            'entity_id' => $contactId,
            'content' => $content,
            'is_private' => 1,
            'created_by' => $userId,
        ]);
        mobileJson(['success' => true, 'data' => $buildDetailData($id, $userId, $threadId ?: null)]);
    }
    if ($action === 'link_contact') {
        $contactId = (int) ($input['contact_id'] ?? 0);
        if ($contactId <= 0) {
            mobileJson(['error' => 'Contact id is required.'], 422);
        }
        $contact = (new Contacts())->getById($contactId);
        if (!$contact || !mobileCanAccessContact($contact, $userId, $user)) {
            mobileJson(['error' => 'Contact not found or not accessible.'], 404);
        }
        mobileLinkConversationToContact($id, $contactId);
        mobileJson(['success' => true, 'data' => $buildDetailData($id, $userId, $threadId ?: null)]);
    }
    if ($action === 'create_contact') {
        $communication = mobileConversationRecord($id);
        $email = trim((string) ($input['email'] ?? $communication['contact_email'] ?? $communication['from_email'] ?? ''));
        $firstName = trim((string) ($input['first_name'] ?? $communication['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? $communication['last_name'] ?? ''));
        if ($firstName === '' && !empty($communication['first_name'])) {
            $firstName = trim((string) $communication['first_name']);
        }
        if ($firstName === '') {
            $firstName = 'New';
        }

        $contacts = new Contacts();
        $created = $contacts->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => (string) ($input['phone'] ?? ''),
            'company' => (string) ($input['company'] ?? ''),
            'assigned_to' => $userId,
            'created_by' => $userId,
            'lead_source' => 'mobile_inbox',
        ]);

        if (($created['status'] ?? '') === 'duplicate') {
            $duplicate = $created['matches']['email'][0] ?? $created['matches']['phone'][0] ?? null;
            if (!$duplicate || empty($duplicate['id'])) {
                mobileJson(['error' => 'A matching contact already exists, but it could not be linked automatically.'], 422);
            }
            mobileLinkConversationToContact($id, (int) $duplicate['id']);
            mobileJson(['success' => true, 'data' => $buildDetailData($id, $userId, $threadId ?: null)]);
        }

        $contactId = (int) ($created['id'] ?? 0);
        if ($contactId <= 0) {
            mobileJson(['error' => 'Could not create a contact for this conversation.'], 422);
        }
        mobileLinkConversationToContact($id, $contactId);
        mobileJson(['success' => true, 'data' => $buildDetailData($id, $userId, $threadId ?: null)]);
    }
    if ($action === 'create_deal') {
        $communication = mobileConversationRecord($id);
        $contactId = (int) ($communication['contact_id'] ?? 0);
        if ($contactId <= 0) {
            mobileJson(['error' => 'Link a contact before creating a deal.'], 422);
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = 'Conversation follow-up';
        }
        $dealId = (new Deals())->create([
            'title' => $title,
            'description' => (string) ($input['description'] ?? ''),
            'contact_id' => $contactId,
            'assigned_to' => $userId,
            'created_by' => $userId,
            'stage' => (string) ($input['stage'] ?? 'prospecting'),
        ]);
        mobileJson(['success' => true, 'data' => $buildDetailData($id, $userId, $threadId ?: null)]);
    }
    if ($action === 'update_linked_deal') {
        $dealId = (int) ($input['deal_id'] ?? 0);
        if ($dealId <= 0) {
            mobileJson(['error' => 'Deal id is required.'], 422);
        }
        $dealModule = new Deals();
        $deal = $dealModule->getById($dealId);
        if (!$deal || !in_array($userId, array_filter([(int) ($deal['assigned_to'] ?? 0), (int) ($deal['created_by'] ?? 0)]), true)) {
            mobileJson(['error' => 'Deal not found or not accessible.'], 404);
        }
        $updates = [];
        foreach (['stage', 'title', 'description', 'expected_close_date'] as $field) {
            if (array_key_exists($field, $input)) {
                $updates[$field] = $input[$field];
            }
        }
        if ($updates === []) {
            mobileJson(['error' => 'No supported deal updates were provided.'], 422);
        }
        $dealModule->update($dealId, $updates);
        mobileJson(['success' => true, 'data' => $buildDetailData($id, $userId, $threadId ?: null)]);
    }
    mobileJson(['error' => 'Unsupported action.'], 422);
}

$id = (int) ($_GET['id'] ?? 0);
$contactConversationId = (int) ($_GET['contact_id'] ?? 0);
$threadId = (int) ($_GET['thread_id'] ?? 0);
$visibilityFilters = [
    'viewer_user_id' => $userId,
    'can_view_all_conversations' => $canViewAll,
    'owner_scope' => $requestedOwnerScope,
];

if ($contactConversationId > 0) {
    $messages = $inbox->getContactConversationMessages($contactConversationId, $visibilityFilters);
    if ($messages === []) {
        mobileJson(['error' => 'Contact conversation not found or not accessible.'], 404);
    }
    $latest = end($messages);
    $id = (int) ($latest['id'] ?? $id);
    mobileJson([
        'success' => true,
        'data' => array_merge(
            mobileConversationDetailData(
                $id,
                $userId,
                null,
                false,
                $contactConversationId,
                $visibilityFilters
            ),
            ['owner_scope' => $requestedOwnerScope]
        ),
    ]);
}

$resolved = mobileResolveConversationTarget($inbox, $id, $threadId, $userId, $canViewAll, $requestedOwnerScope);
$id = (int) ($resolved['communication_id'] ?? 0);
$threadId = (int) ($resolved['thread_id'] ?? 0);

if ($id <= 0 && $threadId <= 0) {
    mobileJson(['error' => 'Conversation not found or not accessible.'], 404);
}

$communication = Database::queryOne(
    "SELECT c.*, ct.first_name, ct.last_name, ct.email AS contact_email
     FROM communications c
     LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.workspace_id = c.workspace_id
     WHERE c.workspace_id = ? AND c.id = ? LIMIT 1",
    [(int) (WorkspaceContext::currentWorkspaceId() ?? 0), $id]
);

if (!$communication && $threadId <= 0) {
    mobileJson(['error' => 'Conversation not found.'], 404);
}

mobileJson([
    'success' => true,
    'data' => array_merge(
        mobileConversationDetailData($id, $userId, $threadId ?: null),
        ['owner_scope' => $requestedOwnerScope]
    ),
]);
