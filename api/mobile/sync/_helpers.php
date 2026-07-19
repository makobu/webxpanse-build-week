<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_serializers.php';
require_once __DIR__ . '/../ai/_helpers.php';

use CRM\Authorization;
use CRM\CacheManager;
use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\Deals;
use CRM\Modules\Notifications;
use CRM\Modules\Tasks;
use CRM\Modules\UnifiedInbox;
use CRM\Services\WorkspaceContext;

function mobileSyncCache(): CacheManager
{
    static $cache = null;
    if ($cache === null) {
        $cache = new CacheManager();
    }
    return $cache;
}

function mobileSyncRequestSource(array $input, string $default = 'mobile_open'): string
{
    $source = strtolower(trim((string) ($input['source'] ?? $default)));
    if ($source === '') {
        return $default;
    }

    return preg_replace('/[^a-z0-9_\-]/', '_', $source) ?: $default;
}

function mobileSyncDeviceKey(array $auth): string
{
    $deviceId = trim((string) ($auth['device_id'] ?? 'unknown_device'));
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $deviceId) ?: 'unknown_device';
}

function mobileSyncWorkspaceId(array $auth): int
{
    return (int) (WorkspaceContext::currentWorkspaceId() ?? ($auth['workspace_id'] ?? 0));
}

function mobileSyncCooldownState(array $auth, string $channel, int $cooldownSeconds, string $source): array
{
    $cooldownSeconds = max(5, $cooldownSeconds);
    $userId = (int) ($auth['user_id'] ?? 0);
    $workspaceId = mobileSyncWorkspaceId($auth);
    $deviceKey = mobileSyncDeviceKey($auth);
    $cacheKey = sprintf('mobile_sync:cooldown:%s:%d:%d:%s', $channel, $workspaceId, $userId, $deviceKey);
    $cache = mobileSyncCache();
    $cached = $cache->get($cacheKey);
    $now = time();

    if (is_array($cached)) {
        $nextAllowedAt = strtotime((string) ($cached['next_allowed_at'] ?? ''));
        if ($nextAllowedAt !== false && $nextAllowedAt > $now) {
            return [
                'skipped' => true,
                'next_refresh_after_seconds' => max(1, $nextAllowedAt - $now),
                'last_refreshed_at' => $cached['generated_at'] ?? null,
                'source' => $cached['source'] ?? $source,
            ];
        }
    }

    $generatedAt = gmdate('c', $now);
    $payload = [
        'generated_at' => $generatedAt,
        'next_allowed_at' => gmdate('c', $now + $cooldownSeconds),
        'source' => $source,
    ];
    $cache->set($cacheKey, $payload, $cooldownSeconds);

    return [
        'skipped' => false,
        'next_refresh_after_seconds' => $cooldownSeconds,
        'last_refreshed_at' => $generatedAt,
        'source' => $source,
    ];
}

function mobileSyncWarmCache(string $key, array $payload, int $ttl = 300): void
{
    mobileSyncCache()->set($key, [
        'generated_at' => gmdate('c'),
        'payload' => $payload,
    ], max(30, $ttl));
}

function mobileSyncUserContext(array $auth): array
{
    $userId = (int) ($auth['user_id'] ?? 0);
    $user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];

    return [$userId, $user];
}

function mobileSyncBuildHomeSummary(int $userId, array $user, string $ownerScope = 'mine_unassigned'): array
{
    $tasks = new Tasks();
    $deals = new Deals();
    $notifications = new Notifications();
    $inbox = new UnifiedInbox();

    return [
        'unread_conversations' => $inbox->getCount([
            'status' => 'unread',
            'viewer_user_id' => $userId,
            'can_view_all_conversations' => Authorization::can('conversations.view_all', $user),
            'owner_scope' => $ownerScope,
        ]),
        'overdue_tasks' => $tasks->getCount(['assigned_to' => $userId, 'overdue' => true]),
        'tasks_due_today' => $tasks->getCount(['assigned_to' => $userId, 'due_today' => true]),
        'open_deals' => $deals->getCount(['assigned_to' => $userId, 'exclude_stages' => ['closed_won', 'closed_lost']]),
        'unread_notifications' => $notifications->getUnreadCount($userId),
    ];
}

function mobileSyncBuildInboxFreshness(int $userId, array $user, string $ownerScope = 'mine_unassigned'): array
{
    $inbox = new UnifiedInbox();
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    $filters = [
        'viewer_user_id' => $userId,
        'can_view_all_conversations' => Authorization::can('conversations.view_all', $user),
        'owner_scope' => $ownerScope,
    ];
    $channelStats = $inbox->getChannelStats($filters);

    return [
        'workspace_id' => $workspaceId,
        'active_workspace_id' => $workspaceId,
        'workspace_version' => $workspaceId > 0 ? 'workspace-' . $workspaceId : 'workspace-none',
        'total' => $inbox->getCount($filters),
        'unread' => $inbox->getCount(array_merge($filters, ['status' => 'unread'])),
        'channels' => $channelStats['counts'] ?? [],
        'unread_by_channel' => $channelStats['unread'] ?? [],
    ];
}

function mobileSyncBuildNotificationFreshness(int $userId): array
{
    $notifications = new Notifications();

    return [
        'unread_count' => $notifications->getUnreadCount($userId),
    ];
}

function mobileSyncBuildReminderFreshness(int $userId): array
{
    $tasks = new Tasks();

    return [
        'overdue_tasks' => $tasks->getCount(['assigned_to' => $userId, 'overdue' => true]),
        'tasks_due_today' => $tasks->getCount(['assigned_to' => $userId, 'due_today' => true]),
    ];
}

function mobileSyncBuildPulseFreshness(int $userId, array $user, string $ownerScope = 'mine_unassigned'): array
{
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    $summary = mobileSyncBuildHomeSummary($userId, $user, $ownerScope);
    $inbox = mobileSyncBuildInboxFreshness($userId, $user, $ownerScope);
    $notifications = mobileSyncBuildNotificationFreshness($userId);
    $reminders = mobileSyncBuildReminderFreshness($userId);
    $aiHome = mobileAiBuildCompactHomePayload($userId, $user, $summary, $ownerScope);

    mobileSyncWarmCache("mobile_sync:home:$workspaceId:$userId", [
        'summary' => $summary,
        'ai_today' => $aiHome['today_brief'] ?? null,
        'top_ai_actions' => $aiHome['top_actions'] ?? [],
    ], 300);
    mobileSyncWarmCache("mobile_sync:inbox:$workspaceId:$userId", $inbox, 180);
    mobileSyncWarmCache("mobile_sync:notifications:$userId", $notifications, 120);
    mobileSyncWarmCache("mobile_sync:reminders:$userId", $reminders, 120);

    return [
        'home' => $summary,
        'inbox' => $inbox,
        'notifications' => $notifications,
        'reminders' => $reminders,
    ];
}

function mobileSyncResponse(
    string $channel,
    array $cooldownState,
    array $freshness,
    bool $queued = false
): array {
    return [
        'channel' => $channel,
        'generated_at' => gmdate('c'),
        'queued' => $queued,
        'skipped' => $cooldownState['skipped'] ?? false,
        'source' => $cooldownState['source'] ?? null,
        'next_refresh_after_seconds' => $cooldownState['next_refresh_after_seconds'] ?? 60,
        'last_refreshed_at' => $cooldownState['last_refreshed_at'] ?? null,
        'freshness' => $freshness,
    ];
}
