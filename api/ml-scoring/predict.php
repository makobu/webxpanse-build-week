<?php
/**
 * ML Prediction API
 * Get prediction for a contact
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
use CRM\Services\MLPredictionService;
use CRM\Services\AnalyticsWorkspaceService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $analyticsWorkspace = new AnalyticsWorkspaceService();
    $workspaceId = $analyticsWorkspace->requireAnalyticsWorkspaceId();
    
    if ($method === 'GET') {
        $contactId = (int) ($_GET['contact_id'] ?? 0);
        $modelType = $_GET['model_type'] ?? 'conversion';
        
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
        
        $predictionService = new MLPredictionService();
        $prediction = $predictionService->predictConversion($contactId, $modelType);
        
        echo json_encode([
            'success' => true,
            'data' => $prediction
        ]);
        
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $contactIds = $data['contact_ids'] ?? [];
        $modelType = $data['model_type'] ?? 'conversion';
        
        if (empty($contactIds)) {
            throw new \Exception('contact_ids array is required');
        }

        $contactIds = array_values(array_unique(array_map('intval', $contactIds)));
        $validCount = 0;
        foreach ($contactIds as $contactId) {
            if ($contactId <= 0) {
                continue;
            }

            $contact = Database::queryOne(
                "SELECT id FROM contacts WHERE workspace_id = ? AND id = ? LIMIT 1",
                [$workspaceId, $contactId]
            );
            if (!$contact) {
                throw new \Exception('One or more contacts were not found');
            }
            $validCount++;
        }

        if ($validCount === 0) {
            throw new \Exception('contact_ids array is required');
        }
        
        $predictionService = new MLPredictionService();
        $predictions = $predictionService->predictBatch($contactIds, $modelType);
        
        echo json_encode([
            'success' => true,
            'data' => $predictions
        ]);
        
    } else {
        throw new \Exception('Method not allowed');
    }
    
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
