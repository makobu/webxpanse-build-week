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
use CRM\Services\PlatformWorkspaceOperationsService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceOwnerWelcomeService;
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

$error = trim((string) ($_GET['error'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
                'workspace_name' => (string) ($_POST['workspace_name'] ?? ''),
                'first_name' => (string) ($_POST['first_name'] ?? ''),
                'last_name' => (string) ($_POST['last_name'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'password' => (string) ($_POST['password'] ?? ''),
                'starter_token_pack' => !empty($_POST['starter_token_pack']),
            ]);
            try {
                (new WorkspaceOwnerWelcomeService())->sendForProvisionedWorkspace(
                    (int) ($result['workspace_id'] ?? 0),
                    (int) ($result['user_id'] ?? 0),
                    (int) ($user['id'] ?? 0),
                    'platform_admin_provisioning'
                );
            } catch (\Throwable $welcomeError) {
                error_log('Workspace admin provisioning welcome email failed: ' . $welcomeError->getMessage());
            }
            header('Location: workspace_admin.php?workspace_id=' . (int) ($result['workspace_id'] ?? 0) . '&notice=' . rawurlencode('Workspace provisioned successfully.'));
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Provision Workspace - ' . brandProductName();
ob_start();
?>
<style>
    .workspace-provision-shell {
        max-width: 760px;
        margin: 0 auto;
    }

    .workspace-provision-card {
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 22px;
    }

    .workspace-provision-card form {
        display: grid;
        gap: 12px;
    }

    .workspace-provision-card input:not([type="checkbox"]) {
        width: 100%;
        padding: 12px 14px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
    }
</style>

<div class="workspace-provision-shell">
    <div style="margin-bottom:1rem;">
        <a href="workspaces.php" style="color:var(--accent-blue);text-decoration:none;">&larr; Back to Workspaces</a>
    </div>

    <?php if ($error): ?>
        <div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:16px;border-radius:8px;margin-bottom:16px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="workspace-provision-card">
        <h1 style="margin:0 0 .6rem;color:var(--midnight-black);font-size:1.7rem;">Provision Workspace</h1>
        <p style="color:var(--charcoal-grey);margin:0 0 18px;">Sales-assisted provisioning uses the same service as public signup.</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
            <input name="workspace_name" required placeholder="Workspace name" value="<?php echo htmlspecialchars($_POST['workspace_name'] ?? ''); ?>">
            <input name="first_name" required placeholder="Owner first name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
            <input name="last_name" placeholder="Owner last name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
            <input type="email" name="email" required placeholder="Owner email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            <input type="password" name="password" required placeholder="Temporary owner password">
            <label style="display:flex;align-items:center;gap:10px;color:var(--midnight-black);">
                <input type="checkbox" name="starter_token_pack" value="1" <?php echo !empty($_POST['starter_token_pack']) ? 'checked' : ''; ?>>
                <span>Prepare starter AI token pack checkout</span>
            </label>
            <div style="display:flex;justify-content:flex-end;gap:.75rem;flex-wrap:wrap;margin-top:.25rem;">
                <a href="workspaces.php" class="btn-premium-secondary" style="text-decoration:none;">Cancel</a>
                <button type="submit" class="btn-premium-primary">Provision Workspace</button>
            </div>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
