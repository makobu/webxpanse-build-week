<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Documents;
use CRM\Modules\Events;

$auth = mobileRequireAuth();
$userId = (int) ($auth['user_id'] ?? 0);
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$events = new Events();
$documents = new Documents();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Authorization::can('events.write', $user) && !Authorization::can('events.read', $user)) {
        mobileJson(['error' => 'You do not have permission to manage events.'], 403);
    }

    $input = mobileRequestBody();
    $id = (int) ($input['id'] ?? 0);

    $payload = [
        'title' => (string) ($input['title'] ?? ''),
        'description' => (string) ($input['description'] ?? ''),
        'event_type' => (string) ($input['event_type'] ?? 'meeting'),
        'contact_id' => !empty($input['contact_id']) ? (int) $input['contact_id'] : null,
        'assigned_to' => $events->resolveAssignedToForUser($input['assigned_to'] ?? null, $user),
        'start_time' => (string) ($input['start_time'] ?? ''),
        'end_time' => !empty($input['end_time']) ? (string) $input['end_time'] : null,
        'location' => (string) ($input['location'] ?? ''),
        'is_all_day' => !empty($input['is_all_day']) ? 1 : 0,
        'reminder_minutes' => isset($input['reminder_minutes']) && $input['reminder_minutes'] !== ''
            ? (int) $input['reminder_minutes']
            : null,
        'status' => (string) ($input['status'] ?? 'scheduled'),
        'created_by' => $userId,
        'expected_lock_version' => $input['expected_lock_version'] ?? null,
    ];

    try {
        if ($id > 0) {
            $event = $events->getByIdForUser($id, $user);
            if (!$event) {
                mobileJson(['error' => 'Event not found or not accessible.'], 404);
            }
            $events->update($id, $payload);
            $fresh = $events->getByIdForUser($id, $user) ?? [];
            mobileJson(['success' => true, 'data' => mobileEventSummary($fresh)]);
        }

        $createdId = $events->create($payload);
        mobileJson([
            'success' => true,
            'data' => array_merge(
                mobileEventSummary($events->getByIdForUser($createdId, $user) ?? []),
                ['result_route' => '/calendar/' . $createdId]
            ),
        ]);
    } catch (\CRM\ConcurrencyConflictException $e) {
        mobileJson(\CRM\Concurrency::conflictPayload($e), 409);
    } catch (Throwable $e) {
        mobileJson(['error' => trim((string) $e->getMessage()) ?: 'Could not save event.'], 422);
    }
}

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $event = $events->getByIdForUser($id, $user);
    if (!$event) {
        mobileJson(['error' => 'Event not found or not accessible.'], 404);
    }

    mobileJson([
        'success' => true,
        'data' => array_merge(
            mobileEventSummary($event),
            [
                'documents' => $documents->canUserAccessEntity($user, 'event', $id, 'read')
                    ? array_map('mobileDocumentSummary', $documents->getEntityDocuments('event', $id))
                    : [],
                'generated_at' => gmdate('c'),
            ]
        ),
    ]);
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 30)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$filters = [];
foreach (['status', 'event_type', 'search', 'start_date', 'end_date'] as $field) {
    if (!empty($_GET[$field])) {
        $filters[$field] = (string) $_GET[$field];
    }
}
if (!empty($_GET['contact_id'])) {
    $filters['contact_id'] = (int) $_GET['contact_id'];
}
if (array_key_exists('assigned_to', $_GET)) {
    $filters['assigned_to'] = $events->resolveAssignedToForUser($_GET['assigned_to'], $user);
}

$items = $events->getAllForUser($filters, $user, $limit, $offset, 'ASC');

mobileJson([
    'success' => true,
    'data' => [
        'items' => array_map('mobileEventSummary', $items),
        'total' => $events->getCount($events->applyVisibilityScope($filters, $user)),
        'generated_at' => gmdate('c'),
    ],
]);
