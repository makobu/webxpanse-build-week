<?php
/**
 * Email Verification API Endpoint
 * Verifies an email address using Hunter.io API
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
use CRM\Services\ThirdPartyEnrichmentService;
use CRM\Services\WorkspaceScopeService;

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
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

try {
    $dbConfig = require __DIR__ . '/../../config/database.php';
    Database::init($dbConfig);
    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $contactId = (int) ($input['contact_id'] ?? 0);
    
    // Validate email format
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Valid email address is required'
        ]);
        exit;
    }
    
    // Check if HUNTER_API_KEY is configured
    $apiKey = $_ENV['HUNTER_API_KEY'] ?? null;
    if (!$apiKey) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Hunter.io API key not configured. Please add HUNTER_API_KEY to your .env file.'
        ]);
        exit;
    }
    
    // Verify email using Hunter.io
    $thirdPartyService = new ThirdPartyEnrichmentService();
    $verificationResult = $thirdPartyService->verifyEmail($email);
    
    if ($verificationResult['status'] === 'error') {
        http_response_code(400);
        echo json_encode($verificationResult);
        exit;
    }
    
    // If contact_id is provided, update the contact record
    if ($contactId > 0) {
        $contact = Database::queryOne(
            "SELECT id FROM contacts WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$workspaceId, $contactId]
        );
        if (!$contact) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Contact not found']);
            exit;
        }

        $updates = [];
        $params = [];
        
        if (isset($verificationResult['email_verified'])) {
            $updates[] = "email_verified = ?";
            $params[] = $verificationResult['email_verified'] ? 1 : 0;
        }
        
        if (isset($verificationResult['email_verification_status'])) {
            $updates[] = "email_verification_status = ?";
            $params[] = $verificationResult['email_verification_status'];
        }
        
        if (!empty($updates)) {
            $params[] = $workspaceId;
            $params[] = $contactId;
            Database::execute(
                "UPDATE contacts SET " . implode(', ', $updates) . " WHERE workspace_id = ? AND id = ?",
                $params
            );
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'email' => $email,
        'email_verified' => $verificationResult['email_verified'] ?? false,
        'email_verification_status' => $verificationResult['email_verification_status'] ?? null,
        'confidence_score' => $verificationResult['confidence_score'] ?? null,
        'sources' => $verificationResult['sources'] ?? [],
        'contact_updated' => $contactId > 0,
        'message' => 'Email verification completed successfully'
    ], JSON_PRETTY_PRINT);
    
} catch (\Throwable $e) {
    error_log("Email Verification API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred during email verification.',
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
