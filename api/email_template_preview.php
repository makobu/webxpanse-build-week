<?php
/**
 * Email Template Preview/Load API Endpoint
 */

// Disable error display
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ob_start();

// Set JSON header FIRST
header('Content-Type: application/json');

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
use CRM\Auth;
use CRM\Services\EmailTemplates;
use CRM\Services\WorkspaceScopeService;

try {
    // Initialize database
    Database::init(require __DIR__ . '/../config/database.php');
    
    // Start session
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    // Clear any output
    ob_clean();
    
    // Require authentication
    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }
    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    if ($method === 'GET') {
        // Accept both 'slug' and 'template_slug' for compatibility
        $slug = $_GET['slug'] ?? $_GET['template_slug'] ?? '';
        $contactId = (int) ($_GET['contact_id'] ?? 0);
        $includeRaw = in_array((string) ($_GET['include_raw'] ?? ''), ['1', 'true', 'yes'], true);
        
        if (!$slug) {
            ob_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Template slug required']);
            exit;
        }
        
        if (!$contactId) {
            ob_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Contact ID required']);
            exit;
        }
        
        // Get contact data
        $contact = Database::queryOne(
            "SELECT * FROM contacts WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        );
        if (!$contact) {
            ob_clean();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Contact not found']);
            exit;
        }
        
        // Get template
        $templatesService = new EmailTemplates();
        $currentUser = Auth::user();
        $currentUserId = (int) ($currentUser['id'] ?? 0);
        $template = $templatesService->getSendableTemplateBySlug($slug, $currentUserId);
        
        if (!$template) {
            ob_clean();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Template not found']);
            exit;
        }
        
        // Prepare variables
        $variables = [
            'first_name' => $contact['first_name'] ?? '',
            'last_name' => $contact['last_name'] ?? '',
            'email' => $contact['email'] ?? '',
            'phone' => $contact['phone'] ?? '',
            'company' => $contact['company'] ?? '',
        ];
        
        // Render template
        $rendered = $templatesService->renderSendableTemplate($slug, $currentUserId, $variables);
        
        ob_clean();
        $response = [
            'success' => true,
            'subject' => $rendered['subject'],
            'body_html' => $rendered['body_html'],
            'body_text' => $rendered['body_text'],
        ];

        if ($includeRaw) {
            $declaredVariables = is_string($template['variables'] ?? '')
                ? json_decode((string) $template['variables'], true)
                : ($template['variables'] ?? []);
            $declaredVariables = is_array($declaredVariables) ? array_values(array_filter(array_map('trim', $declaredVariables))) : [];

            $response['variables'] = $declaredVariables;
            $response['raw_subject'] = (string) ($template['subject'] ?? '');
            $response['raw_body_html'] = (string) ($template['body_html'] ?? '');
            $response['raw_body_text'] = (string) ($template['body_text'] ?? '');
            $response['template'] = [
                'id' => (int) ($template['id'] ?? 0),
                'name' => (string) ($template['name'] ?? ''),
                'slug' => (string) ($template['slug'] ?? ''),
                'variables' => $declaredVariables,
                'raw_subject' => (string) ($template['subject'] ?? ''),
                'raw_body_html' => (string) ($template['body_html'] ?? ''),
                'raw_body_text' => (string) ($template['body_text'] ?? ''),
            ];
            $response['preview_contact'] = [
                'id' => (int) ($contact['id'] ?? 0),
                'first_name' => (string) ($contact['first_name'] ?? ''),
                'last_name' => (string) ($contact['last_name'] ?? ''),
                'email' => (string) ($contact['email'] ?? ''),
                'phone' => (string) ($contact['phone'] ?? ''),
                'company' => (string) ($contact['company'] ?? ''),
            ];
        }

        echo json_encode($response, JSON_PRETTY_PRINT);
        
    } else {
        ob_clean();
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }
} catch (Throwable $e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
