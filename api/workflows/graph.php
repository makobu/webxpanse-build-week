<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
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

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Services\WorkflowGraphService;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $workflowId = (int) ($_GET['id'] ?? 0);
    if ($workflowId <= 0) {
        throw new \Exception('Workflow ID is required');
    }

    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    $workflow = Database::queryOne("SELECT * FROM workflows WHERE workspace_id = ? AND id = ?", [$workspaceId, $workflowId]);
    if (!$workflow) {
        throw new \Exception('Workflow not found');
    }

    $service = new WorkflowGraphService();
    $graph = $service->loadGraphFromWorkflowRow($workflow);
    echo json_encode([
        'success' => true,
        'graph' => $graph,
        'validation' => $service->validateGraph($graph),
    ]);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
