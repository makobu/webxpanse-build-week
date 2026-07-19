<?php
/**
 * WhatsApp Permissions Test API
 * 
 * Comprehensive endpoint that tests all Meta permissions for App Review
 * Tests: whatsapp_business_manage_events, manage_app_solution, email, business_management,
 *       whatsapp_business_messaging, whatsapp_business_management
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
    if ($method !== 'GET' && $method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use GET or POST.']);
        exit;
    }
    
    $service = new WhatsAppBusinessService();
    $results = [
        'whatsapp_business_manage_events' => [],
        'manage_app_solution' => [],
        'email' => [],
        'business_management' => [],
        'whatsapp_business_messaging' => [],
        'whatsapp_business_management' => []
    ];
    
    // Test 1: whatsapp_business_manage_events - List webhooks
    try {
        $webhooks = $service->listWebhooks();
        $results['whatsapp_business_manage_events']['list_webhooks'] = [
            'success' => true,
            'data' => $webhooks
        ];
    } catch (\Exception $e) {
        $results['whatsapp_business_manage_events']['list_webhooks'] = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
    
    // Test 2: manage_app_solution - Get app config
    try {
        $appConfig = $service->getAppConfig();
        $results['manage_app_solution']['get_app_config'] = [
            'success' => true,
            'data' => $appConfig
        ];
    } catch (\Exception $e) {
        $results['manage_app_solution']['get_app_config'] = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
    
    // Test 3: email - Get user email
    try {
        $emailData = $service->getUserEmail();
        $results['email']['get_user_email'] = [
            'success' => true,
            'data' => $emailData
        ];
    } catch (\Exception $e) {
        $results['email']['get_user_email'] = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }

    // Test: whatsapp_business_messaging - Get business profile
    $phoneNumberId = $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? '';
    if (!empty($phoneNumberId)) {
        try {
            $profile = $service->getBusinessProfile($phoneNumberId);
            $results['whatsapp_business_messaging']['get_business_profile'] = [
                'success' => true,
                'data' => $profile
            ];
        } catch (\Exception $e) {
            $results['whatsapp_business_messaging']['get_business_profile'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    } else {
        $results['whatsapp_business_messaging']['get_business_profile'] = [
            'success' => false,
            'error' => 'WHATSAPP_PHONE_NUMBER_ID is not set in .env'
        ];
    }
    
    // Test 4: business_management - List businesses
    $businessId = null;
    try {
        $businesses = $service->listBusinesses();
        $businessesData = $businesses['data'] ?? [];
        if (!empty($businessesData) && is_array($businessesData)) {
            $businessId = $businessesData[0]['id'] ?? null;
        }
        $results['business_management']['list_businesses'] = [
            'success' => true,
            'data' => $businesses
        ];
    } catch (\Exception $e) {
        $results['business_management']['list_businesses'] = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
    
    // Test 5: business_management - Get Business details
    // Note: This requires a Business ID (from /me/businesses), NOT a WABA ID.
    if (!empty($businessId)) {
        try {
            $business = $service->getBusiness($businessId);
            $results['business_management']['get_business'] = [
                'success' => true,
                'data' => $business
            ];
        } catch (\Exception $e) {
            $results['business_management']['get_business'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    } else {
        $results['business_management']['get_business'] = [
            'success' => false,
            'error' => 'No Business ID available. Ensure your token includes business_management and the user/system user has access to at least one Business.'
        ];
    }
    
    // Test 6: business_management - List phone numbers (requires WABA ID)
    $wabaId = $_ENV['WHATSAPP_BUSINESS_ACCOUNT_ID'] ?? '';
    if (!empty($wabaId)) {
        try {
            $phoneNumbers = $service->listPhoneNumbers($wabaId);
            $results['business_management']['list_phone_numbers'] = [
                'success' => true,
                'data' => $phoneNumbers
            ];
        } catch (\Exception $e) {
            $results['business_management']['list_phone_numbers'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    } else {
        $results['business_management']['list_phone_numbers'] = [
            'success' => false,
            'error' => 'WHATSAPP_BUSINESS_ACCOUNT_ID (WABA ID) is not set. Set it in Settings > WhatsApp or in your .env file.'
        ];
    }

    // Test: whatsapp_business_management - Get WABA and list message templates
    if (!empty($wabaId)) {
        try {
            $waba = $service->getWaba($wabaId);
            $results['whatsapp_business_management']['get_waba'] = [
                'success' => true,
                'data' => $waba
            ];
        } catch (\Exception $e) {
            $results['whatsapp_business_management']['get_waba'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
        try {
            $templates = $service->listMessageTemplates($wabaId, 5);
            $results['whatsapp_business_management']['list_message_templates'] = [
                'success' => true,
                'data' => $templates
            ];
        } catch (\Exception $e) {
            $results['whatsapp_business_management']['list_message_templates'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    } else {
        $results['whatsapp_business_management']['get_waba'] = [
            'success' => false,
            'error' => 'WHATSAPP_BUSINESS_ACCOUNT_ID is not set'
        ];
        $results['whatsapp_business_management']['list_message_templates'] = [
            'success' => false,
            'error' => 'WHATSAPP_BUSINESS_ACCOUNT_ID is not set'
        ];
    }
    
    // Calculate summary
    $summary = [
        'total_tests' => 0,
        'passed' => 0,
        'failed' => 0
    ];
    
    foreach ($results as $permission => $tests) {
        foreach ($tests as $testName => $result) {
            $summary['total_tests']++;
            if ($result['success']) {
                $summary['passed']++;
            } else {
                $summary['failed']++;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'results' => $results,
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
    
} catch (\Exception $e) {
    http_response_code(500);
    error_log("WhatsApp Test Permissions API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred: ' . $e->getMessage()
    ]);
}
