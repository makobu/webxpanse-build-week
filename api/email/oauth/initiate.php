<?php
/**
 * Main email OAuth initiate endpoint.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

$envFile = __DIR__ . '/../../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
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
use CRM\Session;
use CRM\Services\GoogleOAuthScopeCatalog;
use CRM\Services\GoogleWorkspaceMailService;
use CRM\Services\WorkspaceConnectService;

Database::init(require __DIR__ . '/../../../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

try {
    (new WorkspaceConnectService())->requireWorkspaceAdmin(Auth::user());
    $service = new GoogleWorkspaceMailService();
    $grant = GoogleOAuthScopeCatalog::normalizeGrantType((string) ($_GET['grant'] ?? GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND));
    if (!in_array($grant, [GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND, GoogleOAuthScopeCatalog::GRANT_GMAIL_INBOX], true)) {
        $grant = GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND;
    }
    $authUrl = $service->getAuthUrl((int) (Auth::user()['id'] ?? 0), $grant);
    header('Location: ' . $authUrl);
    exit;
} catch (\Throwable $e) {
    header('Location: ' . getBasePath() . '/settings.php?tab=email&error=' . urlencode($e->getMessage()));
    exit;
}
