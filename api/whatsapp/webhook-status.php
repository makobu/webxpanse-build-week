<?php
/**
 * WhatsApp Webhook Status API
 * 
 * Gets current webhook subscription status
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
use CRM\Authorization;
use CRM\Database;
use CRM\Services\WhatsAppBusinessService;

// Initialize database
$dbConfig = require __DIR__ . '/../../config/database.php';
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
    
    // Get webhook subscriptions
    $subscriptions = [];
    $status = 'not_configured';
    $statusError = null;
    
    try {
        $result = $service->listWebhooks();
        $subscriptions = $result['data'] ?? [];
        
        // Find WhatsApp Business Account subscription
        $whatsappSubscription = null;
        foreach ($subscriptions as $sub) {
            if (($sub['object'] ?? '') === 'whatsapp_business_account') {
                $whatsappSubscription = $sub;
                break;
            }
        }
        
        if ($whatsappSubscription) {
            $status = 'active';
        } else {
            $status = 'not_subscribed';
        }
    } catch (\RuntimeException $e) {
        if (strpos($e->getMessage(), 'META_APP_ID is required') !== false) {
            $status = 'app_id_not_set';
        } else {
            $status = 'error';
            $statusError = $e->getMessage();
            error_log("Webhook status error: " . $e->getMessage());
        }
    } catch (\Exception $e) {
        $status = 'error';
        $statusError = $e->getMessage();
        error_log("Webhook status error: " . $e->getMessage());
    }
    
    $secretRaw = $_ENV['META_APP_SECRET'] ?? '';
    $payload = [
        'success' => true,
        'status' => $status,
        'webhook_url' => '',
        'manual_workspace_setup_required' => true,
        'message' => 'WhatsApp callbacks are workspace-specific. Copy the URL and verify token from each workspace WhatsApp setup page.',
        'subscriptions' => $subscriptions,
        'whatsapp_subscription' => $whatsappSubscription ?? null,
        'app_secret_configured' => (trim($secretRaw) !== '')
    ];
    if ($statusError !== null) {
        $payload['error'] = $statusError;
    }
    echo json_encode($payload);
    
} catch (\RuntimeException $e) {
    http_response_code(400);
    error_log("WhatsApp Webhook Status API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    error_log("WhatsApp Webhook Status API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred: ' . $e->getMessage()
    ]);
}
