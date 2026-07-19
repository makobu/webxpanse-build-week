<?php
/**
 * Import Workflow API
 * Imports workflow from JSON
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
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\AutomationEngine;
use CRM\Services\WorkflowGraphService;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
Authorization::requirePermission('workflows.manage', true);


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $automationEngine = new AutomationEngine();
    $graphService = new WorkflowGraphService();
    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    $graph = $input['graph_json'] ?? null;
    if ($graph) {
        $graph = is_string($graph) ? json_decode($graph, true) : $graph;
        $legacyPayload = $graphService->deriveLegacyPayload($graph);
        $input['trigger_config'] = $legacyPayload['trigger'];
        $input['conditions'] = $legacyPayload['conditions'];
        $input['actions'] = $legacyPayload['actions'];
        $input['visual_data'] = $legacyPayload['visual_data'];
        $input['branches'] = $legacyPayload['branches'];
    }

    if (!$input || empty($input['name']) || empty($input['trigger_config']) || empty($input['actions'])) {
        throw new \Exception('Invalid workflow data');
    }
    
    // Create workflow
    $workflowId = $automationEngine->createWorkflow(
        $input['name'],
        $input['trigger_config'],
        $input['conditions'] ?? [],
        $input['actions']
    );
    
    $graph = $graph ?: $graphService->migrateLegacyWorkflow([
        'id' => $workflowId,
        'name' => $input['name'],
        'workflow_mode' => $input['workflow_mode'] ?? 'mixed',
        'trigger_config' => $input['trigger_config'],
        'conditions' => $input['conditions'] ?? [],
        'actions' => $input['actions'],
        'visual_data' => $input['visual_data'] ?? null,
    ]);
    $validation = $graphService->validateGraph($graph);
    Database::execute(
        "UPDATE workflows SET visual_data = ?, graph_json = ?, builder_version = 2, workflow_mode = ?,
         migration_source = ?, migration_status = ?, last_saved_at = NOW(), last_migrated_at = NOW(), last_validated_at = NOW()
         WHERE workspace_id = ? AND id = ?",
        [
            json_encode($input['visual_data'] ?? null),
            json_encode($graph),
            $input['workflow_mode'] ?? 'mixed',
            !empty($input['graph_json']) ? 'graph_v2' : (!empty($input['visual_data']) ? 'visual_v1' : 'legacy_form'),
            $validation['valid'] ? 'migrated' : 'failed',
            $workspaceId,
            $workflowId
        ]
    );
    
    // Save branches if provided
    if (!empty($input['branches'])) {
        foreach ($input['branches'] as $branch) {
            Database::execute(
                "INSERT INTO workflow_branches (workflow_id, source_node_id, target_node_id, branch_type, condition_value, branch_order) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $workflowId,
                    $branch['source_node_id'] ?? $branch['from'] ?? '',
                    $branch['target_node_id'] ?? $branch['to'] ?? '',
                    $branch['branch_type'] ?? $branch['type'] ?? 'success',
                    $branch['condition_value'] ?? null,
                    $branch['branch_order'] ?? $branch['order'] ?? 0
                ]
            );
        }
    }
    
    echo json_encode([
        'success' => true,
        'workflow_id' => $workflowId,
        'message' => 'Workflow imported successfully'
    ]);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
