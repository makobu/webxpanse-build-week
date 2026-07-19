<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Documents;
use CRM\Modules\Invoices;

function mobileCanAccessInvoice(array $invoice, int $viewerUserId, array $viewer): bool
{
    if (Authorization::can('contacts.view_all', $viewer)) {
        return true;
    }

    $assignedTo = (int) ($invoice['assigned_to'] ?? 0);
    $createdBy = (int) ($invoice['created_by'] ?? 0);
    if ($assignedTo === $viewerUserId || $createdBy === $viewerUserId) {
        return true;
    }

    $contactId = (int) ($invoice['contact_id'] ?? 0);
    if ($contactId > 0) {
        $contact = Database::queryOne('SELECT assigned_to FROM contacts WHERE id = ? LIMIT 1', [$contactId]) ?? [];
        $contactAssigned = (int) ($contact['assigned_to'] ?? 0);
        return $contactAssigned === 0 || $contactAssigned === $viewerUserId;
    }

    return false;
}

$auth = mobileRequireAuth();
$userId = (int) ($auth['user_id'] ?? 0);
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$invoices = new Invoices();
$documents = new Documents();
$canViewAllContacts = Authorization::can('contacts.view_all', $user);

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $invoice = $invoices->getById($id);
    if (!$invoice || !mobileCanAccessInvoice($invoice, $userId, $user)) {
        mobileJson(['error' => 'Invoice not found or not accessible.'], 404);
    }

    $documentType = (string) ($invoice['document_type'] ?? 'invoice');
    $documentsForInvoice = [];
    if ($documents->canUserAccessEntity($user, 'invoice', $id, 'read')) {
        $documentsForInvoice = $documents->getEntityDocuments('invoice', $id);
    } elseif ($documents->canUserAccessEntity($user, $documentType, $id, 'read')) {
        $documentsForInvoice = $documents->getEntityDocuments($documentType, $id);
    }

    mobileJson([
        'success' => true,
        'data' => array_merge(
            mobileInvoiceSummary($invoice),
            [
                'line_items' => array_map(
                    'mobileInvoiceLineItemSummary',
                    is_array($invoice['line_items'] ?? null) ? $invoice['line_items'] : []
                ),
                'activity_log' => is_array($invoice['activity_log'] ?? null) ? $invoice['activity_log'] : [],
                'status_history' => is_array($invoice['status_history'] ?? null) ? $invoice['status_history'] : [],
                'delivery_log' => is_array($invoice['delivery_log'] ?? null) ? $invoice['delivery_log'] : [],
                'documents' => array_map('mobileDocumentSummary', $documentsForInvoice),
                'generated_at' => gmdate('c'),
            ]
        ),
    ]);
}

$filters = [];
if (!$canViewAllContacts) {
    $filters['assigned_to'] = $userId;
} elseif (!empty($_GET['assigned_to'])) {
    $filters['assigned_to'] = (int) $_GET['assigned_to'];
}

foreach (['document_type', 'status', 'search'] as $field) {
    if (!empty($_GET[$field])) {
        $filters[$field] = (string) $_GET[$field];
    }
}
foreach (['contact_id', 'company_id', 'deal_id'] as $field) {
    if (!empty($_GET[$field])) {
        $filters[$field] = (int) $_GET[$field];
    }
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

mobileJson([
    'success' => true,
    'data' => [
        'items' => array_map('mobileInvoiceSummary', $invoices->list($filters, $limit, $offset)),
        'total' => $invoices->count($filters),
        'generated_at' => gmdate('c'),
    ],
]);
