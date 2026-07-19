<?php
/**
 * Draft Templates API Endpoint
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../public/index.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Modules\DraftTemplates;
use CRM\Security;

header('Content-Type: application/json');

// Require authentication
Auth::requireAuth();
Authorization::requirePermission('drafts.manage', true);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$templates = new DraftTemplates();

function requireDraftTemplatesApiCsrf(?array $jsonInput = null): bool
{
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($jsonInput['csrf_token'] ?? '');
    if (Security::validateCSRF((string) $csrfToken)) {
        return true;
    }

    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    return false;
}

try {
    switch ($method) {
        case 'GET':
            $action = $_GET['action'] ?? 'list';
            $id = $_GET['id'] ?? null;
            $templateId = $_GET['template_id'] ?? null;
            $contactId = $_GET['contact_id'] ?? null;
            
            if ($action === 'apply' && $templateId && $contactId) {
                $result = $templates->applyToContact((int) $templateId, (int) $contactId);
                echo json_encode(['success' => true] + $result, JSON_PRETTY_PRINT);
            } elseif ($id) {
                $template = $templates->getById((int) $id);
                echo json_encode($template ?: ['error' => 'Template not found'], JSON_PRETTY_PRINT);
            } else {
                $type = $_GET['type'] ?? null;
                $allTemplates = $templates->getAll($type);
                echo json_encode(['templates' => $allTemplates], JSON_PRETTY_PRINT);
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            if (!requireDraftTemplatesApiCsrf(is_array($input) ? $input : null)) {
                break;
            }

            $templateId = $templates->create($input);
            echo json_encode(['success' => true, 'id' => $templateId], JSON_PRETTY_PRINT);
            break;
            
        case 'PUT':
        case 'PATCH':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!requireDraftTemplatesApiCsrf(is_array($input) ? $input : null)) {
                break;
            }
            $id = $_GET['id'] ?? $input['id'] ?? null;
            
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Template ID required']);
                break;
            }
            
            $result = $templates->update((int) $id, $input);
            echo json_encode(['success' => $result], JSON_PRETTY_PRINT);
            break;
            
        case 'DELETE':
            if (!requireDraftTemplatesApiCsrf()) {
                break;
            }
            $id = $_GET['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Template ID required']);
                break;
            }
            
            $result = $templates->delete((int) $id);
            echo json_encode(['success' => $result], JSON_PRETTY_PRINT);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
