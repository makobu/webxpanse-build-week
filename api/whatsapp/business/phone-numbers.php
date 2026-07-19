<?php
/**
 * WhatsApp Business Phone Numbers API
 * 
 * Lists phone numbers for business account (business_management permission)
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Services\WhatsAppBusinessService;

// Initialize database
$dbConfig = require __DIR__ . '/../../../config/database.php';
Database::init($dbConfig);

// Start session for authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Require admin authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$user = Auth::user();
Authorization::requirePermission('settings.whatsapp', true);


$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use GET.']);
        exit;
    }
    
    $wabaId = $_GET['waba_id'] ?? $_ENV['WHATSAPP_BUSINESS_ACCOUNT_ID'] ?? '';
    
    if (empty($wabaId)) {
        http_response_code(400);
        echo json_encode(['error' => 'WABA ID is required. Provide waba_id parameter or set WHATSAPP_BUSINESS_ACCOUNT_ID in .env']);
        exit;
    }
    
    $service = new WhatsAppBusinessService();
    $result = $service->listPhoneNumbers($wabaId);
    
    echo json_encode([
        'success' => true,
        'phone_numbers' => $result['data'] ?? [],
        'count' => count($result['data'] ?? [])
    ]);
    
} catch (\RuntimeException $e) {
    http_response_code(400);
    error_log("WhatsApp Phone Numbers API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    error_log("WhatsApp Phone Numbers API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred: ' . $e->getMessage()
    ]);
}
