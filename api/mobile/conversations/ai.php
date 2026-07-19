<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_conversation_resolver.php';
require_once __DIR__ . '/../_conversation_detail.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\UnifiedInbox;

$auth = mobileRequireAuth();
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $auth['user_id']]) ?? [];
$userId = (int) $auth['user_id'];
$inbox = new UnifiedInbox();
$canViewAll = Authorization::can('conversations.view_all', $user);
$ownerScope = mobileResolveConversationOwnerScope($user, $_GET['owner_scope'] ?? null);

$id = (int) ($_GET['id'] ?? 0);
$threadId = (int) ($_GET['thread_id'] ?? 0);
$resolved = mobileResolveConversationTarget($inbox, $id, $threadId, $userId, $canViewAll, $ownerScope);
$id = (int) ($resolved['communication_id'] ?? 0);
$threadId = (int) ($resolved['thread_id'] ?? 0);

if ($id <= 0 && $threadId <= 0) {
    mobileJson(['error' => 'Conversation not found or not accessible.'], 404);
}

$detail = mobileConversationDetailData($id, $userId, $threadId ?: null, true);
mobileJson([
    'success' => true,
    'data' => [
        'conversation' => $detail['conversation'] ?? [],
        'ai' => $detail['ai'] ?? mobileSafeConversationInsights($id, $userId, $threadId),
        'ai_status' => 'ready',
        'owner_scope' => $ownerScope,
        'generated_at' => gmdate('c'),
    ],
]);
