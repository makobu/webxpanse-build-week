<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';
require_once __DIR__ . '/ai/_helpers.php';

use CRM\Authorization;
use CRM\CacheManager;
use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\Deals;
use CRM\Modules\Notifications;
use CRM\Modules\Tasks;
use CRM\Modules\UnifiedInbox;
use CRM\Services\AutomationBatteryService;
use CRM\Services\FounderCommandCenterAccessService;
use CRM\Services\FounderCommandCenterService;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceLaunchReadinessException;
use CRM\Services\WorkspaceContext;

$auth = mobileRequireAuth(true);
$mobileService = mobileService();
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $auth['user_id']]) ?? [];
$userId = (int) ($auth['user_id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? ($auth['workspace_id'] ?? 0));
$saasBilling = [
    'subscription_status' => 'inactive',
    'token_balance' => 0,
    'available_tokens' => 0,
    'billing_blocked' => false,
    'ai_blocked_reason' => null,
];
if ($workspaceId > 0) {
    try {
        $saasBilling = (new SaaSBillingService())->getWorkspaceSnapshot($workspaceId, $user);
    } catch (WorkspaceLaunchReadinessException $e) {
        $saasBilling['launch_readiness'] = $e->readiness();
    }
}
$workspaceBilling = $mobileService->shouldExposeWorkspaceBillingCompatibility()
    ? $mobileService->buildWorkspaceBillingCompatibility([], $saasBilling)
    : null;
$conversationOwnerScope = mobileResolveConversationOwnerScope($user, $_GET['owner_scope'] ?? null);

if (!empty($saasBilling['billing_blocked'])) {
    mobileJson([
        'success' => true,
        'data' => [
            'billing_blocked' => true,
            'workspace_billing' => $workspaceBilling,
            'saas_billing' => $saasBilling,
        ],
    ]);
}

$canViewAllConversations = Authorization::can('conversations.view_all', $user);

$tasks = new Tasks();
$deals = new Deals();
$contacts = new Contacts();
$notifications = new Notifications();
$inbox = new UnifiedInbox();

$unreadInboxCount = $inbox->getThreadCount([
    'status' => 'unread',
    'viewer_user_id' => $userId,
    'can_view_all_conversations' => $canViewAllConversations,
    'owner_scope' => $conversationOwnerScope,
]);

$recentInbox = array_map('mobileConversationSummary', $inbox->getAll(5, 0, [
    'viewer_user_id' => $userId,
    'can_view_all_conversations' => $canViewAllConversations,
    'owner_scope' => $conversationOwnerScope,
]));

$myTasks = array_map('mobileTaskSummary', $tasks->getAll(['assigned_to' => $userId], 5, 0));
$recentDeals = array_map('mobileDealSummary', $deals->getAll(5, 0, ['assigned_to' => $userId]));
$recentContacts = array_map('mobileContactSummary', $contacts->getAll(5, 0, null, 'mine_unassigned', $userId));
$dashboardSummary = [
    'unread_conversations' => $unreadInboxCount,
    'overdue_tasks' => $tasks->getCount(['assigned_to' => $userId, 'overdue' => true]),
    'tasks_due_today' => $tasks->getCount(['assigned_to' => $userId, 'due_today' => true]),
    'open_deals' => $deals->getCount(['assigned_to' => $userId, 'exclude_stages' => ['closed_won', 'closed_lost']]),
    'unread_notifications' => $notifications->getUnreadCount($userId),
];
$aiHome = mobileAiBuildCompactHomePayload($userId, $user, $dashboardSummary, $conversationOwnerScope);
$canViewAutomationBattery = Authorization::can('feature.automation_battery', $user)
    || Authorization::can('feature.automation_readiness', $user)
    || Authorization::hasAccessProfilePermission('feature.automation_readiness_card', $user);
$automationBattery = $canViewAutomationBattery
    ? (new AutomationBatteryService())->getMobileStatus($userId, $userId)
    : null;
$founderCommandCenter = null;
if ((new FounderCommandCenterAccessService())->canView($user, $workspaceId)) {
    try {
        $allowMarketing = Authorization::can('marketing.read', $user);
        $commandCenterService = new FounderCommandCenterService();
        $founderCommandCenter = (new CacheManager())->getWithCache(
            implode(':', [
                'mobile_founder_command_center',
                'v2',
                $workspaceId,
                $userId,
                $allowMarketing ? 'marketing' : 'no_marketing',
            ]),
            static fn(): array => $commandCenterService->compactForMobile(
                $commandCenterService->build($workspaceId, $userId, ['allow_marketing' => $allowMarketing])
            ),
            45
        );
    } catch (\Throwable $e) {
        error_log('Mobile Founder Command Center failed: ' . $e->getMessage());
    }
}

$payload = [
    'generated_at' => gmdate('c'),
    'workspace_id' => $workspaceId,
    'active_workspace_id' => $workspaceId,
    'workspace_version' => $workspaceId > 0 ? 'workspace-' . $workspaceId : 'workspace-none',
    'summary' => $dashboardSummary,
    'ai_today' => $aiHome['today_brief'],
    'top_ai_actions' => $aiHome['top_actions'],
    'workspace_billing' => $workspaceBilling,
    'saas_billing' => $saasBilling,
    'billing_blocked' => !empty($saasBilling['billing_blocked']),
    'subscription_status' => (string) ($saasBilling['subscription_status'] ?? 'inactive'),
    'token_balance' => (int) ($saasBilling['token_balance'] ?? 0),
    'available_tokens' => (int) ($saasBilling['available_tokens'] ?? 0),
    'ai_blocked_reason' => $saasBilling['ai_blocked_reason'] ?? null,
    'conversation_owner_scope' => $conversationOwnerScope,
    'recent_conversations' => $recentInbox,
    'my_tasks' => $myTasks,
    'recent_deals' => $recentDeals,
    'recent_contacts' => $recentContacts,
];

if ($automationBattery !== null) {
    $payload['automation_battery'] = $automationBattery;
}
if ($founderCommandCenter !== null) {
    $payload['founder_command_center'] = $founderCommandCenter;
}

mobileJson([
    'success' => true,
    'data' => $payload,
]);
