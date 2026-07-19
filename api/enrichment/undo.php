<?php
/**
 * Undo Enrichment API Endpoint
 * Reverts the most recent enrichment for a contact
 */

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
        $key = trim($key);
        $value = trim($value);
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || 
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Services\AIEnrichmentService;

// Start session for authentication
Session::start();

header('Content-Type: application/json');

// Check authentication - return JSON error if not authenticated
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Authentication required',
        'error' => 'Please log in to use this feature'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $dbConfig = require __DIR__ . '/../../config/database.php';
    Database::init($dbConfig);
    
    $input = json_decode(file_get_contents('php://input'), true);
    $contactId = (int) ($input['contact_id'] ?? 0);
    
    if (!$contactId) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'contact_id is required']);
        exit;
    }
    
    $enrichmentService = new AIEnrichmentService();
    $result = $enrichmentService->undoEnrichment($contactId);
    
    // Ensure consistent response format
    if (!isset($result['status'])) {
        $result['status'] = isset($result['error']) ? 'error' : 'success';
    }
    
    if ($result['status'] === 'error') {
        http_response_code(400);
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (\Throwable $e) {
    error_log("Undo Enrichment API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred while undoing enrichment.',
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
