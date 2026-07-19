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
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\PlatformSecuritySettingsService;
use CRM\Services\PlatformWorkspaceDeletionService;
use CRM\Services\PlatformWorkspaceOperationsService;
use CRM\Services\WorkspaceContext;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!PlatformWorkspaceOperationsService::isPlatformAdmin($user) || !Authorization::isSuperAdmin($user)) {
    header('Location: dashboard.php');
    exit;
}

function workspaceDeleteRedirectToList(string $message, string $type = 'error'): void
{
    $param = $type === 'notice' ? 'notice' : 'error';
    header('Location: workspaces.php?' . $param . '=' . rawurlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    workspaceDeleteRedirectToList('Invalid security token. Please try again.');
}

$workspaceIds = $_POST['workspace_ids'] ?? [];
if (!is_array($workspaceIds)) {
    $workspaceIds = [$workspaceIds];
}

$workspaceIds = array_values(array_filter(array_map('intval', $workspaceIds), static fn(int $id): bool => $id > 0));
if ($workspaceIds === []) {
    workspaceDeleteRedirectToList('Choose at least one workspace to delete.');
}

$confirmation = trim((string) ($_POST['delete_confirm_text'] ?? ''));
$requiredConfirmation = count(array_unique($workspaceIds)) > 1 ? 'DELETE WORKSPACES' : 'DELETE WORKSPACE';
if ($confirmation !== $requiredConfirmation && !(count(array_unique($workspaceIds)) === 1 && $confirmation === 'DELETE WORKSPACES')) {
    workspaceDeleteRedirectToList('Type ' . $requiredConfirmation . ' to confirm workspace deletion.');
}

$reason = trim((string) ($_POST['reason'] ?? ''));
if ($reason === '') {
    $reason = 'Super Admin workspace deletion from workspace directory.';
}

$pending = [
    'actor_user_id' => (int) ($user['id'] ?? 0),
    'workspace_ids' => array_values(array_unique($workspaceIds)),
    'reason' => $reason,
    'active_workspace_id' => (int) (WorkspaceContext::currentWorkspaceId() ?? Session::get('active_workspace_id') ?? 0),
    'created_at' => time(),
];

$settings = new PlatformSecuritySettingsService();
if ($settings->requiresSuperadminWorkspace2FA()) {
    Session::set('pending_superadmin_workspace_delete', $pending);
    header('Location: workspace_delete_2fa.php');
    exit;
}

try {
    $result = (new PlatformWorkspaceDeletionService())->deleteWorkspaces(
        $pending['workspace_ids'],
        (int) $pending['actor_user_id'],
        (int) $pending['active_workspace_id'],
        $reason
    );
    $count = count((array) ($result['deleted_workspace_ids'] ?? []));
    workspaceDeleteRedirectToList($count . ' workspace' . ($count === 1 ? '' : 's') . ' deleted.', 'notice');
} catch (\Throwable $e) {
    workspaceDeleteRedirectToList($e->getMessage());
}
