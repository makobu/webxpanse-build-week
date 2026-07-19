<?php
/**
 * WhatsApp Business List API
 * 
 * Lists business accounts (business_management permission)
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
    
    $service = new WhatsAppBusinessService();
    $result = $service->listBusinesses();
    
    echo json_encode([
        'success' => true,
        'businesses' => $result['data'] ?? [],
        'count' => count($result['data'] ?? [])
    ]);
    
} catch (\RuntimeException $e) {
    http_response_code(400);
    error_log("WhatsApp Business List API Error: " . $e->getMessage());
    $payload = ['success' => false, 'error' => $e->getMessage()];
    if (stripos($e->getMessage(), 'Missing Permission') !== false || stripos($e->getMessage(), '(#100)') !== false) {
        $payload['hint'] = 'Token may be missing the business_management permission. Generate a token with that scope in Meta Business Manager or Graph API Explorer.';
    }
    echo json_encode($payload);
} catch (\Exception $e) {
    http_response_code(500);
    error_log("WhatsApp Business List API Error: " . $e->getMessage());
    $payload = ['success' => false, 'error' => 'An unexpected error occurred: ' . $e->getMessage()];
    if (stripos($e->getMessage(), 'Missing Permission') !== false || stripos($e->getMessage(), '(#100)') !== false) {
        $payload['hint'] = 'Token may be missing the business_management permission.';
    }
    echo json_encode($payload);
}
