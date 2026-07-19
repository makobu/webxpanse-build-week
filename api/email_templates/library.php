<?php
/**
 * Email Templates Library API
 * 
 * API endpoints for browsing and installing library templates
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';
use CRM\Database;
use CRM\Services\EmailTemplates;
use CRM\Auth;

Database::init(require __DIR__ . '/../../config/database.php');

header('Content-Type: application/json');

// Check authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

http_response_code(410);
echo json_encode([
    'error' => 'Generic email template library has been retired. Use AI drafting until learned templates are ready.'
]);
exit;

$templatesService = new EmailTemplates();
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$lastPart = end($pathParts);

try {
    // Handle different endpoints
    if ($lastPart === 'install') {
        // POST /api/email_templates/library.php/install
        if ($method === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['template_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Template ID required']);
                exit;
            }
            
            $userId = $_SESSION['user_id'] ?? null;
            if (!$userId) {
                http_response_code(401);
                echo json_encode(['error' => 'User ID required']);
                exit;
            }
            
            $customizations = $data['customizations'] ?? [];
            $newTemplateId = $templatesService->installLibraryTemplate(
                (int)$data['template_id'],
                (int)$userId,
                $customizations
            );
            
            http_response_code(201);
            echo json_encode([
                'id' => $newTemplateId,
                'message' => 'Template installed successfully'
            ]);
            exit;
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
    } elseif ($lastPart === 'search') {
        // GET /api/email_templates/library.php/search?q={query}
        if ($method === 'GET') {
            $query = $_GET['q'] ?? '';
            $filters = [
                'search' => $query,
                'is_library' => true
            ];
            
            // Add other filters
            if (isset($_GET['category'])) $filters['category'] = $_GET['category'];
            if (isset($_GET['industry'])) $filters['industry'] = $_GET['industry'];
            if (isset($_GET['purpose'])) $filters['purpose'] = $_GET['purpose'];
            if (isset($_GET['tags'])) {
                $filters['tags'] = is_array($_GET['tags']) ? $_GET['tags'] : explode(',', $_GET['tags']);
            }
            if (isset($_GET['is_featured'])) $filters['is_featured'] = $_GET['is_featured'] === 'true';
            if (isset($_GET['limit'])) $filters['limit'] = (int)$_GET['limit'];
            if (isset($_GET['offset'])) $filters['offset'] = (int)$_GET['offset'];
            if (isset($_GET['sort_by'])) $filters['sort_by'] = $_GET['sort_by'];
            if (isset($_GET['sort_order'])) $filters['sort_order'] = $_GET['sort_order'];
            
            $templates = $templatesService->searchTemplates($query, $filters);
            echo json_encode($templates);
            exit;
        }
    } elseif ($lastPart === 'categories') {
        // GET /api/email_templates/library.php/categories
        if ($method === 'GET') {
            $categories = $templatesService->getCategories();
            echo json_encode($categories);
            exit;
        }
    } elseif ($lastPart === 'tags') {
        // GET /api/email_templates/library.php/tags
        if ($method === 'GET') {
            $tags = $templatesService->getTags();
            echo json_encode($tags);
            exit;
        }
    } elseif ($lastPart === 'industries') {
        // GET /api/email_templates/library.php/industries
        if ($method === 'GET') {
            $industries = $templatesService->getIndustries();
            echo json_encode($industries);
            exit;
        }
    } elseif ($lastPart === 'purposes') {
        // GET /api/email_templates/library.php/purposes
        if ($method === 'GET') {
            $purposes = $templatesService->getPurposes();
            echo json_encode($purposes);
            exit;
        }
    } elseif (isset($_GET['id'])) {
        // GET /api/email_templates/library.php?id={id}
        if ($method === 'GET') {
            $template = $templatesService->getById((int)$_GET['id']);
            if (!$template || !$template['is_library']) {
                http_response_code(404);
                echo json_encode(['error' => 'Library template not found']);
                exit;
            }
            
            // Decode JSON fields
            $template['variables'] = json_decode($template['variables'] ?? '[]', true);
            $template['tags'] = json_decode($template['tags'] ?? '[]', true);
            
            echo json_encode($template);
            exit;
        }
    } else {
        // GET /api/email_templates/library.php - List library templates
        if ($method === 'GET') {
            $filters = [
                'is_library' => true
            ];
            
            // Add filters from query parameters
            if (isset($_GET['category'])) $filters['category'] = $_GET['category'];
            if (isset($_GET['industry'])) $filters['industry'] = $_GET['industry'];
            if (isset($_GET['purpose'])) $filters['purpose'] = $_GET['purpose'];
            if (isset($_GET['tags'])) {
                $filters['tags'] = is_array($_GET['tags']) ? $_GET['tags'] : explode(',', $_GET['tags']);
            }
            if (isset($_GET['is_featured'])) $filters['is_featured'] = $_GET['is_featured'] === 'true';
            if (isset($_GET['search'])) $filters['search'] = $_GET['search'];
            if (isset($_GET['limit'])) $filters['limit'] = (int)$_GET['limit'];
            if (isset($_GET['offset'])) $filters['offset'] = (int)$_GET['offset'];
            if (isset($_GET['sort_by'])) $filters['sort_by'] = $_GET['sort_by'];
            if (isset($_GET['sort_order'])) $filters['sort_order'] = $_GET['sort_order'];
            
            $templates = $templatesService->getLibraryTemplates($filters);
            echo json_encode($templates);
            exit;
        }
    }
    
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
