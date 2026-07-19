<?php
/**
 * Email Templates API
 * 
 * CRUD operations for email templates
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
use CRM\Services\EmailTemplates;
use CRM\Auth;

Database::init(require __DIR__ . '/../config/database.php');

header('Content-Type: application/json');

// Check authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));

$templatesService = new EmailTemplates();

try {
    switch ($method) {
        case 'GET':
            if (isset($pathParts[2]) && is_numeric($pathParts[2])) {
                // Get single template
                $template = $templatesService->getById((int) $pathParts[2]);
                if (!$template) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Template not found']);
                    exit;
                }
                echo json_encode($template);
            } elseif (isset($pathParts[2]) && $pathParts[2] === 'slug' && isset($pathParts[3])) {
                // Get by slug
                $template = $templatesService->getBySlug($pathParts[3]);
                if (!$template) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Template not found']);
                    exit;
                }
                echo json_encode($template);
            } elseif (isset($_GET['category'])) {
                // List by category
                $templates = $templatesService->list($_GET['category']);
                echo json_encode($templates);
            } else {
                // List all templates
                $templates = $templatesService->list();
                echo json_encode($templates);
            }
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON']);
                exit;
            }
            
            // Validate required fields
            if (empty($data['name']) || empty($data['slug']) || empty($data['subject']) || empty($data['body_html'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields: name, slug, subject, body_html']);
                exit;
            }
            
            $data['created_by'] = $_SESSION['user_id'] ?? null;
            $id = $templatesService->create($data);
            
            http_response_code(201);
            echo json_encode([
                'id' => $id,
                'message' => 'Template created successfully'
            ]);
            break;
            
        case 'PUT':
        case 'PATCH':
            if (!isset($pathParts[2]) || !is_numeric($pathParts[2])) {
                http_response_code(400);
                echo json_encode(['error' => 'Template ID required']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON']);
                exit;
            }
            
            $success = $templatesService->update((int) $pathParts[2], $data);
            
            if (!$success) {
                http_response_code(400);
                echo json_encode(['error' => 'Update failed']);
                exit;
            }
            
            echo json_encode(['message' => 'Template updated successfully']);
            break;
            
        case 'DELETE':
            if (!isset($pathParts[2]) || !is_numeric($pathParts[2])) {
                http_response_code(400);
                echo json_encode(['error' => 'Template ID required']);
                exit;
            }
            
            $success = $templatesService->delete((int) $pathParts[2]);
            
            if (!$success) {
                http_response_code(404);
                echo json_encode(['error' => 'Template not found']);
                exit;
            }
            
            echo json_encode(['message' => 'Template deleted successfully']);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
