<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_conversation_detail.php';

use CRM\Database;
use CRM\Services\MobileConversationDraftService;

$auth = mobileRequireAuth();
$userId = (int) ($auth['user_id'] ?? 0);
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$service = new MobileConversationDraftService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $status = isset($_GET['status']) && strtolower((string) $_GET['status']) !== 'all'
        ? trim((string) $_GET['status'])
        : null;
    mobileJson([
        'success' => true,
        'data' => [
            'items' => $service->list($userId, $status),
            'drafts' => $service->list($userId, $status),
        ],
    ]);
}

if ($method !== 'POST') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

$input = mobileRequestBody();
$action = trim((string) ($input['action'] ?? 'update'));
$draftId = (int) ($input['draft_id'] ?? $input['id'] ?? 0);
if ($draftId <= 0) {
    mobileJson(['error' => 'Draft id is required.'], 422);
}
$input['owner_scope'] = mobileResolveConversationOwnerScope($user, $input['owner_scope'] ?? $_GET['owner_scope'] ?? null);

try {
    if ($action === 'delete') {
        $service->delete($draftId, $userId);
        mobileJson(['success' => true, 'message' => 'Draft deleted.', 'data' => ['deleted' => true]]);
    }

    if ($action === 'send') {
        $conversationId = (int) ($input['conversation_id'] ?? $input['communication_id'] ?? 0);
        $result = $service->send($draftId, $conversationId, $userId, $user, $input);
        $data = ['draft' => $result['draft'], 'send_result' => $result['send_result']];
        if ($conversationId > 0) {
            $data['conversation'] = mobileConversationDetailData($conversationId, $userId);
        }
        mobileJson(['success' => true, 'message' => 'Draft sent.', 'data' => $data]);
    }

    $draft = $service->update($draftId, $userId, $input);
    mobileJson(['success' => true, 'message' => 'Draft updated.', 'data' => ['draft' => $draft]]);
} catch (\RuntimeException $e) {
    $status = (int) $e->getCode();
    mobileJson(['error' => $e->getMessage()], $status >= 400 && $status <= 599 ? $status : 422);
} catch (\Throwable $e) {
    error_log('Mobile conversation draft action failed for user_id=' . $userId . ': ' . $e->getMessage());
    mobileJson(['error' => 'Could not update this draft.'], 422);
}
