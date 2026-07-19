<?php
/**
 * Deal Automation Audit API
 * Returns recent automation evaluations for explainability
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$dealId = (int) ($_GET['deal_id'] ?? 0);
$limit = min(20, max(1, (int) ($_GET['limit'] ?? 10)));
if (!$dealId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'deal_id required']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$workspaceFilter = '';
$params = [$dealId];
if ($workspaceId > 0) {
    if (Database::columnExists('deal_automation_audit', 'workspace_id')) {
        $workspaceFilter = ' AND workspace_id = ?';
        $params[] = $workspaceId;
    } else {
        $workspaceFilter = ' AND EXISTS (SELECT 1 FROM deals d WHERE d.id = deal_automation_audit.deal_id AND d.workspace_id = ?)';
        $params[] = $workspaceId;
    }
}
$params[] = $limit;

$rows = Database::query(
    "SELECT id, trigger_type, from_stage, to_stage, decision, confidence, applied, reason, evidence_summary, checklist_results, created_at
     FROM deal_automation_audit
     WHERE deal_id = ?{$workspaceFilter}
     ORDER BY created_at DESC
     LIMIT ?",
    $params
);

echo json_encode(['success' => true, 'audit' => $rows]);
