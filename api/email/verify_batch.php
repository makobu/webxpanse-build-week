<?php
/**
 * Batch Email Verification API
 * Verifies email addresses for multiple contacts using Hunter.io
 */

require_once __DIR__ . '/../../vendor/autoload.php';

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
use CRM\Services\ThirdPartyEnrichmentService;
use CRM\Services\WorkspaceScopeService;

header('Content-Type: application/json');
Auth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$apiKey = $_ENV['HUNTER_API_KEY'] ?? null;
if (!$apiKey) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Hunter.io API key not configured. Add HUNTER_API_KEY to .env'
    ]);
    exit;
}

try {
    Database::init(require __DIR__ . '/../../config/database.php');
    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $contactIds = $input['contact_ids'] ?? [];
    
    if (empty($contactIds) || !is_array($contactIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'contact_ids array is required']);
        exit;
    }
    
    $thirdPartyService = new ThirdPartyEnrichmentService();
    $results = [];
    $verifiedCount = 0;
    $invalidCount = 0;
    $errorCount = 0;
    
    foreach ($contactIds as $contactId) {
        $contactId = (int) $contactId;
        $contact = Database::queryOne(
            "SELECT id, email FROM contacts WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        );
        
        if (!$contact || empty($contact['email']) || !filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
            $results[] = ['contact_id' => $contactId, 'status' => 'skipped', 'reason' => 'No valid email'];
            $invalidCount++;
            continue;
        }
        
        try {
            $verificationResult = $thirdPartyService->verifyEmail($contact['email']);
            
            if ($verificationResult['status'] === 'error') {
                $results[] = ['contact_id' => $contactId, 'status' => 'error', 'message' => $verificationResult['message'] ?? 'Verification failed'];
                $errorCount++;
                continue;
            }
            
            $emailVerified = $verificationResult['email_verified'] ?? false;
            $status = $verificationResult['email_verification_status'] ?? null;
            
            Database::execute(
                "UPDATE contacts SET email_verified = ?, email_verification_status = ? WHERE workspace_id = ? AND id = ?",
                [$emailVerified ? 1 : 0, $status, $workspaceId, $contactId]
            );
            
            $results[] = [
                'contact_id' => $contactId,
                'status' => 'success',
                'email_verified' => $emailVerified,
                'email_verification_status' => $status
            ];
            $verifiedCount++;
            
            usleep(300000); // 0.3s delay to avoid rate limiting
        } catch (\Exception $e) {
            $results[] = ['contact_id' => $contactId, 'status' => 'error', 'message' => $e->getMessage()];
            $errorCount++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'total' => count($contactIds),
        'verified_count' => $verifiedCount,
        'invalid_count' => $invalidCount,
        'error_count' => $errorCount,
        'results' => $results
    ], JSON_PRETTY_PRINT);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
