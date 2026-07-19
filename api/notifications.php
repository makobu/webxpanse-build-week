<?php
/**
 * Notifications API
 * Returns JSON for AJAX requests
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Security;
use CRM\Modules\Notifications;
use CRM\Modules\UserPreferences;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');
$perfEnabled = isset($_GET['perf_debug']) || (
    (($_ENV['APP_ENV'] ?? 'production') === 'development')
    || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
    || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
);
$perfMarks = [['start', microtime(true)]];
$markPerf = static function (string $label) use (&$perfMarks, $perfEnabled): void {
    if ($perfEnabled) {
        $perfMarks[] = [$label, microtime(true)];
    }
};
$flushPerf = static function () use (&$perfMarks, $perfEnabled): void {
    if (!$perfEnabled || headers_sent()) {
        return;
    }
    $parts = [];
    for ($i = 1, $count = count($perfMarks); $i < $count; $i++) {
        $label = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $perfMarks[$i][0]);
        $duration = max(0, ($perfMarks[$i][1] - $perfMarks[$i - 1][1]) * 1000);
        $parts[] = $label . ';dur=' . number_format($duration, 1, '.', '');
    }
    $total = max(0, ($perfMarks[count($perfMarks) - 1][1] - $perfMarks[0][1]) * 1000);
    $parts[] = 'notifications_total;dur=' . number_format($total, 1, '.', '');
    header('Server-Timing: ' . implode(', ', $parts));
};

// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$notificationsModule = new Notifications();
$userPreferences = new UserPreferences();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

$action = $_GET['action'] ?? 'list';
$isReadOnlyAction = in_array($action, ['list', 'count'], true);
if ($isReadOnlyAction) {
    Session::closeWrite();
}
$markPerf('auth');

if ($action === 'list') {
    $limit = (int) ($_GET['limit'] ?? 10);
    $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';
    
    $notifications = $notificationsModule->getUserNotifications($userId, $limit, 0, $unreadOnly);
    $lastOpenedAt = $userPreferences->getNotificationsLastOpenedAt($userId);
    $badgeCounts = $notificationsModule->getBadgeCounts($userId, $lastOpenedAt);
    $unreadCount = $badgeCounts['unread_count'];
    $newCount = $badgeCounts['new_count'];
    $markPerf('query');
    
    // Format notifications for JSON
    $formatted = [];
    foreach ($notifications as $notification) {
        $formatted[] = [
            'id' => $notification['id'],
            'type' => $notification['type'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'link' => $notification['link'],
            'severity' => $notification['severity'] ?? null,
            'ai_insight' => $notification['ai_insight'] ?? null,
            'ai_action' => $notification['ai_action'] ?? null,
            'is_read' => (bool) $notification['is_read'],
            'created_at' => $notification['created_at'],
            'icon' => $notificationsModule->getIcon($notification['type']),
            'color' => $notificationsModule->getColor($notification['type'])
        ];
    }
    
    $flushPerf();
    echo json_encode([
        'notifications' => $formatted,
        'unread_count' => $unreadCount,
        'new_count' => $newCount
    ]);
} elseif ($action === 'count') {
    $lastOpenedAt = $userPreferences->getNotificationsLastOpenedAt($userId);
    $badgeCounts = $notificationsModule->getBadgeCounts($userId, $lastOpenedAt);
    $unreadCount = $badgeCounts['unread_count'];
    $newCount = $badgeCounts['new_count'];
    $markPerf('query');
    $flushPerf();
    echo json_encode([
        'unread_count' => $unreadCount,
        'new_count' => $newCount
    ]);
} elseif ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }
    Session::closeWrite();
    
    $notificationId = (int) ($_POST['notification_id'] ?? 0);
    if ($notificationsModule->markAsRead($notificationId, $userId)) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to mark as read']);
    }
} elseif ($action === 'mark_all_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }
    Session::closeWrite();
    
    if ($notificationsModule->markAllAsRead($userId)) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to mark all as read']);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}
