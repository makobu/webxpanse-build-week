<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_conversation_detail.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\UnifiedInbox;
use CRM\Services\ConversationEmailReplyService;
use CRM\Services\WhatsAppService;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceContext;

$auth = mobileRequireAuth();
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $auth['user_id']]) ?? [];
$userId = (int) $auth['user_id'];
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$input = mobileRequestBody();
$preflightCommunicationId = (int) ($input['conversation_id'] ?? 0);
if ($preflightCommunicationId > 0) {
    $existsInWorkspace = Database::queryOne(
        "SELECT id
         FROM communications
         WHERE workspace_id = ?
           AND id = ?
         LIMIT 1",
        [$workspaceId, $preflightCommunicationId]
    );
    if (!$existsInWorkspace) {
        mobileJson(['error' => 'Conversation not found or not accessible.'], 404);
    }
}
$inbox = new UnifiedInbox();
$canViewAll = Authorization::can('conversations.view_all', $user);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

$ownerScope = mobileResolveConversationOwnerScope($user, $input['owner_scope'] ?? $_GET['owner_scope'] ?? null);
$communicationId = (int) ($input['conversation_id'] ?? 0);
$body = trim((string) ($input['body'] ?? ''));

if ($communicationId <= 0 || !$inbox->canUserAccessCommunication($communicationId, $userId, $canViewAll, $ownerScope)) {
    mobileJson(['error' => 'Conversation not found or not accessible.'], 404);
}
if ($body === '') {
    mobileJson(['error' => 'Reply body is required.'], 422);
}

$communication = Database::queryOne(
    "SELECT c.*, ct.email AS contact_email, ct.phone AS contact_phone
     FROM communications c
     LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.workspace_id = c.workspace_id
     WHERE c.workspace_id = ?
       AND c.id = ?
     LIMIT 1",
    [$workspaceId, $communicationId]
);

if (!$communication) {
    mobileJson(['error' => 'Conversation not found.'], 404);
}

$channel = strtolower((string) ($communication['channel'] ?? ''));
if (in_array($channel, ['email', 'whatsapp'], true)) {
    $communicationGate = new WorkspaceCommunicationGateService();
    if (!$communicationGate->isChannelRuntimeReady($workspaceId, $channel, $user)) {
        mobileJson($communicationGate->jsonChannelBlockPayload($workspaceId, $channel, $user), 403);
    }
}

try {
    if ($channel === 'email') {
        $subject = trim((string) ($input['subject'] ?? ''));
        (new ConversationEmailReplyService())->sendReply(
            $communicationId,
            $userId,
            $body,
            $subject !== '' ? $subject : null,
            'mobile'
        );
    } elseif ($channel === 'whatsapp') {
        $contactId = (int) ($communication['contact_id'] ?? 0);
        if ($contactId <= 0) {
            mobileJson(['error' => 'A linked contact is required for WhatsApp replies.'], 422);
        }

        $whatsApp = new WhatsAppService();
        if (!$whatsApp->isWithin24HourWindow($contactId)) {
            mobileJson([
                'error' => "Custom messages can only be sent within 24 hours of the customer's last message. The 24-hour window is closed.",
            ], 422);
        }

        $to = $whatsApp->normalizePhoneNumber((string) ($communication['contact_phone'] ?? ''));
        if ($to === '') {
            mobileJson(['error' => 'A valid contact phone number is required to send this WhatsApp reply.'], 422);
        }

        $result = $whatsApp->sendTextMessage($to, $body);
        $whatsappMessageId = $result['messages'][0]['id'] ?? null;
        $whatsApp->storeMessage($contactId, $to, 'text', $body, [
            'user_id' => $userId,
            'whatsapp_message_id' => $whatsappMessageId,
        ]);
    } else {
        mobileJson(['error' => 'This channel does not support mobile live replies yet.'], 422);
    }
} catch (Throwable $e) {
    error_log('Mobile conversation reply failed for communication_id=' . $communicationId . ' user_id=' . $userId . ': ' . $e->getMessage());
    mobileJson(['error' => trim((string) $e->getMessage()) ?: 'Failed to send reply.'], 422);
}

try {
    mobileJson([
        'success' => true,
        'message' => 'Reply sent successfully.',
        'data' => array_merge(
            mobileConversationDetailData($communicationId, $userId),
            ['owner_scope' => $ownerScope]
        ),
    ]);
} catch (Throwable $e) {
    mobileJson([
        'success' => true,
        'message' => 'Reply sent successfully.',
        'data' => ['conversation_id' => $communicationId],
    ]);
}
