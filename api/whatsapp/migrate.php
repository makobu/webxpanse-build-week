<?php
/**
 * WhatsApp Cloud API Migration Endpoint
 * 
 * RESTful API for migration operations:
 * - GET ?status=1 - Get migration status
 * - GET ?health=1 - Check health status
 * - POST with action - Perform migration steps
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
use CRM\Security;
use CRM\Services\WhatsAppMigrationService;
use CRM\Services\WorkspaceConnectService;

// Initialize database
$dbConfig = require __DIR__ . '/../../config/database.php';
Database::init($dbConfig);

// Start session for authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Require admin authentication (return JSON error instead of redirect)
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$user = Auth::user();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $connectService = new WorkspaceConnectService();
    $workspaceId = $connectService->requireWorkspaceAdmin($user);
    $migrationService = new WhatsAppMigrationService($workspaceId, (int) ($user['id'] ?? 0));
    
    // Handle GET requests
    if ($method === 'GET') {
        // Get migration status
        if (isset($_GET['status']) && $_GET['status'] == '1') {
            $status = $migrationService->getMigrationStatus();
            echo json_encode([
                'success' => true,
                'workspace_id' => $workspaceId,
                'status' => $status
            ]);
            exit;
        }
        
        // Check health status
        if (isset($_GET['health']) && $_GET['health'] == '1') {
            $health = $migrationService->checkHealthStatus();
            echo json_encode([
                'success' => true,
                'workspace_id' => $workspaceId,
                'health' => $health
            ]);
            exit;
        }
        
        // Invalid GET request
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request. Use ?status=1 or ?health=1']);
        exit;
    }
    
    // Handle POST requests
    if ($method === 'POST') {
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input)) {
            // Try form data
            $input = $_POST;
        }
        
        // Validate CSRF token
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
        if (!Security::validateCSRF($csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
        
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'generate_metadata':
                $password = $input['password'] ?? '';
                
                if (empty($password)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Password is required']);
                    exit;
                }
                
                if (strlen($password) < 8) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters long']);
                    exit;
                }
                
                $result = $migrationService->generateMetadata($password);
                echo json_encode($result);
                exit;
                
            case 'register_number':
                $pin = $input['pin'] ?? '';
                $password = $input['password'] ?? null; // Optional - only needed for On-Premises migration
                $metadata = $input['metadata'] ?? null; // Optional - only needed for On-Premises migration
                $dataLocalizationRegion = $input['data_localization_region'] ?? null;
                
                if (empty($pin)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'PIN is required']);
                    exit;
                }
                
                // Password and metadata are optional - only required for On-Premises API migration
                // For simple registration of pending phone numbers, they're not needed
                
                // Validate data localization region if provided
                if (!empty($dataLocalizationRegion) && strlen($dataLocalizationRegion) !== 2) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Data localization region must be a 2-letter ISO country code']);
                    exit;
                }
                
                $result = $migrationService->registerNumber($pin, $password, $metadata, $dataLocalizationRegion);
                echo json_encode($result);
                exit;
                
            case 'deregister_number':
                $confirm = $input['confirm'] ?? false;
                
                if (!$confirm) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Confirmation required. Set confirm to true.']);
                    exit;
                }
                
                $result = $migrationService->deregisterNumber();
                echo json_encode($result);
                exit;
                
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action. Use: generate_metadata, register_number, or deregister_number']);
                exit;
        }
    }
    
    // Method not allowed
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    
} catch (\RuntimeException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    error_log("WhatsApp Migration API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred. Please check the logs for details.'
    ]);
}
