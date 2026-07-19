<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/_helpers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Deals;
use CRM\Modules\Notifications;
use CRM\Modules\Tasks;
use CRM\Modules\UnifiedInbox;

$auth = mobileRequireAuth();
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $auth['user_id']]) ?? [];
$userId = (int) $auth['user_id'];
$inbox = new UnifiedInbox();
$tasks = new Tasks();
$deals = new Deals();
$notifications = new Notifications();
$conversationOwnerScope = mobileResolveConversationOwnerScope($user, $_GET['owner_scope'] ?? null);

$summary = [
    'unread_conversations' => $inbox->getCount([
        'status' => 'unread',
        'viewer_user_id' => $userId,
        'can_view_all_conversations' => Authorization::can('conversations.view_all', $user),
        'owner_scope' => $conversationOwnerScope,
    ]),
    'overdue_tasks' => $tasks->getCount(['assigned_to' => $userId, 'overdue' => true]),
    'tasks_due_today' => count($tasks->getAll(['assigned_to' => $userId, 'due_today' => true], 20, 0)),
    'open_deals' => $deals->getCount(['assigned_to' => $userId, 'exclude_stages' => ['closed_won', 'closed_lost']]),
    'unread_notifications' => $notifications->getUnreadCount($userId),
];
$readiness = mobileAiCoachReadiness($userId);

mobileJson([
    'success' => true,
    'data' => array_merge(
        mobileAiBuildFastFeed($userId, $user, $summary, $conversationOwnerScope),
        mobileAiReadinessEnvelope($readiness),
        ['conversation_owner_scope' => $conversationOwnerScope]
    ),
]);
