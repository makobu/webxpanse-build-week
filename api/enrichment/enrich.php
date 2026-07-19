<?php
/**
 * Manual Enrichment API Endpoint
 */

// Suppress HTML error output - we want JSON only
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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
use CRM\Authorization;
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

// Create detailed log file for this enrichment session
$logFile = __DIR__ . '/../../.cursor/enrichment_debug.log';
$sessionId = uniqid('enrich_', true);

function logEnrichmentStep($step, $message, $data = []) {
    global $logFile, $sessionId;
    $logEntry = [
        'session_id' => $sessionId,
        'timestamp' => date('Y-m-d H:i:s'),
        'step' => $step,
        'message' => $message,
        'data' => $data
    ];
    file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND);
}

try {
    logEnrichmentStep('INIT', 'Enrichment API called', ['method' => $_SERVER['REQUEST_METHOD']]);
    
    $dbConfig = require __DIR__ . '/../../config/database.php';
    Database::init($dbConfig);
    logEnrichmentStep('INIT', 'Database initialized');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $contactId = (int) ($input['contact_id'] ?? 0);
    logEnrichmentStep('INPUT', 'Request parsed', ['contact_id' => $contactId, 'has_options' => !empty($input['options'])]);
    
    if (!$contactId) {
        http_response_code(400);
        echo json_encode(['error' => 'contact_id is required']);
        exit;
    }
    
    $options = $input['options'] ?? [
        'use_third_party' => true,
        'extract_web' => false,
        'extract_email' => true,
        'extract_social' => false,
        'discover_linkedin' => false,
        'infer_fields' => true,
        'validate_data' => true,
        'sources' => ['third_party', 'email', 'inference']
    ];
    logEnrichmentStep('OPTIONS', 'Enrichment options set', $options);
    
    logEnrichmentStep('SERVICE', 'Creating AIEnrichmentService');
    $enrichmentService = new AIEnrichmentService();
    logEnrichmentStep('SERVICE', 'AIEnrichmentService created successfully');
    
    logEnrichmentStep('ENRICH', 'Starting enrichContact', ['contact_id' => $contactId]);
    $result = $enrichmentService->enrichContact($contactId, $options);
    logEnrichmentStep('ENRICH', 'enrichContact completed', ['status' => $result['status'] ?? 'unknown', 'has_ai_context' => !empty($result['ai_context'])]);
    
    // Ensure consistent response format
    if (!isset($result['status'])) {
        $result['status'] = isset($result['error']) ? 'error' : 'success';
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (\Throwable $e) {
    // Log the error with full details
    $errorDetails = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    error_log("Enrichment API Error: " . json_encode($errorDetails, JSON_PRETTY_PRINT));
    
    logEnrichmentStep('ERROR', 'Exception caught', [
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'class' => get_class($e)
    ]);
    
    http_response_code(500);
    $canViewDiagnostics = Authorization::can('ai.operations.manage');
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred during enrichment. Please check the logs for details.',
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'trace' => ($canViewDiagnostics && ($_ENV['APP_DEBUG'] ?? 'false') === 'true') ? $e->getTraceAsString() : null,
        'session_id' => $sessionId,
        'log_file' => '.cursor/enrichment_debug.log',
        'debug_command' => $canViewDiagnostics ? 'php scripts/view_enrichment_log.php' : null
    ], JSON_PRETTY_PRINT);
}
