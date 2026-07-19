<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Companies;
use CRM\Modules\Documents;
use CRM\Modules\Invoices;
use CRM\Services\WorkspaceScopeService;

function mobileCanAccessCompany(array $company, int $viewerUserId, array $viewer): bool
{
    if (Authorization::can('contacts.view_all', $viewer)) {
        return true;
    }

    $companyId = (int) ($company['id'] ?? 0);
    if ($companyId <= 0) {
        return false;
    }

    $assignedTo = (int) ($company['assigned_to'] ?? 0);
    if ($assignedTo === 0 || $assignedTo === $viewerUserId) {
        return true;
    }

    $workspaceScope = new WorkspaceScopeService();
    $workspace = $workspaceScope->workspaceClause('c.');
    $match = Database::queryOne(
        "SELECT 1
         FROM contacts c
         WHERE {$workspace['sql']}
           AND c.company_id = ?
           AND (COALESCE(c.assigned_to, 0) = 0 OR c.assigned_to = ?)
         LIMIT 1",
        array_merge($workspace['params'], [$companyId, $viewerUserId])
    );

    return !empty($match);
}

$auth = mobileRequireAuth();
$userId = (int) ($auth['user_id'] ?? 0);
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$workspaceScope = new WorkspaceScopeService();
$activeWorkspaceId = $workspaceScope->requireActiveWorkspaceId();
$companies = new Companies();
$invoices = new Invoices();
$documents = new Documents();
$canViewAllContacts = Authorization::can('contacts.view_all', $user);

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $company = $companies->getById($id);
    if (!$company || !mobileCanAccessCompany($company, $userId, $user)) {
        mobileJson(['error' => 'Company not found or not accessible.'], 404);
    }

    $linkedContacts = $companies->getLinkedContacts($id);
    $contactIds = array_values(array_filter(array_map(
        static fn(array $contact): int => (int) ($contact['id'] ?? 0),
        $linkedContacts
    )));

    $deals = Database::query(
        "SELECT d.*,
                c.first_name AS contact_first_name,
                c.last_name AS contact_last_name,
                c.email AS contact_email,
                co.name AS company_name
         FROM deals d
         LEFT JOIN contacts c ON c.id = d.contact_id AND c.workspace_id = d.workspace_id
         LEFT JOIN companies co ON co.id = d.company_id AND co.workspace_id = d.workspace_id
         WHERE d.workspace_id = ?
           AND d.company_id = ?
         ORDER BY d.created_at DESC
         LIMIT 20",
        [$activeWorkspaceId, $id]
    );

    $tasks = [];
    if ($contactIds !== []) {
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $taskParams = $contactIds;
        $taskSql = "SELECT t.*,
                           c.first_name AS contact_first_name,
                           c.last_name AS contact_last_name,
                           c.email AS contact_email,
                           u1.email AS assigned_to_email,
                           target.title AS target_title,
                           target.deadline AS target_deadline
                    FROM tasks t
                    LEFT JOIN contacts c ON c.id = t.contact_id AND c.workspace_id = t.workspace_id
                    LEFT JOIN users u1 ON u1.id = t.assigned_to
                    LEFT JOIN targets target ON target.id = t.target_id
                    WHERE t.workspace_id = ?
                      AND t.contact_id IN ($placeholders)";
        array_unshift($taskParams, $activeWorkspaceId);
        if (!Authorization::can('tasks.view_all', $user)) {
            $taskSql .= ' AND t.assigned_to = ?';
            $taskParams[] = $userId;
        }
        $taskSql .= ' ORDER BY t.created_at DESC LIMIT 20';
        $tasks = Database::query($taskSql, $taskParams);
    }

    $invoiceFilters = ['company_id' => $id];
    if (!$canViewAllContacts) {
        $invoiceFilters['assigned_to'] = $userId;
    }

    mobileJson([
        'success' => true,
        'data' => [
            'company' => mobileCompanySummary($company),
            'contacts' => array_map('mobileContactSummary', $linkedContacts),
            'deals' => array_map('mobileDealSummary', $deals),
            'tasks' => array_map('mobileTaskSummary', $tasks),
            'invoices' => array_map('mobileInvoiceSummary', $invoices->list($invoiceFilters, 20, 0)),
            'documents' => $documents->canUserAccessEntity($user, 'company', $id, 'read')
                ? array_map('mobileDocumentSummary', $documents->getEntityDocuments('company', $id))
                : [],
            'stats' => [
                'contacts' => count($linkedContacts),
                'deals' => count($deals),
                'tasks' => count($tasks),
                'invoices' => $invoices->count($invoiceFilters),
                'documents' => $documents->getEntityDocumentCount('company', $id),
            ],
            'generated_at' => gmdate('c'),
        ],
    ]);
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$search = trim((string) ($_GET['search'] ?? ''));
$ownerScope = mobileResolveVisibilityScope($user, 'contacts.view_all', $_GET['owner_scope'] ?? null);

$rawItems = $companies->list(
    $ownerScope === 'all' && $canViewAllContacts ? $limit : max($limit * 3, $limit),
    $offset,
    $search !== '' ? $search : null
);
$items = ($ownerScope === 'all' && $canViewAllContacts)
    ? $rawItems
    : array_values(array_filter(
        $rawItems,
        static fn(array $company): bool => mobileCanAccessCompany($company, $userId, $user)
    ));
$items = array_slice($items, 0, $limit);

mobileJson([
    'success' => true,
    'data' => [
        'items' => array_map('mobileCompanySummary', $items),
        'owner_scope' => $ownerScope,
        'generated_at' => gmdate('c'),
    ],
]);
