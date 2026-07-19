<?php
/**
 * Workflow Analytics API
 * Returns workflow performance analytics
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
use CRM\Modules\WorkflowAnalytics;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $workflowId = isset($_GET['workflow_id']) ? (int)$_GET['workflow_id'] : null;
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    
    $analytics = new WorkflowAnalytics();
    
    if ($workflowId) {
        $result = $analytics->getWorkflowAnalytics($workflowId, $startDate, $endDate);
    } else {
        $result = $analytics->getAllWorkflowsAnalytics($startDate, $endDate);
    }
    
    echo json_encode([
        'success' => true,
        'analytics' => $result
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
