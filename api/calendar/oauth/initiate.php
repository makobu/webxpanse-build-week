<?php
/**
 * Calendar OAuth - Initiate
 * Redirects to Google or Microsoft consent
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
use CRM\Services\GoogleCalendarService;
use CRM\Services\GoogleOAuthScopeCatalog;
use CRM\Services\WorkspaceConnectService;

Database::init(require __DIR__ . '/../../../config/database.php');
Session::start();

$calendarSetupUrl = static function (array $params = []): string {
    $query = array_merge([
        'module' => 'calendar_meetings',
        'setup_tab' => 'calendar',
    ], $params);

    return getBasePath() . '/workspace_skills.php?' . http_build_query($query) . '#setup';
};

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$provider = $_GET['provider'] ?? 'google';
if (!in_array($provider, ['google', 'outlook'])) {
    header('Location: ' . $calendarSetupUrl(['error' => 'invalid_provider']));
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$purpose = (string) ($_GET['purpose'] ?? 'sync');
$purpose = in_array($purpose, ['availability', 'sync', 'both'], true) ? $purpose : 'sync';
$returnTo = trim((string) ($_GET['return_to'] ?? $calendarSetupUrl()));
if ($returnTo !== '' && (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//') || str_contains($returnTo, "\n"))) {
    $returnTo = $calendarSetupUrl();
}

try {
    $workspaceId = (new WorkspaceConnectService())->requireCalendarConnectionAccess($user);
} catch (\Throwable $e) {
    header('Location: ' . $calendarSetupUrl(['error' => $e->getMessage()]));
    exit;
}

if ($provider === 'google') {
    $service = new GoogleCalendarService();
    $grant = GoogleOAuthScopeCatalog::normalizeGrantType((string) ($_GET['grant'] ?? GoogleOAuthScopeCatalog::GRANT_CALENDAR_IMPORT));
    if (!in_array($grant, [GoogleOAuthScopeCatalog::GRANT_CALENDAR_IMPORT, GoogleOAuthScopeCatalog::GRANT_CALENDAR_WRITE], true)) {
        $grant = GoogleOAuthScopeCatalog::GRANT_CALENDAR_IMPORT;
    }
    $authUrl = $service->getAuthUrl($userId, $grant, [
        'workspace_id' => $workspaceId,
        'purpose' => $purpose,
        'return_to' => $returnTo,
    ]);
    header('Location: ' . $authUrl);
    exit;
}

if ($provider === 'outlook') {
    $service = new \CRM\Services\OutlookCalendarService();
    $authUrl = $service->getAuthUrl($userId, [
        'workspace_id' => $workspaceId,
        'purpose' => $purpose,
        'return_to' => $returnTo,
    ]);
    header('Location: ' . $authUrl);
    exit;
}
