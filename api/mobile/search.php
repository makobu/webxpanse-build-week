<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';
require_once __DIR__ . '/_feature_helpers.php';
require_once __DIR__ . '/ai/_helpers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Activities;
use CRM\Modules\Companies;
use CRM\Modules\Contacts;
use CRM\Modules\Deals;
use CRM\Modules\Events;
use CRM\Modules\Forms;
use CRM\Modules\Invoices;
use CRM\Modules\Targets;
use CRM\Modules\Tasks;
use CRM\Modules\UnifiedInbox;
use CRM\Services\MeetingBookingService;
use CRM\Services\WorkspaceScopeService;

function mobileSearchCanAccessCompany(array $company, int $viewerUserId, array $viewer): bool
{
    if (Authorization::can('contacts.view_all', $viewer)) {
        return true;
    }

    $assignedTo = (int) ($company['assigned_to'] ?? 0);
    if ($assignedTo === 0 || $assignedTo === $viewerUserId) {
        return true;
    }

    $companyId = (int) ($company['id'] ?? 0);
    if ($companyId <= 0) {
        return false;
    }

    $workspaceScope = new WorkspaceScopeService();
    $workspace = $workspaceScope->workspaceClause();
    $match = Database::queryOne(
        "SELECT 1
         FROM contacts
         WHERE {$workspace['sql']}
           AND company_id = ?
           AND (COALESCE(assigned_to, 0) = 0 OR assigned_to = ?)
         LIMIT 1",
        array_merge($workspace['params'], [$companyId, $viewerUserId])
    );

    return !empty($match);
}

function mobileSearchItem(
    string $entityType,
    int $id,
    string $title,
    string $subtitle,
    ?string $route,
    array $payload = []
): array {
    return [
        'entity_type' => $entityType,
        'id' => $id,
        'title' => $title,
        'subtitle' => $subtitle,
        'route' => $route,
        'payload' => $payload,
    ];
}

$auth = mobileRequireAuth();
$userId = (int) ($auth['user_id'] ?? 0);
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$query = trim((string) ($_GET['q'] ?? ''));
$type = strtolower(trim((string) ($_GET['type'] ?? 'all')));
$limit = max(1, min(25, (int) ($_GET['limit'] ?? 8)));

if ($query === '') {
    mobileJson([
        'success' => true,
        'data' => [
            'items' => [],
            'contacts' => [],
            'deals' => [],
            'companies' => [],
            'tasks' => [],
            'events' => [],
            'invoices' => [],
            'conversations' => [],
            'targets' => [],
            'activities' => [],
            'forms' => [],
            'bookings' => [],
        ],
    ]);
}

$contactsModule = new Contacts();
$dealsModule = new Deals();
$companiesModule = new Companies();
$tasksModule = new Tasks();
$eventsModule = new Events();
$invoicesModule = new Invoices();
$inboxModule = new UnifiedInbox();
$targetsModule = new Targets();
$activitiesModule = new Activities();
$formsModule = new Forms();

$items = [];
$contacts = [];
$deals = [];
$companies = [];
$tasks = [];
$events = [];
$invoices = [];
$conversations = [];
$targets = [];
$activities = [];
$forms = [];
$bookings = [];
$conversationOwnerScope = mobileResolveConversationOwnerScope($user, $_GET['owner_scope'] ?? null);
$contactOwnerScope = Authorization::can('contacts.view_all', $user) && mobileRequestedAllScope($_GET['owner_scope'] ?? null)
    ? 'all'
    : 'mine_unassigned';

if ($type === 'all' || $type === 'contacts') {
    $contacts = array_map(
        'mobileContactSummary',
        $contactsModule->search($query, $limit, 0, $contactOwnerScope, $userId)
    );
    foreach ($contacts as $contact) {
        $items[] = mobileSearchItem(
            'contact',
            (int) ($contact['id'] ?? 0),
            (string) ($contact['full_name'] ?? ''),
            trim((string) (($contact['company'] ?? '') !== '' ? $contact['company'] : ($contact['email'] ?? ''))),
            '/contacts/' . (int) ($contact['id'] ?? 0),
            $contact
        );
    }
}

if ($type === 'all' || $type === 'deals') {
    $deals = array_map(
        'mobileDealSummary',
        $dealsModule->getAll($limit, 0, ['assigned_to' => $userId, 'search' => $query])
    );
    foreach ($deals as $deal) {
        $items[] = mobileSearchItem(
            'deal',
            (int) ($deal['id'] ?? 0),
            (string) ($deal['title'] ?? ''),
            trim((string) (($deal['company_name'] ?? '') !== '' ? $deal['company_name'] : ($deal['stage'] ?? ''))),
            '/deals/' . (int) ($deal['id'] ?? 0),
            $deal
        );
    }
}

if ($type === 'all' || $type === 'companies') {
    $companyRows = $companiesModule->search($query, $limit);
    $companies = [];
    foreach ($companyRows as $row) {
        $company = $companiesModule->getById((int) ($row['id'] ?? 0)) ?? $row;
        if (!mobileSearchCanAccessCompany($company, $userId, $user)) {
            continue;
        }
        $companies[] = mobileCompanySummary($company);
    }
    foreach ($companies as $company) {
        $items[] = mobileSearchItem(
            'company',
            (int) ($company['id'] ?? 0),
            (string) ($company['name'] ?? ''),
            trim((string) (($company['industry'] ?? '') !== '' ? $company['industry'] : ($company['website'] ?? ''))),
            '/companies/' . (int) ($company['id'] ?? 0),
            $company
        );
    }
}

if ($type === 'all' || $type === 'tasks') {
    $taskFilters = ['search' => $query];
    if (!Authorization::can('tasks.view_all', $user)) {
        $taskFilters['assigned_to'] = $userId;
    }
    $tasks = array_map('mobileTaskSummary', $tasksModule->getAll($taskFilters, $limit, 0));
    foreach ($tasks as $task) {
        $items[] = mobileSearchItem(
            'task',
            (int) ($task['id'] ?? 0),
            (string) ($task['title'] ?? ''),
            trim((string) (($task['status'] ?? '') !== '' ? $task['status'] : ($task['contact']['name'] ?? ''))),
            '/tasks/' . (int) ($task['id'] ?? 0),
            $task
        );
    }
}

if ($type === 'all' || $type === 'events') {
    $events = array_map(
        'mobileEventSummary',
        $eventsModule->getAllForUser(['search' => $query], $user, $limit, 0, 'ASC')
    );
    foreach ($events as $event) {
        $items[] = mobileSearchItem(
            'event',
            (int) ($event['id'] ?? 0),
            (string) ($event['title'] ?? ''),
            trim((string) (($event['contact_name'] ?? '') !== '' ? $event['contact_name'] : ($event['start_time'] ?? ''))),
            '/calendar/' . (int) ($event['id'] ?? 0),
            $event
        );
    }
}

if ($type === 'all' || $type === 'targets') {
    $targetFilters = ['search' => $query];
    if (!Authorization::can('targets.manage_all', $user)) {
        $targetFilters['user_id'] = $userId;
    }
    $targetRows = $targetsModule->getAll($targetFilters, $limit, 0);
    foreach ($targetRows as $targetRow) {
        if (!$targetsModule->canViewTarget($targetRow, $user)) {
            continue;
        }
        $target = mobileTargetSummary($targetRow);
        $targets[] = $target;
        $items[] = mobileSearchItem(
            'target',
            (int) ($target['id'] ?? 0),
            (string) ($target['title'] ?? ''),
            trim((string) (($target['status'] ?? '') !== '' ? $target['status'] : ($target['target_date'] ?? ''))),
            '/targets/' . (int) ($target['id'] ?? 0),
            $target
        );
    }
}

if ($type === 'all' || $type === 'activities') {
    $activityRows = $activitiesModule->getRecent(max($limit * 4, 30), null, 0);
    foreach ($activityRows as $activityRow) {
        $haystack = strtolower(implode(' ', [
            (string) ($activityRow['activity_type'] ?? ''),
            (string) ($activityRow['description'] ?? ''),
            (string) ($activityRow['first_name'] ?? ''),
            (string) ($activityRow['last_name'] ?? ''),
            (string) ($activityRow['contact_email'] ?? ''),
        ]));
        if (!str_contains($haystack, strtolower($query))) {
            continue;
        }
        $activity = mobileActivitySummary($activityRow);
        $activities[] = $activity;
        $items[] = mobileSearchItem(
            'activity',
            (int) ($activity['id'] ?? 0),
            (string) ($activity['activity_label'] ?? 'Activity'),
            trim((string) (($activity['contact_name'] ?? '') !== '' ? $activity['contact_name'] : ($activity['created_at'] ?? ''))),
            '/activities',
            $activity
        );
        if (count($activities) >= $limit) {
            break;
        }
    }
}

if ($type === 'all' || $type === 'forms') {
    foreach ($formsModule->list() as $formRow) {
        if (!str_contains(strtolower((string) ($formRow['name'] ?? '')), strtolower($query))) {
            continue;
        }
        $form = mobileFormSummary($formRow);
        $forms[] = $form;
        $items[] = mobileSearchItem(
            'form',
            (int) ($form['id'] ?? 0),
            (string) ($form['name'] ?? ''),
            (int) ($form['submission_count'] ?? 0) . ' submissions',
            (string) ($form['submissions_route'] ?? '/forms'),
            $form
        );
        if (count($forms) >= $limit) {
            break;
        }
    }
}

if (($type === 'all' || $type === 'bookings')
    && mobileCanAny($user, ['meeting_bookings.view', 'meeting_bookings.manage'])) {
    try {
        $bookingRows = (new MeetingBookingService())->listBookings(mobileWorkspaceId($auth), [], max($limit * 3, 25));
        foreach ($bookingRows as $bookingRow) {
            $haystack = strtolower(implode(' ', [
                (string) ($bookingRow['requester_name'] ?? ''),
                (string) ($bookingRow['requester_email'] ?? ''),
                (string) ($bookingRow['inquiry_type'] ?? ''),
                (string) ($bookingRow['deal_title'] ?? ''),
                (string) ($bookingRow['contact_first_name'] ?? ''),
                (string) ($bookingRow['contact_last_name'] ?? ''),
            ]));
            if (!str_contains($haystack, strtolower($query))) {
                continue;
            }
            $booking = mobileMeetingBookingSummary($bookingRow);
            $bookings[] = $booking;
            $items[] = mobileSearchItem(
                'booking',
                (int) ($booking['id'] ?? 0),
                (string) (($booking['requester_name'] ?? '') !== '' ? $booking['requester_name'] : 'Meeting booking'),
                trim((string) (($booking['scheduled_start'] ?? '') !== '' ? $booking['scheduled_start'] : ($booking['status'] ?? ''))),
                '/bookings',
                $booking
            );
            if (count($bookings) >= $limit) {
                break;
            }
        }
    } catch (Throwable $e) {
        $bookings = [];
    }
}

if ($type === 'all' || $type === 'invoices') {
    $invoiceFilters = ['search' => $query];
    if (!Authorization::can('contacts.view_all', $user)) {
        $invoiceFilters['assigned_to'] = $userId;
    }
    $invoices = array_map('mobileInvoiceSummary', $invoicesModule->list($invoiceFilters, $limit, 0));
    foreach ($invoices as $invoice) {
        $items[] = mobileSearchItem(
            'invoice',
            (int) ($invoice['id'] ?? 0),
            (string) ($invoice['invoice_number'] ?? $invoice['title'] ?? ''),
            trim((string) (($invoice['company_name'] ?? '') !== '' ? $invoice['company_name'] : ($invoice['status'] ?? ''))),
            '/invoices/' . (int) ($invoice['id'] ?? 0),
            $invoice
        );
    }
}

if ($type === 'all' || $type === 'conversations') {
    $conversationRows = $inboxModule->getThreadSummaries($limit, 0, [
        'viewer_user_id' => $userId,
        'can_view_all_conversations' => Authorization::can('conversations.view_all', $user),
        'owner_scope' => $conversationOwnerScope,
        'search' => $query,
    ]);
    $conversations = array_map(
        static fn(array $row): array => mobileAiDecorateConversationItem($row, $userId),
        $conversationRows
    );
    foreach ($conversations as $conversation) {
        $items[] = mobileSearchItem(
            'conversation',
            (int) ($conversation['id'] ?? 0),
            (string) (($conversation['contact_name'] ?? '') !== '' ? $conversation['contact_name'] : ($conversation['subject'] ?? 'Conversation')),
            trim((string) ($conversation['body_preview'] ?? '')),
            '/inbox/' . (int) ($conversation['id'] ?? 0),
            $conversation
        );
    }
}

mobileJson([
    'success' => true,
    'data' => [
        'query' => $query,
        'items' => $items,
        'contacts' => $contacts,
        'deals' => $deals,
        'companies' => $companies,
        'tasks' => $tasks,
        'events' => $events,
        'invoices' => $invoices,
        'conversations' => $conversations,
        'targets' => $targets,
        'activities' => $activities,
        'forms' => $forms,
        'bookings' => $bookings,
        'conversation_owner_scope' => $conversationOwnerScope,
        'generated_at' => gmdate('c'),
    ],
]);
