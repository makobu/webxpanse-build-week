<?php
/**
 * WhatsApp Email Verification API
 * 
 * Gets user email (email permission)
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
    if ($method !== 'GET' && $method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use GET or POST.']);
        exit;
    }
    
    $service = new WhatsAppBusinessService();
    $result = $service->getUserEmail();
    
    echo json_encode([
        'success' => true,
        'email' => $result['email'] ?? null,
        'data' => $result
    ]);
    
} catch (\RuntimeException $e) {
    http_response_code(400);
    error_log("WhatsApp Email Verify API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    error_log("WhatsApp Email Verify API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred: ' . $e->getMessage()
    ]);
}
