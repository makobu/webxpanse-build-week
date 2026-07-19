<?php
/**
 * Email Template Test API
 * Sends a test email using a template with sample data
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ob_start();

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
use CRM\Security;
use CRM\Services\EmailTemplates;
use CRM\Services\SMTPClient;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;

/**
 * @param array<string, mixed> $payload
 */
function respondJson(array $payload, int $statusCode = 200): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }

    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    respondJson(['error' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(['error' => 'Method not allowed'], 405);
}

if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    respondJson(['error' => 'Invalid CSRF token'], 403);
}

try {
    $templateId = (int) ($_POST['template_id'] ?? 0);
    $testEmail = $_POST['test_email'] ?? '';
    
    if (!$templateId) {
        respondJson(['error' => 'Template ID required'], 400);
    }
    
    if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        respondJson(['error' => 'Valid test email address required'], 400);
    }
    
    $emailTemplatesService = new EmailTemplates();
    $currentUser = Auth::user();
    $currentUserId = (int) ($currentUser['id'] ?? 0);
    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    WorkspaceContext::activateRuntimeWorkspace($workspaceId, $currentUserId);
    $template = $emailTemplatesService->getSendableTemplateById($templateId, $currentUserId);
    
    if (!$template) {
        respondJson(['error' => 'Template not found'], 404);
    }
    
    // Get variables
    $variables = is_string($template['variables'] ?? '') ? json_decode($template['variables'], true) : ($template['variables'] ?? []);
    $variables = is_array($variables) ? $variables : [];
    
    // Create sample data
    $sampleData = [];
    foreach ($variables as $var) {
        $var = trim($var);
        if ($var === 'first_name') $sampleData[$var] = 'John';
        elseif ($var === 'last_name') $sampleData[$var] = 'Doe';
        elseif ($var === 'email') $sampleData[$var] = $testEmail;
        elseif ($var === 'company') $sampleData[$var] = 'Acme Corp';
        else $sampleData[$var] = 'Sample ' . ucfirst(str_replace('_', ' ', $var));
    }
    
    // Replace variables
    $subject = $template['subject'];
    $bodyHtml = $template['body_html'];
    $bodyText = $template['body_text'] ?? '';
    
    foreach ($sampleData as $key => $value) {
        $subject = str_replace('{' . $key . '}', $value, $subject);
        $bodyHtml = str_replace('{' . $key . '}', htmlspecialchars($value), $bodyHtml);
        $bodyText = str_replace('{' . $key . '}', $value, $bodyText);
    }
    
    // Remove any remaining variables
    $subject = preg_replace('/\{[^}]+\}/', '', $subject);
    $bodyHtml = preg_replace('/\{[^}]+\}/', '', $bodyHtml);
    $bodyText = preg_replace('/\{[^}]+\}/', '', $bodyText);
    
    // Send test email
    $smtpClient = new SMTPClient('outreach');
    $fromEmail = $smtpClient->getPreferredFromEmail('noreply@example.com') ?? 'noreply@example.com';
    $fromName = $smtpClient->getPreferredFromName(brandProductName()) ?? brandProductName();
    $plainTextBody = $bodyText !== '' ? $bodyText : strip_tags((string) $bodyHtml);
    $result = $smtpClient->send(
        $testEmail,
        $fromEmail,
        $fromName,
        '[TEST] ' . $subject,
        $plainTextBody,
        [],
        $bodyHtml !== '' ? $bodyHtml : null
    );
    
    if ($result) {
        respondJson(['success' => true, 'message' => 'Test email sent successfully']);
    } else {
        respondJson(['error' => 'Failed to send email'], 500);
    }
} catch (Throwable $e) {
    respondJson(['error' => $e->getMessage()], 500);
}
