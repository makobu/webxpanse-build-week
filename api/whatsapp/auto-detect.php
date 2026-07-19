<?php
/**
 * WhatsApp Auto-Detect API
 * 
 * Auto-detects WABA ID and phone numbers using business management APIs
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
use CRM\Services\WhatsAppService;

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
    
    $businessService = new WhatsAppBusinessService();
    $whatsappService = new WhatsAppService();
    
    $result = [
        'businesses' => [],
        'phone_numbers' => [],
        'waba_id' => null,
        'phone_number_id' => null
    ];
    
    // Try to list businesses (Business Manager IDs, NOT WABA IDs)
    try {
        $businesses = $businessService->listBusinesses();
        $result['businesses'] = $businesses['data'] ?? [];
        
        // For each Business, get owned WABAs via owned_whatsapp_business_accounts
        if (!empty($result['businesses'])) {
            foreach ($result['businesses'] as $business) {
                $businessId = $business['id'] ?? null;
                if (empty($businessId)) {
                    continue;
                }
                try {
                    $wabas = $businessService->listOwnedWhatsappBusinessAccounts($businessId);
                    $wabaList = $wabas['data'] ?? [];
                    if (!empty($wabaList)) {
                        $firstWaba = $wabaList[0];
                        $wabaId = $firstWaba['id'] ?? null;
                        if ($wabaId) {
                            $result['waba_id'] = $wabaId;
                            try {
                                $phoneNumbers = $businessService->listPhoneNumbers($wabaId);
                                $result['phone_numbers'] = $phoneNumbers['data'] ?? [];
                                if (!empty($result['phone_numbers'])) {
                                    $firstPhone = $result['phone_numbers'][0];
                                    $result['phone_number_id'] = $firstPhone['id'] ?? null;
                                }
                            } catch (\Exception $e) {
                                error_log("Failed to get phone numbers: " . $e->getMessage());
                            }
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Failed to list owned WABAs for business {$businessId}: " . $e->getMessage());
                }
            }
        }
    } catch (\Exception $e) {
        error_log("Failed to list businesses: " . $e->getMessage());
    }
    
    // Alternative: Try to get WABA ID from phone number if phone number ID is set
    $phoneNumberId = $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? '';
    if (empty($result['waba_id']) && !empty($phoneNumberId)) {
        try {
            $wabaId = $whatsappService->getWabaIdFromPhoneNumber();
            if ($wabaId) {
                $result['waba_id'] = $wabaId;
                
                // Try to get phone numbers
                try {
                    $phoneNumbers = $businessService->listPhoneNumbers($wabaId);
                    $result['phone_numbers'] = $phoneNumbers['data'] ?? [];
                } catch (\Exception $e) {
                    error_log("Failed to get phone numbers from WABA: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to get WABA ID from phone number: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    
} catch (\RuntimeException $e) {
    http_response_code(400);
    error_log("WhatsApp Auto-Detect API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    error_log("WhatsApp Auto-Detect API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred: ' . $e->getMessage()
    ]);
}
