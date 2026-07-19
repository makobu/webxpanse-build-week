<?php
/**
 * Test Workflow API
 * Test workflow execution without actually executing
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
use CRM\Modules\WorkflowTester;
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $workflowId = isset($input['workflow_id']) ? (int)$input['workflow_id'] : null;
    $testData = $input['test_data'] ?? [];
    
    if (!$workflowId) {
        throw new \Exception('Workflow ID is required');
    }

    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    
    // If contact_id provided, load contact data
    if (isset($testData['contact_id'])) {
        $contact = Database::queryOne(
            "SELECT * FROM contacts WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $testData['contact_id']]
        );
        if ($contact) {
            $testData = array_merge($contact, $testData);
        }
    }
    
    $tester = new WorkflowTester();
    $result = $tester->testWorkflow($workflowId, $testData);
    
    echo json_encode([
        'success' => true,
        'test_result' => $result
    ]);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
