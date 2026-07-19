<?php
/**
 * ML Explanation API
 * Get explanation for a contact's score
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Services\MLExplainabilityService;
use CRM\Services\AnalyticsWorkspaceService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $analyticsWorkspace = new AnalyticsWorkspaceService();
    $workspaceId = $analyticsWorkspace->requireAnalyticsWorkspaceId();
    $contactId = (int) ($_GET['contact_id'] ?? 0);
    $modelType = $_GET['model_type'] ?? 'conversion';
    $limit = (int) ($_GET['limit'] ?? 5);
    
    if (!$contactId) {
        throw new \Exception('contact_id is required');
    }

    $contact = Database::queryOne(
        "SELECT id FROM contacts WHERE workspace_id = ? AND id = ? LIMIT 1",
        [$workspaceId, $contactId]
    );
    if (!$contact) {
        throw new \Exception('Contact not found');
    }
    
    $explainabilityService = new MLExplainabilityService();
    
    // Get full explanation
    $explanation = $explainabilityService->explainScore($contactId, $modelType);
    
    // Get top factors separately if requested
    $topFactors = null;
    if (isset($_GET['top_factors_only'])) {
        $topFactors = $explainabilityService->getTopFactors($contactId, $modelType, $limit);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'explanation' => $explanation,
            'top_factors' => $topFactors ?? $explanation['top_factors']
        ]
    ]);
    
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
