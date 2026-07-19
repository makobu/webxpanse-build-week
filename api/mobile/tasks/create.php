<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_serializers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Tasks;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

if (!Authorization::can('tasks.write', $user)) {
    mobileJson(['error' => 'You do not have permission to create tasks.'], 403);
}

$input = mobileRequestBody();
$title = trim((string) ($input['title'] ?? ''));
if ($title === '') {
    mobileJson(['error' => 'Task title is required.'], 422);
}

$priority = trim((string) ($input['priority'] ?? 'medium'));
$allowedPriorities = ['low', 'medium', 'high', 'urgent'];
if (!in_array($priority, $allowedPriorities, true)) {
    $priority = 'medium';
}

$sourceSurface = trim((string) ($input['source_surface'] ?? 'mobile_inbox'));
$allowedSourceSurfaces = [
    'mobile_inbox',
    'mobile_quick_capture',
    'mobile_contact',
    'mobile_form_submission',
];
if (!in_array($sourceSurface, $allowedSourceSurfaces, true)) {
    $sourceSurface = 'mobile_inbox';
}

$metadata = [
    'source_surface' => $sourceSurface,
];
if (!empty($input['conversation_id'])) {
    $metadata['conversation_id'] = (int) $input['conversation_id'];
}
if (!empty($input['deal_id'])) {
    $metadata['deal_id'] = (int) $input['deal_id'];
}

$tasks = new Tasks();

try {
    $taskId = $tasks->create([
        'title' => $title,
        'description' => (string) ($input['description'] ?? ''),
        'contact_id' => !empty($input['contact_id']) ? (int) $input['contact_id'] : null,
        'assigned_to' => $userId,
        'created_by' => $userId,
        'actor_user_id' => $userId,
        'priority' => $priority,
        'due_date' => !empty($input['due_date']) ? (string) $input['due_date'] : null,
        'metadata_json' => $metadata,
    ]);
} catch (Throwable $e) {
    error_log('Mobile task create failed for user_id=' . $userId . ': ' . $e->getMessage());
    mobileJson(['error' => trim((string) $e->getMessage()) ?: 'Could not create task.'], 422);
}

mobileJson([
    'success' => true,
    'message' => 'Follow-up task created.',
    'data' => array_merge(
        mobileTaskSummary($tasks->getById($taskId) ?? []),
        ['result_route' => '/tasks/' . $taskId]
    ),
]);
