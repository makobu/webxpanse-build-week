<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';
require_once __DIR__ . '/_feature_helpers.php';
require_once __DIR__ . '/_conversation_resolver.php';

use CRM\Modules\Activities;
use CRM\Modules\Contacts;
use CRM\Authorization;

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$userId = (int) ($auth['user_id'] ?? 0);
$activities = new Activities();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$canViewAllContacts = Authorization::can('contacts.view_all', $user);

try {
    if ($method === 'POST') {
        $input = mobileRequestBody();
        $action = strtolower(trim((string) ($input['action'] ?? 'log')));
        if ($action !== 'log') {
            mobileJson(['error' => 'Unsupported action.'], 422);
        }
        $contactId = (int) ($input['contact_id'] ?? 0);
        $activityType = (string) ($input['activity_type'] ?? $input['type'] ?? '');
        $description = trim((string) ($input['description'] ?? ''));
        if ($contactId <= 0) {
            mobileJson(['error' => 'contact_id is required.'], 422);
        }
        $contact = (new Contacts())->getById($contactId);
        if (!$contact || !mobileCanAccessContact($contact, $userId, $user)) {
            mobileJson(['error' => 'Contact not found or not accessible.'], 404);
        }
        if (!Activities::isManualType($activityType)) {
            mobileJson(['error' => 'Choose a valid manual activity type.'], 422);
        }

        $activityId = $activities->log($contactId, $activityType, $description, [
            'source' => 'mobile',
        ], $userId);
        mobileJson([
            'success' => true,
            'data' => [
                'activity' => mobileActivitySummary($activities->getById($activityId, $userId, $canViewAllContacts) ?? ['id' => $activityId]),
                'result_route' => '/contacts/' . $contactId,
            ],
        ]);
    }

    if ($method !== 'GET') {
        mobileJson(['error' => 'Method not allowed.'], 405);
    }

    $limit = mobileBoundedLimit($_GET['limit'] ?? null, 30, 100);
    $offset = mobileBoundedOffset($_GET['offset'] ?? 0);
    $contactId = (int) ($_GET['contact_id'] ?? 0);
    $type = trim((string) ($_GET['type'] ?? ''));

    if (!empty($_GET['id'])) {
        $activity = $activities->getById((int) $_GET['id'], $userId, $canViewAllContacts);
        if (!$activity) {
            mobileJson(['error' => 'Activity not found.'], 404);
        }
        mobileJson([
            'success' => true,
            'data' => [
                'activity' => mobileActivitySummary($activity),
            ],
        ]);
    }

    if ($contactId > 0) {
        $contact = (new Contacts())->getById($contactId);
        if (!$contact || !mobileCanAccessContact($contact, $userId, $user)) {
            mobileJson(['error' => 'Contact not found or not accessible.'], 404);
        }
        $rows = $activities->getByContact($contactId, $limit, $offset);
    } elseif ($type !== '') {
        $rows = $activities->getByType($type, $limit, $offset, $userId, $canViewAllContacts);
    } else {
        $rows = $activities->getRecent($limit, null, $offset, $userId, $canViewAllContacts);
    }

    mobileJson([
        'success' => true,
        'data' => [
            'items' => array_map('mobileActivitySummary', $rows),
            'type_counts' => $activities->getTypeCounts($userId, $canViewAllContacts),
            'manual_types' => Activities::manualTypeOptions(),
            'limit' => $limit,
            'offset' => $offset,
            'generated_at' => gmdate('c'),
        ],
    ]);
} catch (Throwable $e) {
    mobileJson([
        'error' => $e->getMessage(),
        'error_code' => 'activity_mobile_failed',
    ], str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422);
}
