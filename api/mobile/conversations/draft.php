<?php

require_once dirname(__DIR__) . '/_bootstrap.php';

use CRM\Database;
use CRM\Services\MobileConversationDraftService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

$auth = mobileRequireAuth();
$userId = (int) ($auth['user_id'] ?? 0);
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$input = mobileRequestBody();
$input['owner_scope'] = mobileResolveConversationOwnerScope($user, $input['owner_scope'] ?? $_GET['owner_scope'] ?? null);

try {
    $mode = strtolower(trim((string) ($input['mode'] ?? '')));
    $hasManualBody = array_key_exists('body', $input) && trim((string) $input['body']) !== '';
    $result = ($mode === 'manual' || (!empty($input['save']) && $hasManualBody && empty($input['generate'])))
        ? (new MobileConversationDraftService())->saveManual($userId, $user, $input)
        : (new MobileConversationDraftService())->generate($userId, $user, $input);
    mobileJson([
        'success' => true,
        'message' => !empty($result['draft']['draft_id']) ? 'Draft saved.' : 'Draft generated.',
        'data' => $result,
    ]);
} catch (\RuntimeException $e) {
    $status = (int) $e->getCode();
    mobileJson(['error' => $e->getMessage()], $status >= 400 && $status <= 599 ? $status : 422);
} catch (\Throwable $e) {
    error_log('Mobile conversation draft generation failed for user_id=' . $userId . ': ' . $e->getMessage());
    mobileJson(['error' => 'Could not prepare this draft.'], 422);
}
