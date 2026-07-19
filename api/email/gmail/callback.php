<?php
/**
 * Gmail mail OAuth callback.
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
use CRM\Services\EmailIntegrationService;
use CRM\Services\GmailMailService;
use CRM\Services\WorkspaceConnectService;

Database::init(require __DIR__ . '/../../../config/database.php');
Session::start();

$redirectUrl = getBasePath() . '/settings.php?tab=email';

if (!Auth::check()) {
    header('Location: ' . $redirectUrl . '&error=session_expired');
    exit;
}

$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '') {
    header('Location: ' . $redirectUrl . '&error=no_code');
    exit;
}

try {
    $workspaceId = (new WorkspaceConnectService())->requireWorkspaceAdmin(Auth::user());
    $mailService = new GmailMailService();
    $oauthContext = $mailService->consumeAuthorizedContext((string) ($_GET['state'] ?? ''));
    $tokens = $mailService->exchangeCodeForTokens($code, (string) $oauthContext['grant_type']);
    $profile = $mailService->getAuthenticatedProfile((string) ($tokens['access_token'] ?? ''));

    $integrationService = new EmailIntegrationService();
    $integrationService->storeGmailIntegration(
        (int) $oauthContext['user_id'],
        $tokens,
        $profile,
        $workspaceId,
        (string) $oauthContext['grant_type'],
        is_array($oauthContext['requested_scopes'] ?? null) ? $oauthContext['requested_scopes'] : [],
        (string) ($oauthContext['oauth_client_key'] ?? '')
    );

    header('Location: ' . $redirectUrl . '&success=gmail_connected#connect-apps');
    exit;
} catch (\Throwable $e) {
    header('Location: ' . $redirectUrl . '&error=' . urlencode($e->getMessage()));
    exit;
}
