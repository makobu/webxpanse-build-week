<?php
/**
 * Apply Weights API Endpoint
 * Applies recommended or custom weights to a contact
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

use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Modules\AILeadScoring;
use CRM\Services\AnalyticsWorkspaceService;

Session::start();
header('Content-Type: application/json');

// Check authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

try {
    $dbConfig = require __DIR__ . '/../../config/database.php';
    Database::init($dbConfig);
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $contactId = (int) ($input['contact_id'] ?? 0);
    $useRecommended = $input['use_recommended'] ?? false;
    $customWeights = $input['weights'] ?? null;
    
    if (!$contactId) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'contact_id is required']);
        exit;
    }
    
    $workspaceId = (new AnalyticsWorkspaceService())->requireAnalyticsWorkspaceId();
    $contact = Database::queryOne(
        "SELECT id
         FROM contacts
         WHERE workspace_id = ? AND id = ?
         LIMIT 1",
        [$workspaceId, $contactId]
    );

    if (!$contact) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Contact not found']);
        exit;
    }

    $scoringService = new AILeadScoring();
    
    if ($useRecommended) {
        $weights = $scoringService->getRecommendedWeights($contactId);
    } elseif (array_key_exists('weights', $input ?? [])) {
        try {
            $weights = AILeadScoring::validateWeights($customWeights);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    } else {
        // Get default weights from contact or global config
        $weights = $scoringService->getWeights($contactId);
    }

    try {
        $weights = AILeadScoring::validateWeights($weights);
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }

    $breakdown = $scoringService->recalculateScore($contactId, 'conversion', $weights, 'api_apply_weights');
    $consolidatedScore = (int) ($breakdown['consolidated_score'] ?? 0);
    
    echo json_encode([
        'status' => 'success',
        'success' => true,
        'contact_id' => $contactId,
        'weights' => $weights,
        'consolidated_score' => $consolidatedScore,
        'breakdown' => $breakdown
    ], JSON_PRETTY_PRINT);
    
} catch (\Throwable $e) {
    error_log("Apply Weights API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
