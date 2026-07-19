<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_serializers.php';
require_once __DIR__ . '/../_feature_helpers.php';
require_once __DIR__ . '/../_conversation_resolver.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Forms;
use CRM\Modules\Tasks;

function mobileFindFormSubmission(int $workspaceId, int $submissionId): ?array
{
    if ($submissionId <= 0 || !Database::tableExists('form_submissions')) {
        return null;
    }

    $where = ['fs.id = ?'];
    $params = [$submissionId];
    if (Database::columnExists('form_submissions', 'workspace_id')) {
        $where[] = 'fs.workspace_id = ?';
        $params[] = $workspaceId;
    }

    return Database::queryOne(
        "SELECT fs.*, c.first_name, c.last_name, c.email
         FROM form_submissions fs
         LEFT JOIN contacts c ON c.id = fs.contact_id" . (Database::columnExists('form_submissions', 'workspace_id') ? ' AND c.workspace_id = fs.workspace_id' : '') . "
         WHERE " . implode(' AND ', $where) . "
         LIMIT 1",
        $params
    ) ?: null;
}

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$workspaceId = mobileWorkspaceId($auth);
$userId = (int) ($auth['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$forms = new Forms();

mobileRequireCommunicationRuntime($workspaceId, $user);

try {
    if ($method === 'POST') {
        mobileRequireAnyPermission($user, ['tasks.write'], 'You do not have permission to create follow-up tasks.');
        $input = mobileRequestBody();
        $action = strtolower(trim((string) ($input['action'] ?? 'create_follow_up_task')));
        if ($action === 'apply_ai_tags') {
            mobileJson(['error' => 'AI tag application is not available from mobile yet.'], 422);
        }
        if ($action !== 'create_follow_up_task') {
            mobileJson(['error' => 'Unsupported action.'], 422);
        }

        $submission = mobileFindFormSubmission($workspaceId, (int) ($input['submission_id'] ?? $input['id'] ?? 0));
        if (!$submission) {
            mobileJson(['error' => 'Submission not found.'], 404);
        }
        $contactId = !empty($submission['contact_id']) && mobileCanAccessContact($submission, $userId, $user)
            ? (int) $submission['contact_id']
            : null;
        $title = trim((string) ($input['title'] ?? 'Follow up on form submission'));
        $description = trim((string) ($input['description'] ?? 'Review the mobile form submission and follow up.'));
        if (!empty($submission['form_id'])) {
            $description .= "\n\nForm: " . (string) $submission['form_id'];
        }

        $taskId = (new Tasks())->create([
            'title' => $title,
            'description' => $description,
            'contact_id' => $contactId,
            'assigned_to' => !empty($input['assigned_to']) ? (int) $input['assigned_to'] : $userId,
            'created_by' => $userId,
            'actor_user_id' => $userId,
            'status' => 'pending',
            'priority' => strtolower(trim((string) ($input['priority'] ?? 'medium'))) ?: 'medium',
            'due_date' => trim((string) ($input['due_date'] ?? '')) ?: null,
            'metadata_json' => [
                'source_surface' => 'mobile_forms',
                'form_submission_id' => (int) ($submission['id'] ?? 0),
                'form_id' => (string) ($submission['form_id'] ?? ''),
            ],
        ]);

        mobileJson([
            'success' => true,
            'data' => [
                'task_id' => $taskId,
                'route' => '/tasks/' . $taskId,
            ],
        ]);
    }

    if ($method !== 'GET') {
        mobileJson(['error' => 'Method not allowed.'], 405);
    }

    $formId = (int) ($_GET['form_id'] ?? $_GET['id'] ?? 0);
    $formUuid = trim((string) ($_GET['form_uuid'] ?? $_GET['uuid'] ?? ''));
    $limit = mobileBoundedLimit($_GET['limit'] ?? null, 100, 150);
    if ($formId <= 0 && $formUuid === '') {
        mobileJson(['error' => 'form_id or form_uuid is required.'], 422);
    }

    $form = $formId > 0 ? $forms->getById($formId) : $forms->getByUuid($formUuid);
    if (!$form) {
        mobileJson(['error' => 'Form not found.'], 404);
    }
    $rows = $formId > 0
        ? $forms->getSubmissions((int) $form['id'], $limit)
        : $forms->getSubmissionsByUuid((string) $form['uuid'], $limit);

    $filter = strtolower(trim((string) ($_GET['filter'] ?? '')));
    if ($filter === 'linked_contact') {
        $rows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['contact_id'])));
    } elseif ($filter === 'needs_follow_up') {
        $rows = array_values(array_filter($rows, static fn(array $row): bool => empty($row['contact_id'])));
    } elseif ($filter === 'new') {
        $rows = array_slice($rows, 0, 25);
    }

    foreach ($rows as &$row) {
        if (!empty($row['contact_id']) && !mobileCanAccessContact($row, $userId, $user)) {
            $row['contact_id'] = null;
            $row['first_name'] = '';
            $row['last_name'] = '';
            $row['email'] = '';
            $row['contact_email'] = '';
        }
    }
    unset($row);

    mobileJson([
        'success' => true,
        'data' => [
            'form' => mobileFormSummary($form),
            'items' => array_map('mobileFormSubmissionSummary', $rows),
            'generated_at' => gmdate('c'),
        ],
    ]);
} catch (Throwable $e) {
    mobileJson([
        'error' => $e->getMessage(),
        'error_code' => 'form_submission_mobile_failed',
    ], str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422);
}
