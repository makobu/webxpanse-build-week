<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
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

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Services\PlatformImpersonationService;
use CRM\Services\PlatformSecuritySettingsService;
use CRM\Services\PlatformWorkspaceOperationsService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!PlatformWorkspaceOperationsService::isPlatformAdmin($user)) {
    header('Location: dashboard.php');
    exit;
}

function workspaceLoginRedirectToList(string $message, string $type = 'error'): void
{
    $param = $type === 'notice' ? 'notice' : 'error';
    header('Location: workspaces.php?' . $param . '=' . rawurlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    workspaceLoginRedirectToList('Invalid security token. Please try again.');
}

$workspaceId = (int) ($_POST['workspace_id'] ?? 0);
if ($workspaceId <= 0) {
    workspaceLoginRedirectToList('Choose a workspace to log in to.');
}

$reason = trim((string) ($_POST['reason'] ?? ''));
if ($reason === '') {
    $reason = 'Super Admin workspace login from workspace directory.';
}

$targetUserId = (int) ($_POST['target_user_id'] ?? 0);
$pending = [
    'actor_user_id' => (int) ($user['id'] ?? 0),
    'workspace_id' => $workspaceId,
    'target_user_id' => $targetUserId > 0 ? $targetUserId : null,
    'reason' => $reason,
    'created_at' => time(),
];

$settings = new PlatformSecuritySettingsService();
if ($settings->requiresPlatformWorkspace2FA()) {
    Session::set('pending_superadmin_workspace_login', $pending);
    header('Location: workspace_login_2fa.php');
    exit;
}

try {
    (new PlatformImpersonationService())->begin($user ?? [], $workspaceId, $targetUserId > 0 ? $targetUserId : null, $reason);
    header('Location: dashboard.php?impersonation=started');
    exit;
} catch (\Throwable $e) {
    workspaceLoginRedirectToList($e->getMessage());
}
