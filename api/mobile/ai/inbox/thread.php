<?php

require_once __DIR__ . '/../../_bootstrap.php';
require_once __DIR__ . '/../_helpers.php';

use CRM\Database;
use CRM\Services\WorkspaceContext;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$conversationId = (int) ($_GET['conversation_id'] ?? $_GET['id'] ?? 0);
if ($conversationId <= 0) {
    mobileJson(['error' => 'Conversation id is required.'], 422);
}

$row = Database::queryOne(
    'SELECT id FROM communications WHERE workspace_id = ? AND id = ? LIMIT 1',
    [(int) (WorkspaceContext::currentWorkspaceId() ?? 0), $conversationId]
);
if (!$row) {
    mobileJson(['error' => 'Conversation not found or not accessible.'], 404);
}

mobileJson([
    'success' => true,
    'data' => mobileAiConversationInsights($conversationId, $userId),
]);
