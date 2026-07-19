<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';
require_once __DIR__ . '/_feature_helpers.php';

use CRM\Database;
use CRM\Modules\Forms;

function mobileFormLatestSubmissionAt(int $workspaceId, array $form): string
{
    if (!Database::tableExists('form_submissions')) {
        return '';
    }

    $where = ['(fs.form_id = ?'];
    $params = [(string) ($form['uuid'] ?? '')];
    if ((int) ($form['id'] ?? 0) > 0 && Database::columnExists('form_submissions', 'form_definition_id')) {
        $where[0] .= ' OR fs.form_definition_id = ?';
        $params[] = (int) $form['id'];
    }
    $where[0] .= ')';
    if (Database::columnExists('form_submissions', 'workspace_id')) {
        $where[] = 'fs.workspace_id = ?';
        $params[] = $workspaceId;
    }

    $dateColumn = Database::columnExists('form_submissions', 'submitted_at') ? 'submitted_at' : 'created_at';
    $row = Database::queryOne(
        "SELECT MAX(fs.{$dateColumn}) AS latest_submission_at
         FROM form_submissions fs
         WHERE " . implode(' AND ', $where),
        $params
    );

    return (string) ($row['latest_submission_at'] ?? '');
}

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$workspaceId = mobileWorkspaceId($auth);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

mobileRequireAnyPermission($user, ['marketing.read'], 'Forms are not available to this user.');
mobileRequireCommunicationRuntime($workspaceId, $user);

if ($method !== 'GET') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

try {
    $forms = new Forms();
    $items = [];
    foreach ($forms->list() as $form) {
        $form['latest_submission_at'] = mobileFormLatestSubmissionAt($workspaceId, $form);
        $items[] = mobileFormSummary($form);
    }

    mobileJson([
        'success' => true,
        'data' => [
            'items' => $items,
            'generated_at' => gmdate('c'),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile forms list failed: ' . $e->getMessage());
    mobileJson([
        'error' => 'Forms could not be loaded.',
        'error_code' => 'forms_mobile_failed',
    ], 422);
}
