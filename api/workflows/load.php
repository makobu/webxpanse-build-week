<?php
/**
 * Load Workflow API
 * Loads workflow data for visual builder
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
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
use CRM\Modules\AutomationEngine;
use CRM\Services\WorkflowGraphService;
use CRM\Services\WorkflowMigrationService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$workflowId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$workflowId) {
    http_response_code(400);
    echo json_encode(['error' => 'Workflow ID is required']);
    exit;
}

try {
    $automationEngine = new AutomationEngine();
    $workflow = $automationEngine->getWorkflow($workflowId);
    
    if (!$workflow) {
        http_response_code(404);
        echo json_encode(['error' => 'Workflow not found']);
        exit;
    }

    $workflowRow = $workflow;
    
    // Parse workflow data
    $workflow['trigger_config'] = json_decode($workflow['trigger_config'], true) ?? [];
    $workflow['conditions'] = json_decode($workflow['conditions'], true) ?? [];
    $workflow['actions'] = json_decode($workflow['actions'], true) ?? [];
    $workflow['visual_data'] = json_decode($workflow['visual_data'] ?? 'null', true);
    $workflow['graph_json'] = json_decode($workflow['graph_json'] ?? 'null', true);
    
    // Load branches
    $branches = Database::query(
        "SELECT source_node_id, target_node_id, branch_type, condition_value, branch_order 
         FROM workflow_branches 
         WHERE workflow_id = ? 
         ORDER BY branch_order ASC",
        [$workflowId]
    );
    $workflow['branches'] = $branches;
    
    if (empty($workflow['graph_json'])) {
        $migrationService = new WorkflowMigrationService();
        $migrationService->migrateWorkflow($workflowId);
        $workflow = $automationEngine->getWorkflow($workflowId);
        $workflowRow = $workflow;
        $workflow['trigger_config'] = json_decode($workflow['trigger_config'], true) ?? [];
        $workflow['conditions'] = json_decode($workflow['conditions'], true) ?? [];
        $workflow['actions'] = json_decode($workflow['actions'], true) ?? [];
        $workflow['visual_data'] = json_decode($workflow['visual_data'] ?? 'null', true);
        $workflow['graph_json'] = json_decode($workflow['graph_json'] ?? 'null', true);
        $workflow['branches'] = $branches;
    }

    $graphService = new WorkflowGraphService();
    echo json_encode([
        'success' => true,
        'workflow' => $workflow,
        'graph' => $graphService->loadGraphFromWorkflowRow($workflowRow),
        'lock_version' => (int) ($workflowRow['lock_version'] ?? 0),
        'last_saved_at' => $workflowRow['last_saved_at'] ?? ($workflowRow['updated_at'] ?? null),
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
