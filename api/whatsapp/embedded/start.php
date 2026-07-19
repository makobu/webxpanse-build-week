<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

$envFile = __DIR__ . '/../../../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\WorkspaceConnectService;

Database::init(require __DIR__ . '/../../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    $service = new WorkspaceConnectService();
    $workspaceId = $service->requireWorkspaceAdmin(Auth::user());
    $state = $service->buildHubState(Auth::user(), $workspaceId);
    $appId = trim((string) ($_ENV['META_APP_ID'] ?? ''));
    $configId = trim((string) ($_ENV['META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'] ?? $_ENV['META_EMBEDDED_SIGNUP_CONFIG_ID'] ?? ''));

    if ($appId === '' || $configId === '') {
        echo json_encode([
            'success' => false,
            'error' => 'Platform WhatsApp signup is not configured yet. Contact Super Admin.',
            'whatsapp' => $state['whatsapp'],
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'app_id' => $appId,
        'config_id' => $configId,
        'csrf_token' => Security::getCsrfToken(),
        'callback_url' => rtrim(getApiBasePath(), '/') . '/api/whatsapp/embedded/callback.php',
        'whatsapp' => $state['whatsapp'],
    ]);
} catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
