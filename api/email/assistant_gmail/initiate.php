<?php
/**
 * Assistant Gmail OAuth initiate endpoint.
 */

require_once __DIR__ . '/../../../../vendor/autoload.php';

$envFile = __DIR__ . '/../../../../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Services\AssistantGmailMailService;
use CRM\Services\GoogleOAuthScopeCatalog;
use CRM\Services\WorkspaceConnectService;

Database::init(require __DIR__ . '/../../../../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

try {
    (new WorkspaceConnectService())->requireWorkspaceAdmin(Auth::user());
    $service = new AssistantGmailMailService();
    header('Location: ' . $service->getAuthUrl((int) (Auth::user()['id'] ?? 0), GoogleOAuthScopeCatalog::GRANT_ASSISTANT_GMAIL));
    exit;
} catch (\Throwable $e) {
    header('Location: ' . getBasePath() . '/settings.php?tab=email_assistant&error=' . urlencode($e->getMessage()) . '#connect-apps');
    exit;
}
