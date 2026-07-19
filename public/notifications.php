<?php
/**
 * Notifications Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Modules\Notifications;
use CRM\Modules\UserPreferences;
use CRM\Security;
use CRM\Session;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

function formatNotificationTypeLabel(?string $type): string
{
    $normalized = trim((string) $type);
    if ($normalized === '') {
        return 'Notification';
    }

    return ucwords(str_replace('_', ' ', $normalized));
}

function formatNotificationTimestamp(?string $value): string
{
    if (!$value) {
        return 'Just now';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return 'Just now';
    }

    return date('M j, Y \a\t g:i A', $timestamp);
}

$notificationsModule = new Notifications();
$userPreferences = new UserPreferences();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$basePath = getBasePath();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$isProtectedDemoNotifications = false;
try {
    $isProtectedDemoNotifications = (new DemoSessionScopeService())->activeSession($workspaceId) !== null;
} catch (\Throwable $e) {
    $isProtectedDemoNotifications = false;
}

$allowedFilters = ['all', 'unread', 'read'];
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        header('Location: ' . $basePath . '/notifications.php?error=invalid_token');
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($isProtectedDemoNotifications && in_array($action, ['delete_all_read', 'delete'], true)) {
        header('Location: ' . $basePath . '/notifications.php');
        exit;
    }

    if ($action === 'mark_all_read') {
        $notificationsModule->markAllAsRead($userId);
        header('Location: ' . $basePath . '/notifications.php?success=marked_all_read');
        exit;
    }

    if ($action === 'mark_group_read') {
        $groupType = (string) ($_POST['group_type'] ?? '');
        $groupEntityType = (string) ($_POST['group_entity_type'] ?? '');
        $notificationsModule->markGroupAsRead($userId, $groupType, $groupEntityType !== '' ? $groupEntityType : null);
        header('Location: ' . $basePath . '/notifications.php?success=marked_group_read');
        exit;
    }

    if ($action === 'delete_all_read') {
        $notificationsModule->deleteAllRead($userId);
        header('Location: ' . $basePath . '/notifications.php?success=deleted_all_read');
        exit;
    }

    if ($action === 'mark_read') {
        $notificationId = (int) ($_POST['notification_id'] ?? 0);
        $notificationsModule->markAsRead($notificationId, $userId);
        header('Location: ' . $basePath . '/notifications.php?success=marked_read');
        exit;
    }

    if ($action === 'delete') {
        $notificationId = (int) ($_POST['notification_id'] ?? 0);
        $notificationsModule->delete($notificationId, $userId);
        header('Location: ' . $basePath . '/notifications.php?success=deleted');
        exit;
    }
}

$notifications = $notificationsModule->getUserNotifications($userId, 50, 0, $filter === 'unread');
if ($filter === 'read') {
    $notifications = array_values(array_filter($notifications, static function (array $notification): bool {
        return !empty($notification['is_read']);
    }));
}
if ($isProtectedDemoNotifications) {
    $notifications = array_values(array_filter($notifications, static function (array $notification): bool {
        $type = strtolower(trim((string) ($notification['type'] ?? '')));
        $title = strtolower(trim((string) ($notification['title'] ?? '')));
        $message = strtolower(trim((string) ($notification['message'] ?? '')));

        if ($type === 'marketplace_next_action') {
            return false;
        }

        foreach (['checking setup', 'finish marketplace setup', 'setup readiness', 'ready to check'] as $blockedPhrase) {
            if (str_contains($title, $blockedPhrase) || str_contains($message, $blockedPhrase)) {
                return false;
            }
        }

        return true;
    }));
}

$unreadCount = $notificationsModule->getUnreadCount($userId);
if ($isProtectedDemoNotifications) {
    $unreadCount = count(array_filter($notifications, static fn(array $notification): bool => empty($notification['is_read'])));
}
$userPreferences->markNotificationsPageOpened($userId);
$notificationGroups = $notificationsModule->groupNotifications($notifications);

$pageTitle = 'Notifications - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<style>
.notifications-page {
    --notif-bg: linear-gradient(180deg, #f8fbff 0%, #f5f7fb 100%);
    --notif-surface: rgba(255, 255, 255, 0.94);
    --notif-surface-strong: #ffffff;
    --notif-border: rgba(148, 163, 184, 0.18);
    --notif-shadow: 0 24px 52px rgba(15, 23, 42, 0.08);
    --notif-shadow-soft: 0 12px 28px rgba(15, 23, 42, 0.05);
    --notif-text: #0f172a;
    --notif-muted: #64748b;
    --notif-muted-2: #475569;
    --notif-blue: #2563eb;
    --notif-blue-soft: rgba(37, 99, 235, 0.1);
    --notif-green: #047857;
    --notif-green-soft: rgba(16, 185, 129, 0.12);
    --notif-red: #b91c1c;
    --notif-red-soft: rgba(239, 68, 68, 0.1);
    box-sizing: border-box;
    padding-left: clamp(1rem, 2.5vw, 2.25rem);
    padding-right: clamp(1rem, 2.5vw, 2.25rem);
    overflow-x: hidden;
}

.notifications-page .container {
    max-width: 1120px;
}

.notifications-page .notifications-shell {
    display: grid;
    gap: 1.5rem;
    box-sizing: border-box;
    margin: 0 auto;
    max-width: 1120px;
    padding: 0.5rem 0 2rem;
    width: 100%;
}

.notifications-page .notifications-summary {
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) auto;
    gap: 1rem;
    align-items: stretch;
    padding: 1.5rem;
    border-radius: 28px;
    border: 1px solid var(--notif-border);
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.1), transparent 28%),
        var(--notif-bg);
    box-shadow: var(--notif-shadow);
}

.notifications-page .summary-main {
    display: grid;
    gap: 1rem;
}

.notifications-page .summary-heading {
    display: flex;
    align-items: center;
    gap: 1rem;
    min-width: 0;
}

.notifications-page .summary-icon {
    width: 58px;
    height: 58px;
    border-radius: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.8);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9), var(--notif-shadow-soft);
    color: #f59e0b;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.notifications-page .summary-copy {
    min-width: 0;
}

.notifications-page .summary-copy h1 {
    margin: 0;
    font-size: clamp(1.75rem, 2vw, 2.15rem);
    line-height: 1.1;
    letter-spacing: -0.03em;
    font-weight: 800;
    color: var(--notif-text);
}

.notifications-page .summary-copy p {
    margin: 0.45rem 0 0;
    font-size: 0.98rem;
    color: var(--notif-muted-2);
}

.notifications-page .summary-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.8rem;
    height: 1.8rem;
    margin-left: 0.55rem;
    padding: 0 0.65rem;
    border-radius: 999px;
    background: var(--notif-blue);
    color: #ffffff;
    font-size: 0.84rem;
    font-weight: 800;
    vertical-align: middle;
}

.notifications-page .summary-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
}

.notifications-page .summary-stat {
    padding: 0.95rem 1rem;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.72);
    border: 1px solid rgba(255, 255, 255, 0.72);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
}

.notifications-page .summary-stat-label {
    display: block;
    font-size: 0.76rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--notif-muted);
}

.notifications-page .summary-stat-value {
    display: block;
    margin-top: 0.32rem;
    font-size: 1.45rem;
    font-weight: 800;
    line-height: 1;
    color: var(--notif-text);
}

.notifications-page .summary-controls {
    display: grid;
    gap: 0.9rem;
    align-content: start;
    min-width: min(100%, 420px);
}

.notifications-page .summary-panel {
    display: grid;
    gap: 0.8rem;
    padding: 1rem;
    border-radius: 22px;
    border: 1px solid var(--notif-border);
    background: rgba(255, 255, 255, 0.84);
    box-shadow: var(--notif-shadow-soft);
}

.notifications-page .summary-panel-label {
    margin: 0;
    font-size: 0.76rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--notif-muted);
}

.notifications-page .notif-tabs {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    padding: 0.35rem;
    border-radius: 16px;
    background: rgba(148, 163, 184, 0.12);
    width: fit-content;
}

.notifications-page .notif-tabs a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    padding: 0.65rem 1rem;
    border-radius: 12px;
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--notif-muted-2);
    text-decoration: none;
    transition: background 0.18s ease, color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}

.notifications-page .notif-tabs a:hover {
    background: rgba(255, 255, 255, 0.78);
    color: var(--notif-text);
    transform: translateY(-1px);
}

.notifications-page .notif-tabs a.active {
    background: var(--notif-surface-strong);
    color: var(--notif-text);
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
}

.notifications-page .bulk-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
}

.notifications-page .bulk-actions form {
    margin: 0;
}

.notifications-page .bulk-btn {
    appearance: none;
    -webkit-appearance: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    padding: 0.7rem 1rem;
    border-radius: 14px;
    border: 1px solid transparent;
    font: inherit;
    font-size: 0.9rem;
    font-weight: 700;
    line-height: 1;
    cursor: pointer;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease, color 0.18s ease;
}

.notifications-page .bulk-btn:hover {
    transform: translateY(-1px);
}

.notifications-page .bulk-btn.primary {
    background: linear-gradient(135deg, #5b7cff, #5570e8);
    color: #ffffff;
    box-shadow: 0 14px 28px rgba(85, 112, 232, 0.24);
}

.notifications-page .bulk-btn.secondary {
    background: rgba(255, 255, 255, 0.85);
    color: var(--notif-muted-2);
    border-color: rgba(148, 163, 184, 0.22);
}

.notifications-page .status-banner {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 1rem 1.15rem;
    border-radius: 18px;
    border: 1px solid rgba(16, 185, 129, 0.2);
    background: linear-gradient(135deg, rgba(236, 253, 245, 0.96), rgba(240, 253, 250, 0.96));
    color: #065f46;
    box-shadow: var(--notif-shadow-soft);
}

.notifications-page .status-banner::before {
    content: '';
    width: 22px;
    height: 22px;
    flex-shrink: 0;
    border-radius: 999px;
    background:
        rgba(16, 185, 129, 0.14)
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23065f46'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M5 13l4 4L19 7'/%3E%3C/svg%3E")
        center/14px 14px no-repeat;
}

.notifications-page .notifications-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.85rem 1rem;
    border-radius: 18px;
    border: 1px solid var(--notif-border);
    background: rgba(255, 255, 255, 0.92);
    box-shadow: var(--notif-shadow-soft);
}

.notifications-page .toolbar-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: min(100%, 280px);
}

.notifications-page .toolbar-icon {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(245, 158, 11, 0.12);
    color: #d97706;
    font-size: 1.15rem;
    flex-shrink: 0;
}

.notifications-page .toolbar-copy {
    min-width: 0;
}

.notifications-page .toolbar-copy h1 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0;
    color: var(--notif-text);
    font-size: 1.35rem;
    font-weight: 800;
    line-height: 1.1;
}

.notifications-page .toolbar-copy p {
    margin: 0.18rem 0 0;
    color: var(--notif-muted-2);
    font-size: 0.86rem;
    line-height: 1.35;
}

.notifications-page .toolbar-controls {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.notifications-page .notification-groups {
    display: grid;
    gap: 0.75rem;
}

.notifications-page .notif-group {
    position: relative;
    border-radius: 18px;
    border: 1px solid var(--notif-border);
    background: rgba(255, 255, 255, 0.94);
    box-shadow: var(--notif-shadow-soft);
    overflow: hidden;
}

.notifications-page .notif-group::before {
    content: '';
    position: absolute;
    inset: 0 auto 0 0;
    width: 4px;
    background: var(--notif-accent, rgba(148, 163, 184, 0.6));
}

.notifications-page .notif-group[open] {
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.96));
}

.notifications-page .notif-group-summary {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 0.85rem;
    align-items: center;
    padding: 1rem 1rem 1rem 1.2rem;
    cursor: pointer;
    list-style: none;
}

.notifications-page .notif-group-summary::-webkit-details-marker {
    display: none;
}

.notifications-page .notif-group-icon {
    position: relative;
    width: 46px;
    height: 46px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.12rem;
    flex-shrink: 0;
}

.notifications-page .notif-group-title {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    flex-wrap: wrap;
    min-width: 0;
}

.notifications-page .notif-group-title h2 {
    margin: 0;
    color: var(--notif-text);
    font-size: 1rem;
    font-weight: 800;
    line-height: 1.2;
}

.notifications-page .notif-group-preview {
    margin: 0.25rem 0 0;
    color: var(--notif-muted-2);
    font-size: 0.88rem;
    line-height: 1.4;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.notifications-page .notif-group-meta {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.notifications-page .notif-count-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 26px;
    padding: 0.28rem 0.58rem;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.06);
    color: #334155;
    font-size: 0.72rem;
    font-weight: 800;
    white-space: nowrap;
}

.notifications-page .notif-count-pill.unread {
    background: rgba(37, 99, 235, 0.12);
    color: var(--notif-blue);
}

.notifications-page .notif-thread {
    display: grid;
    gap: 0.5rem;
    padding: 0 1rem 1rem 1.2rem;
}

.notifications-page .notif-group-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid rgba(148, 163, 184, 0.14);
}

.notifications-page .notif-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.8rem;
    align-items: center;
    padding: 0.85rem;
    border-radius: 14px;
    border: 1px solid rgba(148, 163, 184, 0.14);
    background: rgba(255, 255, 255, 0.82);
}

.notifications-page .notif-row.unread {
    border-color: rgba(37, 99, 235, 0.16);
    background: rgba(37, 99, 235, 0.04);
}

.notifications-page .notif-row h3 {
    margin: 0;
    color: var(--notif-text);
    font-size: 0.94rem;
    line-height: 1.25;
    font-weight: 800;
}

.notifications-page .notif-row p {
    margin: 0.28rem 0 0;
    color: var(--notif-muted-2);
    font-size: 0.84rem;
    line-height: 1.45;
}

.notifications-page .notif-row-meta {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex-wrap: wrap;
    margin-top: 0.45rem;
}

.notifications-page .notifications-feed {
    display: grid;
    gap: 1rem;
}

.notifications-page .notif-card {
    position: relative;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 1rem;
    padding: 1.2rem;
    border-radius: 24px;
    border: 1px solid var(--notif-border);
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.96));
    box-shadow: var(--notif-shadow-soft);
    overflow: hidden;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
}

.notifications-page .notif-card::before {
    content: '';
    position: absolute;
    inset: 0 auto 0 0;
    width: 4px;
    background: var(--notif-accent, rgba(148, 163, 184, 0.6));
    opacity: 0.6;
}

.notifications-page .notif-card.unread {
    border-color: rgba(37, 99, 235, 0.18);
    box-shadow: 0 20px 44px rgba(37, 99, 235, 0.08);
}

.notifications-page .notif-card.unread::before {
    opacity: 1;
}

.notifications-page .notif-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.09);
}

.notifications-page .notif-card-icon {
    position: relative;
    width: 58px;
    height: 58px;
    border-radius: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.45rem;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.72), 0 12px 24px rgba(15, 23, 42, 0.08);
    flex-shrink: 0;
}

.notifications-page .notif-card-icon::after {
    content: '';
    position: absolute;
    right: 6px;
    bottom: 6px;
    width: 10px;
    height: 10px;
    border-radius: 999px;
    background: var(--notif-accent, rgba(148, 163, 184, 0.7));
    box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.95);
    opacity: 0;
}

.notifications-page .notif-card.unread .notif-card-icon::after {
    opacity: 1;
}

.notifications-page .notif-card-body {
    min-width: 0;
}

.notifications-page .notif-card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
}

.notifications-page .notif-chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-bottom: 0.55rem;
}

.notifications-page .notif-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 28px;
    padding: 0.35rem 0.7rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    white-space: nowrap;
}

.notifications-page .notif-chip.type {
    background: rgba(15, 23, 42, 0.06);
    color: #334155;
}

.notifications-page .notif-chip.state-unread {
    background: rgba(37, 99, 235, 0.12);
    color: var(--notif-blue);
}

.notifications-page .notif-chip.state-read {
    background: rgba(148, 163, 184, 0.12);
    color: var(--notif-muted-2);
}

.notifications-page .notif-title {
    margin: 0;
    font-size: 1.18rem;
    line-height: 1.2;
    letter-spacing: -0.02em;
    font-weight: 800;
    color: var(--notif-text);
}

.notifications-page .notif-message {
    margin: 0.7rem 0 0;
    max-width: 72ch;
    color: var(--notif-muted-2);
    font-size: 0.96rem;
    line-height: 1.68;
}

.notifications-page .notif-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 1rem;
    padding-top: 0.95rem;
    border-top: 1px solid rgba(148, 163, 184, 0.16);
}

.notifications-page .notif-meta {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.5rem 0.8rem;
    border-radius: 999px;
    background: rgba(148, 163, 184, 0.1);
    color: var(--notif-muted);
    font-size: 0.76rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.notifications-page .notif-meta::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: currentColor;
    opacity: 0.45;
}

.notifications-page .notif-actions-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
    align-items: center;
}

.notifications-page .notif-action-form {
    margin: 0;
    display: inline-flex;
}

.notifications-page a.notif-action-link,
.notifications-page button.notif-action-btn {
    appearance: none;
    -webkit-appearance: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    padding: 0.68rem 0.92rem;
    border-radius: 12px;
    border: 1px solid transparent;
    background: rgba(255, 255, 255, 0.95);
    font: inherit;
    font-size: 0.84rem;
    font-weight: 800;
    line-height: 1;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease, color 0.18s ease;
}

.notifications-page button.notif-action-btn::-moz-focus-inner {
    border: 0;
    padding: 0;
}

.notifications-page a.notif-action-link:hover,
.notifications-page button.notif-action-btn:hover {
    transform: translateY(-1px);
}

.notifications-page a.notif-action-link:focus-visible,
.notifications-page button.notif-action-btn:focus-visible {
    outline: 2px solid rgba(37, 99, 235, 0.28);
    outline-offset: 2px;
}

.notifications-page .action-open {
    background: rgba(37, 99, 235, 0.1);
    color: var(--notif-blue);
    border-color: rgba(37, 99, 235, 0.18);
}

.notifications-page .action-open:hover {
    background: rgba(37, 99, 235, 0.15);
    box-shadow: 0 12px 24px rgba(37, 99, 235, 0.14);
}

.notifications-page .action-read {
    background: rgba(255, 255, 255, 0.96);
    color: var(--notif-green);
    border-color: rgba(16, 185, 129, 0.16);
}

.notifications-page .action-read:hover {
    background: var(--notif-green-soft);
    box-shadow: 0 12px 24px rgba(16, 185, 129, 0.1);
}

.notifications-page .action-delete {
    background: rgba(255, 255, 255, 0.96);
    color: var(--notif-red);
    border-color: rgba(239, 68, 68, 0.16);
}

.notifications-page .action-delete:hover {
    background: var(--notif-red-soft);
    box-shadow: 0 12px 24px rgba(239, 68, 68, 0.1);
}

.notifications-page .empty-state-modern {
    display: grid;
    place-items: center;
    gap: 0.85rem;
    padding: 4rem 1.5rem;
    text-align: center;
    border-radius: 28px;
    border: 1px solid var(--notif-border);
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.96));
    box-shadow: var(--notif-shadow);
}

.notifications-page .empty-state-modern .empty-icon {
    width: 84px;
    height: 84px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(37, 99, 235, 0.08);
    color: var(--notif-blue);
    font-size: 2rem;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.82);
}

.notifications-page .empty-state-modern h2 {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 800;
    color: var(--notif-text);
}

.notifications-page .empty-state-modern p {
    margin: 0;
    max-width: 46ch;
    color: var(--notif-muted-2);
    font-size: 0.98rem;
}

@media (max-width: 900px) {
    .notifications-page .notifications-toolbar {
        align-items: flex-start;
        flex-direction: column;
    }

    .notifications-page .toolbar-controls {
        justify-content: flex-start;
        width: 100%;
    }

    .notifications-page .notifications-summary {
        grid-template-columns: 1fr;
    }

    .notifications-page .summary-controls {
        min-width: 0;
    }
}

@media (max-width: 720px) {
    .notifications-page {
        padding-left: 0.75rem;
        padding-right: 0.75rem;
    }

    .notifications-page .notifications-shell {
        gap: 1rem;
    }

    .notifications-page .notifications-summary {
        padding: 1rem;
        border-radius: 22px;
    }

    .notifications-page .notifications-toolbar {
        padding: 0.8rem;
        border-radius: 16px;
    }

    .notifications-page .toolbar-title {
        width: 100%;
    }

    .notifications-page .toolbar-copy h1 {
        font-size: 1.16rem;
    }

    .notifications-page .notif-tabs,
    .notifications-page .bulk-actions {
        width: 100%;
    }

    .notifications-page .notif-tabs a,
    .notifications-page .bulk-actions form,
    .notifications-page .bulk-btn {
        flex: 1 1 auto;
    }

    .notifications-page .notif-group-summary,
    .notifications-page .notif-row {
        grid-template-columns: 1fr;
    }

    .notifications-page .notif-group-meta,
    .notifications-page .notif-actions-row {
        justify-content: flex-start;
    }

    .notifications-page .notif-group-preview {
        white-space: normal;
    }

    .notifications-page .summary-heading {
        align-items: flex-start;
    }

    .notifications-page .summary-stats {
        grid-template-columns: 1fr;
    }

    .notifications-page .notif-card {
        grid-template-columns: 1fr;
        padding: 1rem;
        border-radius: 20px;
    }

    .notifications-page .notif-card-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        font-size: 1.25rem;
    }

    .notifications-page .notif-footer {
        align-items: flex-start;
    }

    .notifications-page .notif-actions-row {
        width: 100%;
    }

    .notifications-page a.notif-action-link,
    .notifications-page button.notif-action-btn {
        width: auto;
        min-width: 0;
    }
}
</style>

<div class="page-premium notifications-page">
    <div class="notifications-shell">
        <section class="notifications-toolbar" aria-label="Notification controls">
            <div class="toolbar-title">
                <div class="toolbar-icon" aria-hidden="true">&#128276;</div>
                <div class="toolbar-copy">
                    <h1>
                        Notifications
                        <?php if ($unreadCount > 0): ?>
                            <span class="summary-badge" aria-label="<?php echo (int) $unreadCount; ?> unread"><?php echo $unreadCount > 99 ? '99+' : $unreadCount; ?></span>
                        <?php endif; ?>
                    </h1>
                    <p>
                        <?php if ($unreadCount > 0): ?>
                            <?php echo (int) $unreadCount; ?> unread across <?php echo (int) count($notificationGroups); ?> thread<?php echo count($notificationGroups) !== 1 ? 's' : ''; ?>
                        <?php else: ?>
                            All caught up across <?php echo (int) count($notificationGroups); ?> visible thread<?php echo count($notificationGroups) !== 1 ? 's' : ''; ?>.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="toolbar-controls">
                <div class="notif-tabs" role="tablist" aria-label="Notification filters">
                    <a href="?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>" role="tab" aria-selected="<?php echo $filter === 'all' ? 'true' : 'false'; ?>">All</a>
                    <a href="?filter=unread" class="<?php echo $filter === 'unread' ? 'active' : ''; ?>" role="tab" aria-selected="<?php echo $filter === 'unread' ? 'true' : 'false'; ?>">Unread</a>
                    <a href="?filter=read" class="<?php echo $filter === 'read' ? 'active' : ''; ?>" role="tab" aria-selected="<?php echo $filter === 'read' ? 'true' : 'false'; ?>">Read</a>
                </div>

                <div class="bulk-actions">
                    <?php if ($unreadCount > 0): ?>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="bulk-btn primary">Mark all read</button>
                        </form>
                    <?php endif; ?>
                    <?php if (!$isProtectedDemoNotifications): ?>
                    <form method="POST" action="" onsubmit="return confirm('Delete all read notifications?');">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="delete_all_read">
                        <button type="submit" class="bulk-btn secondary">Clear read</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if (isset($_GET['success'])): ?>
            <?php
            $messages = [
                'marked_all_read' => 'All notifications marked as read.',
                'marked_group_read' => 'Notification thread marked as read.',
                'marked_read' => 'Notification marked as read.',
                'deleted_all_read' => 'All read notifications deleted.',
                'deleted' => 'Notification deleted.',
            ];
            $successText = $messages[$_GET['success']] ?? 'Done.';
            ?>
            <div class="status-banner" role="status"><?php echo htmlspecialchars($successText); ?></div>
        <?php endif; ?>

        <?php if (empty($notificationGroups)): ?>
            <section class="empty-state-modern">
                <div class="empty-icon" aria-hidden="true">&#128276;</div>
                <h2>No notifications</h2>
                <p>You're all caught up. New activity, reminders, and team alerts will appear here when they arrive.</p>
            </section>
        <?php else: ?>
            <section class="notification-groups" aria-label="Grouped notification feed">
                <?php foreach ($notificationGroups as $group): ?>
                    <?php
                    $groupType = (string) ($group['type'] ?? '');
                    $groupEntityType = (string) ($group['entity_type'] ?? '');
                    $groupColor = (string) ($group['color'] ?? '#666');
                    $groupIcon = (string) ($group['icon'] ?? '&#128276;');
                    $groupUnread = (int) ($group['unread_count'] ?? 0);
                    $groupTotal = (int) ($group['total_count'] ?? 0);
                    $groupTimestamp = formatNotificationTimestamp((string) ($group['latest_at'] ?? ''));
                    $groupPreview = trim((string) ($group['latest_message'] ?? ''));
                    if ($groupPreview === '') {
                        $groupPreview = (string) ($group['latest_title'] ?? 'Notification');
                    }
                    ?>
                    <details class="notif-group <?php echo $groupUnread > 0 ? 'unread' : ''; ?>" style="--notif-accent: <?php echo htmlspecialchars($groupColor); ?>;">
                        <summary class="notif-group-summary">
                            <div class="notif-group-icon" style="background: <?php echo htmlspecialchars($groupColor); ?>18; color: <?php echo htmlspecialchars($groupColor); ?>;" aria-hidden="true">
                                <?php echo $groupIcon; ?>
                            </div>

                            <div>
                                <div class="notif-group-title">
                                    <h2><?php echo htmlspecialchars((string) ($group['label'] ?? 'Notifications')); ?></h2>
                                    <span class="notif-count-pill"><?php echo $groupTotal; ?> item<?php echo $groupTotal !== 1 ? 's' : ''; ?></span>
                                    <?php if ($groupUnread > 0): ?>
                                        <span class="notif-count-pill unread"><?php echo $groupUnread; ?> unread</span>
                                    <?php endif; ?>
                                </div>
                                <p class="notif-group-preview"><?php echo htmlspecialchars($groupPreview); ?></p>
                            </div>

                            <div class="notif-group-meta">
                                <span class="notif-meta"><?php echo htmlspecialchars($groupTimestamp); ?></span>
                            </div>
                        </summary>

                        <div class="notif-thread">
                            <?php if ($groupUnread > 0): ?>
                                <div class="notif-group-actions">
                                    <span class="notif-count-pill unread"><?php echo $groupUnread; ?> unread in this thread</span>
                                    <form method="POST" action="" class="notif-action-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="mark_group_read">
                                        <input type="hidden" name="group_type" value="<?php echo htmlspecialchars($groupType); ?>">
                                        <input type="hidden" name="group_entity_type" value="<?php echo htmlspecialchars($groupEntityType); ?>">
                                        <button type="submit" class="notif-action-btn action-read" title="Mark thread as read">Mark thread read</button>
                                    </form>
                                </div>
                            <?php endif; ?>

                            <?php foreach ((array) ($group['notifications'] ?? []) as $notification): ?>
                                <?php
                                $type = (string) ($notification['type'] ?? '');
                                $unread = empty($notification['is_read']);
                                $typeLabel = formatNotificationTypeLabel($type);
                                $timestamp = formatNotificationTimestamp((string) ($notification['created_at'] ?? ''));
                                ?>
                                <article class="notif-row <?php echo $unread ? 'unread' : ''; ?>">
                                    <div>
                                        <h3><?php echo htmlspecialchars((string) ($notification['title'] ?? 'Notification')); ?></h3>
                                        <p><?php echo htmlspecialchars((string) ($notification['message'] ?? '')); ?></p>
                                        <div class="notif-row-meta">
                                            <span class="notif-chip type"><?php echo htmlspecialchars($typeLabel); ?></span>
                                            <?php if ($unread): ?>
                                                <span class="notif-chip state-unread">Unread</span>
                                            <?php else: ?>
                                                <span class="notif-chip state-read">Read</span>
                                            <?php endif; ?>
                                            <span class="notif-meta"><?php echo htmlspecialchars($timestamp); ?></span>
                                        </div>
                                    </div>

                                    <div class="notif-actions-row">
                                        <?php if (!empty($notification['link'])): ?>
                                            <a href="<?php echo htmlspecialchars((string) $notification['link']); ?>" class="notif-action-link action-open">Open</a>
                                        <?php endif; ?>

                                        <?php if ($unread): ?>
                                            <form method="POST" action="" class="notif-action-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>">
                                                <button type="submit" class="notif-action-btn action-read" title="Mark as read">Mark read</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (!$isProtectedDemoNotifications): ?>
                                        <form method="POST" action="" class="notif-action-form" onsubmit="return confirm('Delete this notification?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>">
                                            <button type="submit" class="notif-action-btn action-delete" title="Delete">Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
