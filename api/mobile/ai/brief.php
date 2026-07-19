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

$feed = mobileAiBuildFeed($userId, $user, $summary, $conversationOwnerScope);
mobileJson([
    'success' => true,
    'data' => [
        'today_brief' => $feed['today_brief'],
        'top_actions' => array_slice((array) ($feed['sections']['priority_actions'] ?? []), 0, 3),
        'summary' => $summary,
        'conversation_owner_scope' => $conversationOwnerScope,
    ],
]);
