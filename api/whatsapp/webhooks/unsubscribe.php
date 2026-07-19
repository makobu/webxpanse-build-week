<?php
/**
 * WhatsApp Webhook Unsubscribe API
 * 
 * Unsubscribes from webhook events (whatsapp_business_manage_events permission)
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
use CRM\Database;
use CRM\Security;
use CRM\Services\WhatsAppBusinessService;
use CRM\Services\WorkspaceConnectService;

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
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$user = Auth::user();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $workspaceId = (new WorkspaceConnectService())->requireWorkspaceAdmin($user);

    if ($method !== 'POST' && $method !== 'DELETE') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST or DELETE.']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? ''));
    if (!Security::validateCSRF($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $object = $input['object'] ?? 'whatsapp_business_account';
    
    $service = new WhatsAppBusinessService();
    $result = $service->unsubscribeWebhook($object);
    
    echo json_encode([
        'success' => true,
        'workspace_id' => $workspaceId,
        'message' => 'Webhook unsubscribed successfully',
        'data' => $result
    ]);
    
} catch (\RuntimeException $e) {
    http_response_code(str_contains($e->getMessage(), 'Workspace admin access is required') ? 403 : 400);
    error_log("WhatsApp Webhook Unsubscribe API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    error_log("WhatsApp Webhook Unsubscribe API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred: ' . $e->getMessage()
    ]);
}
