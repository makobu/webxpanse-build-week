<?php
/**
 * Custom Fields API Endpoint
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment and initialize
require_once __DIR__ . '/../public/index.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Modules\CustomFields;
use CRM\Modules\Contacts;
use CRM\Security;

header('Content-Type: application/json');

// Require authentication
Auth::requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$customFields = new CustomFields();
$contacts = new Contacts();
$user = Auth::user();

function requireCustomFieldsApiCsrf(?array $jsonInput = null): bool
{
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($jsonInput['csrf_token'] ?? '');
    if (Security::validateCSRF((string) $csrfToken)) {
        return true;
    }

    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    return false;
}

function requireCustomFieldsPermission(string $permission, array $user): bool
{
    if (Authorization::can($permission, $user)) {
        return true;
    }

    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to perform this action.']);
    return false;
}

function requireVisibleCustomFieldsContact(Contacts $contacts, int $contactId, array $user): ?array
{
    $contact = $contacts->getById($contactId);
    if (!$contact || !$contacts->isVisibleToUser(
        $contact,
        (int) ($user['id'] ?? 0),
        Authorization::can('contacts.view_all', $user)
    )) {
        http_response_code(404);
        echo json_encode(['error' => 'Contact not found.']);
        return null;
    }

    return $contact;
}

try {
    switch ($method) {
        case 'GET':
            $id = $_GET['id'] ?? null;
            $module = $_GET['module'] ?? 'contacts';
            $contactId = $_GET['contact_id'] ?? null;
            
            if ($id) {
                $field = $customFields->getById((int) $id);
                echo json_encode($field ?: ['error' => 'Field not found'], JSON_PRETTY_PRINT);
            } elseif ($contactId) {
                if (requireVisibleCustomFieldsContact($contacts, (int) $contactId, $user) === null) {
                    break;
                }
                $values = $customFields->getContactValues((int) $contactId);
                echo json_encode(['values' => $values], JSON_PRETTY_PRINT);
            } else {
                $fields = $customFields->getByModule($module);
                echo json_encode(['fields' => $fields], JSON_PRETTY_PRINT);
            }
            break;
            
        case 'POST':
            // Verify CSRF token
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            if (!requireCustomFieldsApiCsrf(is_array($data) ? $data : null)) {
                break;
            }
            
            // Check if setting contact value
            if (isset($data['contact_id']) && isset($data['field_id'])) {
                if (!requireCustomFieldsPermission('contacts.write', $user)
                    || requireVisibleCustomFieldsContact($contacts, (int) $data['contact_id'], $user) === null) {
                    break;
                }
                $result = $customFields->setContactValue(
                    (int) $data['contact_id'],
                    (int) $data['field_id'],
                    $data['value'] ?? null
                );
                echo json_encode(['success' => $result], JSON_PRETTY_PRINT);
            } else {
                // Create new field
                if (!requireCustomFieldsPermission('crm.custom_fields.manage', $user)) {
                    break;
                }
                $fieldId = $customFields->create($data);
                http_response_code(201);
                echo json_encode(['id' => $fieldId, 'success' => true], JSON_PRETTY_PRINT);
            }
            break;
            
        case 'PUT':
        case 'PATCH':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!requireCustomFieldsApiCsrf(is_array($data) ? $data : null)) {
                break;
            }
            if (!requireCustomFieldsPermission('crm.custom_fields.manage', $user)) {
                break;
            }
            $id = $_GET['id'] ?? $data['id'] ?? null;
            
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Field ID required']);
                break;
            }
            
            $result = $customFields->update((int) $id, $data);
            echo json_encode(['success' => $result], JSON_PRETTY_PRINT);
            break;
            
        case 'DELETE':
            if (!requireCustomFieldsApiCsrf()) {
                break;
            }
            if (!requireCustomFieldsPermission('crm.custom_fields.manage', $user)) {
                break;
            }
            $id = $_GET['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Field ID required']);
                break;
            }
            
            $result = $customFields->delete((int) $id);
            http_response_code($result ? 200 : 404);
            echo json_encode(['success' => $result], JSON_PRETTY_PRINT);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (\CRM\ConcurrencyConflictException $e) {
    http_response_code(409);
    echo json_encode(\CRM\Concurrency::conflictPayload($e), JSON_PRETTY_PRINT);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
} catch (\PDOException $e) {
    error_log('Custom fields database request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The custom fields request could not be completed.'], JSON_PRETTY_PRINT);
} catch (\RuntimeException $e) {
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    error_log('Custom fields API request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The custom fields request could not be completed.'], JSON_PRETTY_PRINT);
}
