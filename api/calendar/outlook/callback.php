<?php
/**
 * Outlook Calendar OAuth Callback
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

$envFile = __DIR__ . '/../../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Services\OAuthTokenVault;
use CRM\Services\OutlookCalendarService;
use CRM\Services\WorkspaceConnectService;

Database::init(require __DIR__ . '/../../../config/database.php');
Session::start();

$basePath = getBasePath();
$calendarSetupUrl = static function (array $params = []) use ($basePath): string {
    $query = array_merge([
        'module' => 'calendar_meetings',
        'setup_tab' => 'calendar',
    ], $params);

    return $basePath . '/workspace_skills.php?' . http_build_query($query) . '#setup';
};
$appendQuery = static function (string $target, array $params): string {
    $fragment = '';
    $hashPos = strpos($target, '#');
    if ($hashPos !== false) {
        $fragment = substr($target, $hashPos);
        $target = substr($target, 0, $hashPos);
    }

    return $target . (str_contains($target, '?') ? '&' : '?') . http_build_query($params) . $fragment;
};
$redirectUrl = $calendarSetupUrl();

if (!Auth::check()) {
    header('Location: ' . $appendQuery($redirectUrl, ['error' => 'session_expired']));
    exit;
}

$code = $_GET['code'] ?? '';
$state = (string) ($_GET['state'] ?? '');

if (empty($code)) {
    header('Location: ' . $appendQuery($redirectUrl, ['error' => 'no_code']));
    exit;
}

try {
    $service = new OutlookCalendarService();
    $oauthContext = $service->consumeAuthorizedContext($state);
    $userId = (int) ($oauthContext['user_id'] ?? 0);
    if ($userId <= 0 || $userId !== (int) (Auth::user()['id'] ?? 0)) {
        throw new RuntimeException('Outlook Calendar authorization no longer matches the signed-in user.');
    }
    $currentWorkspaceId = (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
    if ($currentWorkspaceId > 0 && (int) ($oauthContext['workspace_id'] ?? 0) > 0 && $currentWorkspaceId !== (int) $oauthContext['workspace_id']) {
        throw new RuntimeException('Outlook Calendar authorization no longer matches the active workspace.');
    }
    $workspaceId = (new WorkspaceConnectService())->requireCalendarConnectionAccess(Auth::user(), (int) ($oauthContext['workspace_id'] ?? 0));
    $purpose = in_array((string) ($oauthContext['purpose'] ?? 'sync'), ['availability', 'sync', 'both'], true)
        ? (string) $oauthContext['purpose']
        : 'sync';
    $tokens = $service->exchangeCodeForTokens($code);

    $accessToken = $tokens['access_token'] ?? '';
    $refreshToken = $tokens['refresh_token'] ?? null;
    $expiresIn = (int) ($tokens['expires_in'] ?? 3600);
    $tokenExpiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
    $vault = new OAuthTokenVault();
    $storedAccessToken = $vault->encrypt((string) $accessToken);
    if ($storedAccessToken === null) {
        throw new RuntimeException('Outlook Calendar OAuth did not return an access token.');
    }
    $providerEmail = $service->fetchAccountEmail((string) $accessToken);
    $requiredScopes = ['offline_access', 'Calendars.Read', 'User.Read'];

    $existing = Database::queryOne(
        "SELECT id, refresh_token, sync_enabled FROM calendar_integrations WHERE user_id = ? AND workspace_id = ? AND provider = 'outlook'",
        [$userId, $workspaceId]
    );
    $nextSyncEnabled = $purpose === 'availability'
        ? (int) ($existing['sync_enabled'] ?? 0)
        : 1;

    if ($existing) {
        $storedRefreshToken = trim((string) $refreshToken) !== ''
            ? $vault->encrypt((string) $refreshToken)
            : $vault->encrypt($vault->decrypt($existing['refresh_token'] ?? null));
        Database::execute(
            "UPDATE calendar_integrations
             SET oauth_grant_type = 'calendar_import',
                 oauth_client_key = 'MICROSOFT_CALENDAR',
                 provider_account_email = COALESCE(NULLIF(?, ''), provider_account_email),
                 access_token = ?,
                 refresh_token = ?,
                 token_expires_at = ?,
                 granted_scopes_json = ?,
                 required_scopes_json = ?,
                 scope_status = 'verified',
                 reconnect_required = 0,
                 token_encrypted = 1,
                 last_scope_verified_at = NOW(),
                 availability_enabled = 1,
                 sync_enabled = ?,
                 sync_direction = 'to_crm',
                 updated_at = NOW()
             WHERE id = ? AND workspace_id = ?",
            [
                $providerEmail,
                $storedAccessToken,
                $storedRefreshToken,
                $tokenExpiresAt,
                json_encode($requiredScopes, JSON_UNESCAPED_SLASHES),
                json_encode($requiredScopes, JSON_UNESCAPED_SLASHES),
                $nextSyncEnabled,
                $existing['id'],
                $workspaceId,
            ]
        );
    } else {
        Database::execute(
            "INSERT INTO calendar_integrations (
                workspace_id, user_id, provider, oauth_grant_type, oauth_client_key,
                provider_account_email, access_token, refresh_token, token_expires_at,
                granted_scopes_json, required_scopes_json, scope_status, reconnect_required,
                token_encrypted, last_scope_verified_at, calendar_id, availability_enabled,
                sync_enabled, sync_direction
             ) VALUES (?, ?, 'outlook', 'calendar_import', 'MICROSOFT_CALENDAR', ?, ?, ?, ?, ?, ?, 'verified', 0, 1, NOW(), 'primary', 1, ?, 'to_crm')",
            [
                $workspaceId,
                $userId,
                $providerEmail !== '' ? $providerEmail : null,
                $storedAccessToken,
                $vault->encrypt((string) $refreshToken),
                $tokenExpiresAt,
                json_encode($requiredScopes, JSON_UNESCAPED_SLASHES),
                json_encode($requiredScopes, JSON_UNESCAPED_SLASHES),
                $purpose === 'availability' ? 0 : 1,
            ]
        );
    }

    $returnTo = trim((string) ($oauthContext['return_to'] ?? ''));
    $target = $returnTo !== '' ? $returnTo : $redirectUrl;
    $target = $appendQuery($target, ['success' => 'outlook_connected']);
    header('Location: ' . $target);
    exit;
} catch (\Exception $e) {
    header('Location: ' . $appendQuery($redirectUrl, ['error' => $e->getMessage()]));
    exit;
}
