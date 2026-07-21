<?php
/**
 * Bulk Operations API for Contacts
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\Contacts;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Require contact-management permission (supports RBAC and legacy admin).
if (!Authorization::can('admin.users.manage')) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

$contacts = new Contacts();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'POST':
            if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid CSRF token']);
                break;
            }
            
            $rawIds = $_POST['contact_ids'] ?? '[]';
            $contactIds = json_decode($rawIds, true);
            if (!is_array($contactIds)) {
                $contactIds = array_filter(array_map('trim', explode(',', (string) $rawIds)));
            }
            $contactIds = array_values(array_unique(array_filter(array_map(function ($id) {
                $n = (int) $id;
                return $n > 0 ? $n : null;
            }, $contactIds))));
            if (empty($contactIds) || !is_array($contactIds)) {
                http_response_code(400);
                echo json_encode(['error' => 'Contact IDs required']);
                break;
            }
            
            switch ($action) {
                case 'bulk_update':
                    $updateData = [];
                    if (isset($_POST['stage'])) $updateData['stage'] = Security::sanitizeInput($_POST['stage'], 'string');
                    if (isset($_POST['assigned_to'])) $updateData['assigned_to'] = !empty($_POST['assigned_to']) ? (int) $_POST['assigned_to'] : null;
                    if (isset($_POST['lead_source'])) $updateData['lead_source'] = Security::sanitizeInput($_POST['lead_source'], 'string');
                    
                    if (empty($updateData)) {
                        http_response_code(400);
                        echo json_encode(['error' => 'No update data provided']);
                        break;
                    }
                    
                    $updated = $contacts->bulkUpdate($contactIds, $updateData);
                    echo json_encode(['success' => true, 'updated' => $updated]);
                    break;
                    
                case 'bulk_delete':
                    $deleted = $contacts->bulkDelete($contactIds);
                    echo json_encode(['success' => true, 'deleted' => $deleted]);
                    break;
                    
                case 'merge':
                    $sourceId = (int) ($_POST['source_id'] ?? 0);
                    $targetId = (int) ($_POST['target_id'] ?? 0);
                    $fieldPreferences = json_decode($_POST['field_preferences'] ?? '{}', true);
                    
                    if (!$sourceId || !$targetId) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Source and target contact IDs required']);
                        break;
                    }
                    
                    $contacts->merge($sourceId, $targetId, $fieldPreferences);
                    echo json_encode(['success' => true, 'message' => 'Contacts merged successfully']);
                    break;
                    
                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'The selected user is not a member of the active workspace.') {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } else {
        error_log('Bulk contacts request failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'The bulk contact request could not be completed.']);
    }
} catch (Throwable $e) {
    error_log('Bulk contacts request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The bulk contact request could not be completed.']);
}
