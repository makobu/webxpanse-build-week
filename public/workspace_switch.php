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
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceSecuritySettingsService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$membershipService = new WorkspaceMembershipService();
$memberships = $membershipService->listForUser((int) ($user['id'] ?? 0));
$error = null;

function workspaceSwitchSanitizeReturnTo(?string $candidate): string
{
    $candidate = trim((string) $candidate);
    if ($candidate === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $candidate)) {
        return 'dashboard.php';
    }

    if (str_contains($candidate, "\n") || str_contains($candidate, "\r")) {
        return 'dashboard.php';
    }

    return $candidate;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $workspaceId = (int) ($_POST['workspace_id'] ?? 0);
        $membership = $membershipService->getActiveMembership($workspaceId, (int) ($user['id'] ?? 0));
        if ($membership !== null) {
            $workspaceSecurity = new WorkspaceSecuritySettingsService();
            if ($workspaceSecurity->requiresMember2FA($workspaceId) && !WorkspaceSecuritySettingsService::userHasTwoFactor((int) ($user['id'] ?? 0))) {
                WorkspaceSecuritySettingsService::setPendingSetup(
                    (int) ($user['id'] ?? 0),
                    $workspaceId,
                    $membership,
                    workspaceSwitchSanitizeReturnTo($_POST['return_to'] ?? 'dashboard.php')
                );
                header('Location: settings_2fa.php?required=workspace');
                exit;
            }
        }

        $activated = WorkspaceContext::activateWorkspaceById((int) ($user['id'] ?? 0), $workspaceId);
        if ($activated === null) {
            $error = 'You do not have access to that workspace.';
        } else {
            header('Location: ' . workspaceSwitchSanitizeReturnTo($_POST['return_to'] ?? 'dashboard.php'));
            exit;
        }
    }
}

$pageTitle = 'Switch Workspace - ' . brandProductName();
ob_start();
?>
<div style="max-width:760px;margin:0 auto;">
    <div style="margin-bottom:var(--spacing-xl);">
        <h1 style="margin-bottom:var(--spacing-sm);color:var(--midnight-black);">Switch Workspace</h1>
        <p style="color:var(--charcoal-grey);">Choose which workspace you want to work in for this session.</p>
    </div>

    <?php if ($error): ?>
        <div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:16px;border-radius:8px;margin-bottom:16px;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid var(--border-color);border-radius:12px;padding:24px;">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars((string) ($_GET['return_to'] ?? $_POST['return_to'] ?? 'dashboard.php')); ?>">
            <div style="display:grid;gap:12px;">
                <?php foreach ($memberships as $membership): ?>
                    <?php $isActive = (int) ($membership['workspace_id'] ?? 0) === (int) (Session::get('active_workspace_id') ?? 0); ?>
                    <label style="display:flex;align-items:flex-start;gap:12px;padding:14px;border:1px solid <?php echo $isActive ? 'var(--accent-blue)' : 'var(--border-color)'; ?>;border-radius:10px;cursor:pointer;">
                        <input type="radio" name="workspace_id" value="<?php echo (int) $membership['workspace_id']; ?>" <?php echo $isActive ? 'checked' : ''; ?>>
                        <span>
                            <strong style="display:block;color:var(--midnight-black);"><?php echo htmlspecialchars((string) ($membership['workspace_name'] ?? 'Workspace')); ?></strong>
                            <span style="display:block;color:var(--charcoal-grey);"><?php echo htmlspecialchars((string) ($membership['workspace_slug'] ?? '')); ?></span>
                            <small style="display:block;color:var(--charcoal-grey);margin-top:4px;">Role: <?php echo htmlspecialchars((string) ($membership['role_slug'] ?? 'viewer')); ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:18px;">
                <button type="submit" style="background:var(--accent-blue);color:#fff;border:none;border-radius:8px;padding:12px 18px;font-weight:600;cursor:pointer;">Switch Workspace</button>
            </div>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
