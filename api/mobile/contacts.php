<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';
require_once __DIR__ . '/ai/_helpers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\Deals;
use CRM\Modules\Documents;
use CRM\Modules\Invoices;
use CRM\Modules\Notes;
use CRM\Modules\Tasks;
use CRM\Services\ContactIntelligenceService;

$auth = mobileRequireAuth();
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $auth['user_id']]) ?? [];
$userId = (int) $auth['user_id'];
$contacts = new Contacts();
$deals = new Deals();
$documents = new Documents();
$invoices = new Invoices();
$tasks = new Tasks();
$canViewAllContacts = Authorization::can('contacts.view_all', $user);
$ownerScope = $canViewAllContacts && (($_GET['owner_scope'] ?? '') === 'all') ? 'all' : 'mine_unassigned';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = mobileRequestBody();

    // Claim an unassigned contact (assign it to the calling user)
    if (($input['action'] ?? '') === 'claim') {
        $contactId = (int) ($input['contact_id'] ?? 0);
        if ($contactId < 1) {
            mobileJson(['error' => 'contact_id is required.'], 422);
        }
        $contact = $contacts->getById($contactId);
        if (!$contact) {
            mobileJson(['error' => 'Contact not found.'], 404);
        }
        $currentAssignee = (int) ($contact['assigned_to'] ?? 0);
        if ($currentAssignee !== 0 && $currentAssignee !== $userId && !$canViewAllContacts) {
            mobileJson(['error' => 'Contact is already assigned to another user.'], 403);
        }
        $contacts->update($contactId, ['assigned_to' => $userId]);
        $updated = $contacts->getById($contactId) ?? [];
        mobileJson(['success' => true, 'data' => ['contact' => mobileContactSummary($updated)]]);
    }

    $id = (int) ($input['id'] ?? 0);

    if ($id > 0) {
        $contact = $contacts->getById($id);
        if (!$contact) {
            mobileJson(['error' => 'Contact not found.'], 404);
        }
        if (!$canViewAllContacts) {
            $assignedTo = (int) ($contact['assigned_to'] ?? 0);
            $allowed = $assignedTo === $userId;
            if (!$allowed) {
                mobileJson(['error' => 'Contact not accessible.'], 403);
            }
        }

        $updates = [];
        foreach ([
            'first_name',
            'last_name',
            'email',
            'phone',
            'company',
            'company_id',
            'lead_source',
            'stage',
            'assigned_to',
            'job_title',
            'location',
            'company_website',
            'linkedin_url',
            'twitter_url',
            'timezone',
        ] as $field) {
            if (array_key_exists($field, $input)) {
                $updates[$field] = $input[$field];
            }
        }
        if ($updates === []) {
            mobileJson(['error' => 'No supported contact updates provided.'], 422);
        }
        if (!$canViewAllContacts && array_key_exists('assigned_to', $updates)) {
            $updates['assigned_to'] = $userId;
        }

        $contacts->update($id, $updates);
        $fresh = $contacts->getById($id) ?? [];
        mobileJson(['success' => true, 'data' => ['contact' => mobileContactSummary($fresh)]]);
    }

    foreach (['first_name', 'email'] as $requiredField) {
        if (empty($input[$requiredField])) {
            mobileJson(['error' => ucfirst(str_replace('_', ' ', $requiredField)) . ' is required.'], 422);
        }
    }

    $created = $contacts->create([
        'first_name' => (string) ($input['first_name'] ?? ''),
        'last_name' => (string) ($input['last_name'] ?? ''),
        'email' => (string) ($input['email'] ?? ''),
        'phone' => (string) ($input['phone'] ?? ''),
        'company' => (string) ($input['company'] ?? ''),
        'company_id' => !empty($input['company_id']) ? (int) $input['company_id'] : null,
        'lead_source' => (string) ($input['lead_source'] ?? 'mobile_app'),
        'assigned_to' => $canViewAllContacts && !empty($input['assigned_to']) ? (int) $input['assigned_to'] : $userId,
        'created_by' => $userId,
        'job_title' => (string) ($input['job_title'] ?? ''),
        'location' => (string) ($input['location'] ?? ''),
        'company_website' => (string) ($input['company_website'] ?? ''),
        'linkedin_url' => (string) ($input['linkedin_url'] ?? ''),
        'twitter_url' => (string) ($input['twitter_url'] ?? ''),
        'timezone' => (string) ($input['timezone'] ?? ''),
    ]);

    if (($created['status'] ?? '') === 'duplicate') {
        mobileJson([
            'error' => 'A matching contact already exists.',
            'data' => ['duplicate' => true, 'matches' => $created['matches'] ?? []],
        ], 409);
    }

    $contactId = (int) ($created['id'] ?? 0);
    $contact = $contactId > 0 ? ($contacts->getById($contactId) ?? []) : [];
    mobileJson([
        'success' => true,
        'data' => [
            'contact' => mobileContactSummary($contact),
            'result_route' => '/contacts/' . $contactId,
        ],
    ]);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $contact = $contacts->getById($id);
    if (!$contact) {
        mobileJson(['error' => 'Contact not found.'], 404);
    }
    if (!$canViewAllContacts) {
        $assignedTo = (int) ($contact['assigned_to'] ?? 0);
        $allowed = $assignedTo === 0 || $assignedTo === $userId;
        if (!$allowed) {
            mobileJson(['error' => 'Contact not accessible.'], 404);
        }
    }

    $notes = new Notes();
    $contactIntelligenceService = new ContactIntelligenceService();
    $contactIntelligence = $contactIntelligenceService->getStoredOrCompute($id) ?? [];

    mobileJson([
        'success' => true,
        'data' => [
            'contact' => mobileContactSummary($contact),
            'deals' => array_map('mobileDealSummary', $deals->getAll(10, 0, ['contact_id' => $id])),
            'tasks' => array_map(
                'mobileTaskSummary',
                $tasks->getAll(
                    mobileCanViewAllTasks($user)
                        ? ['contact_id' => $id]
                        : ['contact_id' => $id, 'assigned_to' => $userId],
                    10,
                    0
                )
            ),
            'notes' => array_map(
                'mobileNoteSummary',
                $notes->getEntityNotes('contact', $id, false, $userId)
            ),
            'timeline' => array_map(
                'mobileTimelineItemSummary',
                $contactIntelligenceService->buildUnifiedTimeline($id, 30)
            ),
            'relationship_summary' => mobileRelationshipSummary(
                is_array($contactIntelligence['relationship_summary'] ?? null)
                    ? $contactIntelligence['relationship_summary']
                    : []
            ),
            'account_context' => mobileAccountContextSummary(
                is_array($contactIntelligence['account_context'] ?? null)
                    ? $contactIntelligence['account_context']
                    : []
            ),
            'data_quality' => mobileDataQualitySummary(
                is_array($contactIntelligence['data_quality'] ?? null)
                    ? $contactIntelligence['data_quality']
                    : []
            ),
            'documents' => $documents->canUserAccessEntity($user, 'contact', $id, 'read')
                ? array_map('mobileDocumentSummary', $documents->getEntityDocuments('contact', $id))
                : [],
            'invoices' => array_map('mobileInvoiceSummary', $invoices->list(['contact_id' => $id], 10, 0)),
            'ai' => mobileAiContactInsights($id),
            'generated_at' => (string) ($contactIntelligence['generated_at'] ?? date(DATE_ATOM)),
        ],
    ]);
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$stage = !empty($_GET['stage']) ? (string) $_GET['stage'] : null;
$search = trim((string) ($_GET['search'] ?? ''));

if ($search !== '') {
    $items = $contacts->search($search, $limit, $offset, $ownerScope, $userId);
} else {
    $items = $contacts->getAll($limit, $offset, $stage, $ownerScope, $userId);
}

mobileJson([
    'success' => true,
    'data' => [
        'items' => array_map('mobileContactSummary', $items),
        'owner_scope' => $ownerScope,
    ],
]);
