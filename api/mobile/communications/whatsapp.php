<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_serializers.php';
require_once __DIR__ . '/../_feature_helpers.php';

use CRM\Authorization;
use CRM\Database;

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$workspaceId = mobileWorkspaceId($auth);
mobileRequireCommunicationChannelRuntime($workspaceId, $user, 'whatsapp');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

try {
    if (!Database::tableExists('whatsapp_messages')) {
        mobileJson([
            'success' => true,
            'data' => [
                'items' => [],
                'generated_at' => gmdate('c'),
            ],
        ]);
    }

    $where = ['1=1'];
    $params = [];
    if (Database::columnExists('whatsapp_messages', 'workspace_id')) {
        $where[] = 'wm.workspace_id = ?';
        $params[] = $workspaceId;
    }
    $direction = strtolower(trim((string) ($_GET['direction'] ?? '')));
    if (in_array($direction, ['inbound', 'outbound'], true)) {
        $where[] = 'wm.direction = ?';
        $params[] = $direction;
    }
    $status = trim((string) ($_GET['status'] ?? ''));
    if ($status !== '') {
        $where[] = 'wm.status = ?';
        $params[] = $status;
    }
    if (!empty($_GET['contact_id'])) {
        $where[] = 'wm.contact_id = ?';
        $params[] = (int) $_GET['contact_id'];
    }
    if (!Authorization::can('contacts.view_all', $user)) {
        $where[] = '(wm.contact_id IS NULL OR c.assigned_to = ? OR c.assigned_to IS NULL OR c.assigned_to = 0)';
        $params[] = (int) ($auth['user_id'] ?? 0);
    }

    $limit = mobileBoundedLimit($_GET['limit'] ?? null, 50, 100);
    $offset = mobileBoundedOffset($_GET['offset'] ?? 0);
    $rows = Database::query(
        "SELECT wm.*, c.first_name, c.last_name, c.phone AS contact_phone, u.email AS user_email
         FROM whatsapp_messages wm
         LEFT JOIN contacts c ON wm.contact_id = c.id" . (Database::columnExists('whatsapp_messages', 'workspace_id') ? ' AND c.workspace_id = wm.workspace_id' : '') . "
         LEFT JOIN users u ON wm.user_id = u.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY wm.created_at DESC, wm.id DESC
         LIMIT {$limit} OFFSET {$offset}",
        $params
    );

    mobileJson([
        'success' => true,
        'data' => [
            'items' => array_map('mobileWhatsAppMessageSummary', $rows),
            'limit' => $limit,
            'offset' => $offset,
            'generated_at' => gmdate('c'),
        ],
    ]);
} catch (Throwable $e) {
    mobileJson([
        'error' => $e->getMessage(),
        'error_code' => 'whatsapp_history_failed',
    ], 422);
}
