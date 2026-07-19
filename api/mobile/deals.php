<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';

use CRM\Database;
use CRM\Modules\Documents;
use CRM\Modules\Deals;
use CRM\Modules\Invoices;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$deals = new Deals();
$documents = new Documents();
$invoices = new Invoices();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = mobileRequestBody();
    $id = (int) ($input['id'] ?? 0);
    if ($id > 0) {
        $deal = $deals->getById($id);
        if (!$deal || !in_array($userId, array_filter([(int) ($deal['assigned_to'] ?? 0), (int) ($deal['created_by'] ?? 0)]), true)) {
            mobileJson(['error' => 'Deal not found or not accessible.'], 404);
        }

        $updates = [];
        foreach (['stage', 'title', 'description', 'expected_close_date', 'value', 'probability', 'currency', 'contact_id'] as $field) {
            if (array_key_exists($field, $input)) {
                $updates[$field] = $input[$field];
            }
        }
        if ($updates === []) {
            mobileJson(['error' => 'No supported deal updates provided.'], 422);
        }

        $deals->update($id, $updates);
        mobileJson(['success' => true, 'data' => mobileDealSummary($deals->getById($id) ?? [])]);
    }

    if (empty($input['title'])) {
        mobileJson(['error' => 'Deal title is required.'], 422);
    }

    $dealId = $deals->create([
        'title' => (string) ($input['title'] ?? ''),
        'description' => (string) ($input['description'] ?? ''),
        'contact_id' => !empty($input['contact_id']) ? (int) $input['contact_id'] : null,
        'assigned_to' => !empty($input['assigned_to']) ? (int) $input['assigned_to'] : $userId,
        'created_by' => $userId,
        'stage' => (string) ($input['stage'] ?? 'prospecting'),
        'value' => !empty($input['value']) ? (float) $input['value'] : 0,
        'probability' => !empty($input['probability']) ? (int) $input['probability'] : 0,
        'expected_close_date' => $input['expected_close_date'] ?? null,
        'currency' => (string) ($input['currency'] ?? 'USD'),
        'lead_source' => (string) ($input['lead_source'] ?? 'mobile_app'),
    ]);

    mobileJson([
        'success' => true,
        'data' => array_merge(
            mobileDealSummary($deals->getById($dealId) ?? []),
            ['result_route' => '/deals/' . $dealId]
        ),
    ]);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $deal = $deals->getById($id);
    if (!$deal || !in_array($userId, array_filter([(int) ($deal['assigned_to'] ?? 0), (int) ($deal['created_by'] ?? 0)]), true)) {
        mobileJson(['error' => 'Deal not found or not accessible.'], 404);
    }
    mobileJson([
        'success' => true,
        'data' => array_merge(
            mobileDealSummary($deal),
            [
                'documents' => $documents->canUserAccessEntity($user, 'deal', $id, 'read')
                    ? array_map('mobileDocumentSummary', $documents->getEntityDocuments('deal', $id))
                    : [],
                'invoices' => array_map('mobileInvoiceSummary', $invoices->list(['deal_id' => $id], 10, 0)),
                'generated_at' => gmdate('c'),
            ]
        ),
    ]);
}

$filters = [];
if (array_key_exists('assigned_to', $_GET) && $_GET['assigned_to'] !== '') {
    $filters['assigned_to'] = (int) $_GET['assigned_to'];
} else {
    $filters['assigned_to'] = $userId;
}
foreach (['stage', 'search', 'contact_id'] as $field) {
    if (!empty($_GET[$field])) {
        $filters[$field] = is_numeric($_GET[$field]) ? (int) $_GET[$field] : (string) $_GET[$field];
    }
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

mobileJson([
    'success' => true,
    'data' => [
        'items' => array_map('mobileDealSummary', $deals->getAll($limit, $offset, $filters)),
        'stats' => $deals->getPipelineStats($filters),
        'total' => $deals->getCount($filters),
    ],
]);
