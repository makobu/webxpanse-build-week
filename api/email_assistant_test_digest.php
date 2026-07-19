<?php
/**
 * Send a test assistant digest to the currently logged-in user.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\EmailAssistantDigestService;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
if (!Authorization::can('settings.email_assistant', $user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$installer = new WorkspaceSkillInstallService();
if ($workspaceId <= 0 || !$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT)) {
    http_response_code(400);
    echo json_encode(['error' => 'Install Email Assistant from Marketplace before sending a test digest.']);
    exit;
}
$workspaceConfig = (new WorkspaceAssistantConfigService())->get($workspaceId, 'email', false);
if (empty($workspaceConfig['enabled'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Enable Email Assistant in Marketplace before sending a test digest.']);
    exit;
}

$service = new EmailAssistantDigestService();
$config = $service->validateDigestConfig();
if (empty($config['outbound_ready'])) {
    http_response_code(400);
    echo json_encode(['error' => $config['message']]);
    exit;
}

$userId = (int) ($user['id'] ?? 0);
$email = trim((string) ($user['email'] ?? ''));
if ($userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Current user does not have a valid email address.']);
    exit;
}

try {
    $result = $service->sendDigestToUser($userId, $email, true);
    echo json_encode([
        'success' => true,
        'message' => 'Test digest sent.',
        'email' => $email,
        'task_count' => (int) ($result['task_count'] ?? 0),
        'provider' => (string) ($result['provider'] ?? ''),
        'delivery_method' => (string) ($result['delivery_method'] ?? ''),
    ]);
} catch (\Throwable $e) {
    error_log('Email assistant test digest error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The test digest could not be delivered. Check Email Assistant provider status and try again.']);
}
