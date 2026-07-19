<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
Authorization::requirePermission('settings.email_assistant', true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
if (!Security::validateCSRF((string) $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}
$runId = (int) ($input['run_id'] ?? 0);
$entityType = trim((string) ($input['entity_type'] ?? ''));
$resolvedId = (int) ($input['resolved_id'] ?? 0);

if ($runId <= 0 || $entityType === '' || $resolvedId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'run_id, entity_type, and resolved_id are required']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'An active workspace is required']);
    exit;
}

$assistantTypeWhere = Database::columnExists('email_assistant_runs', 'assistant_type') ? " AND assistant_type = 'email'" : '';
$run = Database::queryOne(
    "SELECT * FROM email_assistant_runs WHERE id = ? AND workspace_id = ?{$assistantTypeWhere}",
    [$runId, $workspaceId]
);
if (!$run) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Assistant run not found']);
    exit;
}

$normalizedEntityType = match (strtolower($entityType)) {
    'contact', 'contacts' => 'contact',
    'deal', 'deals' => 'deal',
    'invoice', 'invoices', 'quote', 'quotes', 'proforma', 'proformas' => 'invoice',
    'approval', 'approvals', 'commercial_automation_approval', 'commercial_automation_approvals' => 'approval',
    default => '',
};

if ($normalizedEntityType === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Unsupported entity_type']);
    exit;
}

try {
    $scope = new WorkspaceScopeService();
    $table = match ($normalizedEntityType) {
        'contact' => 'contacts',
        'deal' => 'deals',
        'invoice' => 'invoices',
        'approval' => 'commercial_automation_approvals',
    };
    $scope->assertSameWorkspace($table, $resolvedId, $workspaceId);
} catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

Database::execute(
    "UPDATE email_assistant_resolutions SET resolved_id = ?, resolution_method = 'exact_id', confidence_score = 1.0 WHERE workspace_id = ? AND run_id = ? AND entity_type = ?",
    [$resolvedId, $workspaceId, $runId, $normalizedEntityType]
);
Database::execute(
    "UPDATE email_assistant_runs SET resolution_status = 'resolved' WHERE id = ? AND workspace_id = ?{$assistantTypeWhere}",
    [$runId, $workspaceId]
);

echo json_encode(['success' => true, 'run_id' => $runId, 'entity_type' => $normalizedEntityType, 'resolved_id' => $resolvedId]);
