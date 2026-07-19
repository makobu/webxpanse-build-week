<?php
/**
 * Batch Enrichment API Endpoint
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
use CRM\Services\AIEnrichmentService;

header('Content-Type: application/json');
Auth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $dbConfig = require __DIR__ . '/../../config/database.php';
    Database::init($dbConfig);
    
    $input = json_decode(file_get_contents('php://input'), true);
    $contactIds = $input['contact_ids'] ?? [];
    
    if (empty($contactIds) || !is_array($contactIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'contact_ids array is required']);
        exit;
    }
    
    $options = $input['options'] ?? [
        'use_third_party' => true,  // Keep defaults aligned with single-contact enrich endpoint
        'extract_web' => true,
        'extract_email' => true,
        'extract_social' => true,
        'infer_fields' => true,
        'validate_data' => true,
        'sources' => ['third_party', 'website', 'email', 'linkedin', 'twitter', 'inference']
    ];
    
    $enrichmentService = new AIEnrichmentService();
    $results = [];
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($contactIds as $contactId) {
        try {
            $result = $enrichmentService->enrichContact((int) $contactId, $options);
            $results[] = [
                'contact_id' => $contactId,
                'result' => $result
            ];
            
            if ($result['status'] === 'success') {
                $successCount++;
            } else {
                $errorCount++;
            }
            
            // Small delay to avoid rate limiting
            usleep(500000); // 0.5 seconds
            
        } catch (\Exception $e) {
            $errorCount++;
            $results[] = [
                'contact_id' => $contactId,
                'result' => ['status' => 'error', 'message' => $e->getMessage()]
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'total' => count($contactIds),
        'success_count' => $successCount,
        'error_count' => $errorCount,
        'results' => $results
    ], JSON_PRETTY_PRINT);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
