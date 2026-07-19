<?php
/**
 * WhatsApp Templates API Endpoint
 * 
 * Returns list of approved WhatsApp message templates
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
use CRM\Database;
use CRM\Services\WhatsAppFeatureGate;
use CRM\Services\WhatsAppTemplateService;
use CRM\Services\WhatsAppService;
use CRM\Services\WorkspaceContext;

// Initialize database
$dbConfig = require __DIR__ . '/../../config/database.php';
Database::init($dbConfig);

// Start session for authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Require authentication (return JSON error instead of redirect)
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use GET.']);
        exit;
    }
    
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    $templates = [];
    $source = 'provider';
    if ($workspaceId > 0 && ($_GET['source'] ?? '') !== 'provider' && (new WhatsAppFeatureGate())->templateCenterEnabled($workspaceId)) {
        $templates = (new WhatsAppTemplateService())->approvedForSending($workspaceId);
        if ($templates !== []) {
            $source = 'template_center';
        }
    }
    if ($templates === []) {
        $whatsappService = new WhatsAppService();
        $templates = $whatsappService->getTemplates();
    }
    
    echo json_encode([
        'success' => true,
        'templates' => $templates,
        'count' => count($templates),
        'source' => $source
    ]);
    
} catch (\RuntimeException $e) {
    http_response_code(400);
    error_log("WhatsApp Templates API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    error_log("WhatsApp Templates API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred: ' . $e->getMessage()
    ]);
}
