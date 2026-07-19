<?php

require_once dirname(__DIR__, 2) . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/_conversation_detail.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Documents;
use CRM\Modules\UnifiedInbox;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

$auth = mobileRequireAuth();
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) ($auth['user_id'] ?? 0)]) ?? [];
$userId = (int) ($auth['user_id'] ?? 0);
$conversationId = (int) ($_POST['conversation_id'] ?? 0);
$canViewAll = Authorization::can('conversations.view_all', $user);
$ownerScope = mobileResolveConversationOwnerScope($user, $_POST['owner_scope'] ?? $_GET['owner_scope'] ?? null);

if ($conversationId <= 0 || !(new UnifiedInbox())->canUserAccessCommunication($conversationId, $userId, $canViewAll, $ownerScope)) {
    mobileJson(['error' => 'Conversation not found or not accessible.'], 404);
}

if (empty($_FILES['attachment']) || !is_array($_FILES['attachment'])) {
    mobileJson(['error' => 'Attachment file is required.'], 422);
}

try {
    $documents = new Documents();
    $documentId = $documents->upload(
        $_FILES['attachment'],
        'communication',
        $conversationId,
        [
            'description' => trim((string) ($_POST['description'] ?? '')),
            'uploaded_by' => $userId,
        ]
    );
    $document = $documents->getById($documentId);

    mobileJson([
        'success' => true,
        'message' => 'Attachment uploaded successfully.',
        'data' => array_merge(
            mobileConversationDetailData($conversationId, $userId),
            ['owner_scope' => $ownerScope]
        ),
    ]);
} catch (\Throwable $e) {
    error_log('Mobile conversation attachment upload failed for communication_id=' . $conversationId . ' user_id=' . $userId . ': ' . $e->getMessage());
    mobileJson(['error' => trim((string) $e->getMessage()) ?: 'Could not upload this attachment.'], 422);
}
