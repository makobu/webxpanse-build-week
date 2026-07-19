<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';
require_once __DIR__ . '/ai/_helpers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\UnifiedInbox;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceContext;

$auth = mobileRequireAuth();
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $auth['user_id']]) ?? [];
$userId = (int) $auth['user_id'];
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? ($auth['workspace_id'] ?? 0));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$communicationGate = new WorkspaceCommunicationGateService();
if ($method !== 'GET' && !$communicationGate->isRuntimeReady($workspaceId, $user)) {
    mobileJson($communicationGate->jsonBlockPayload($workspaceId, $user), 403);
}
$inbox = new UnifiedInbox();
$ownerScope = mobileResolveConversationOwnerScope($user, $_GET['owner_scope'] ?? null);

$filters = [
    'viewer_user_id' => $userId,
    'can_view_all_conversations' => Authorization::can('conversations.view_all', $user),
    'owner_scope' => $ownerScope,
];

if (!empty($_GET['status'])) {
    $status = (string) $_GET['status'];
    if ($status === 'archived') {
        $filters['archived'] = true;
    } elseif ($status !== 'all') {
        $filters['status'] = $status;
    }
}
if (!empty($_GET['channel'])) {
    $filters['channel'] = (string) $_GET['channel'];
}
if (!empty($_GET['search'])) {
    $filters['search'] = (string) $_GET['search'];
}
if (!empty($_GET['contact_id'])) {
    $filters['contact_id'] = (int) $_GET['contact_id'];
}
if (!empty($_GET['triage_priority'])) {
    $filters['triage_priority'] = (string) $_GET['triage_priority'];
}
if (!empty($_GET['triage_status'])) {
    $filters['triage_status'] = (string) $_GET['triage_status'];
}
if (isset($_GET['include_body_search'])) {
    $filters['include_body_search'] = (string) $_GET['include_body_search'] === '1'
        || strtolower((string) $_GET['include_body_search']) === 'true';
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = isset($_GET['offset'])
    ? max(0, (int) $_GET['offset'])
    : (($page - 1) * $limit);
$threadMode = (isset($_GET['mode']) && (string) $_GET['mode'] === 'threads')
    || (isset($_GET['threads']) && (string) $_GET['threads'] === 'true');
$contactMode = isset($_GET['mode']) && (string) $_GET['mode'] === 'contacts';

$rows = $contactMode
    ? $inbox->getContactConversationSummaries($limit, $offset, $filters)
    : ($threadMode
        ? $inbox->getThreadSummaries($limit, $offset, $filters)
        : $inbox->getAll($limit, $offset, $filters));
$items = array_map(
    static fn(array $row): array => mobileAiDecorateConversationItem($row, $userId),
    $rows
);
$total = $contactMode
    ? $inbox->getContactConversationCount($filters)
    : ($threadMode ? $inbox->getThreadCount($filters) : $inbox->getCount($filters));
$totalPages = (int) ceil($total / $limit);

$data = [
    'workspace_id' => $workspaceId,
    'active_workspace_id' => $workspaceId,
    'workspace_version' => $workspaceId > 0 ? 'workspace-' . $workspaceId : 'workspace-none',
    'cache_scope' => sprintf('workspace:%d:inbox:%s:%s', $workspaceId, $ownerScope, $contactMode ? 'contacts' : ($threadMode ? 'threads' : 'messages')),
    'items' => $items,
    'counts' => $inbox->getChannelStats($filters),
    'total' => $total,
    'page' => $page,
    'total_pages' => $totalPages,
    'limit' => $limit,
    'offset' => $offset,
    'mode' => $contactMode ? 'contacts' : ($threadMode ? 'threads' : 'messages'),
    'owner_scope' => $ownerScope,
    'generated_at' => date(DATE_ATOM),
];

if ($contactMode) {
    $data['contacts'] = $items;
} elseif ($threadMode) {
    $data['threads'] = $items;
} else {
    $data['communications'] = $items;
}

mobileJson([
    'success' => true,
    'data' => $data,
]);
