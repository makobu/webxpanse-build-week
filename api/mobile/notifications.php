<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';

use CRM\Database;
use CRM\Modules\Notifications;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$ownerScope = mobileResolveVisibilityScope($user, 'notifications.view_all', $_GET['owner_scope'] ?? null);
$scopeUserId = $ownerScope === 'all' ? null : $userId;
$notifications = new Notifications();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = mobileRequestBody();
    $action = (string) ($input['action'] ?? '');
    if ($action === 'mark_all_read') {
        $notifications->markAllAsRead($userId);
        mobileJson([
            'success' => true,
            'data' => ['unread_count' => $notifications->getUnreadCount($userId)],
        ]);
    }
    if ($action === 'delete_all_read') {
        $notifications->deleteAllRead($userId);
        mobileJson([
            'success' => true,
            'data' => ['unread_count' => $notifications->getUnreadCount($userId)],
        ]);
    }
    if ($action === 'mark_read') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            mobileJson(['error' => 'Notification id is required.'], 422);
        }
        $notifications->markAsRead($id, $userId);
        mobileJson([
            'success' => true,
            'data' => ['unread_count' => $notifications->getUnreadCount($userId)],
        ]);
    }
    mobileJson(['error' => 'Unsupported action.'], 422);
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$unreadOnly = !empty($_GET['unread_only']);
$aiOnly = !empty($_GET['ai_only']);

$items = array_map('mobileNotificationSummary', $notifications->getNotifications($limit, $offset, $unreadOnly, $scopeUserId));
if ($aiOnly) {
    $items = array_values(array_filter($items, static fn(array $item): bool => !empty($item['ai_type'])));
}

mobileJson([
    'success' => true,
    'data' => [
        'items' => $items,
        'unread_count' => $notifications->getUnreadCount($scopeUserId),
        'owner_scope' => $ownerScope,
    ],
]);
