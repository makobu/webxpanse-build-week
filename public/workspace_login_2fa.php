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
use CRM\Services\OperatorAuditService;
use CRM\Services\PlatformImpersonationService;
use CRM\Services\PlatformWorkspaceOperationsService;
use CRM\Session;
use PragmaRX\Google2FA\Google2FA;

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

$pending = Session::get('pending_superadmin_workspace_login');
if (!is_array($pending) || (int) ($pending['actor_user_id'] ?? 0) !== (int) ($user['id'] ?? 0) || (time() - (int) ($pending['created_at'] ?? 0)) > 600) {
    Session::remove('pending_superadmin_workspace_login');
    header('Location: workspaces.php?error=' . rawurlencode('Workspace login verification expired. Please try again.'));
    exit;
}

$workspaceId = (int) ($pending['workspace_id'] ?? 0);
$targetUserId = !empty($pending['target_user_id']) ? (int) $pending['target_user_id'] : null;
$reason = (string) ($pending['reason'] ?? 'Platform workspace login from workspace directory.');
$dbUser = Database::queryOne(
    "SELECT two_factor_enabled, two_factor_secret
     FROM users
     WHERE id = ?
     LIMIT 1",
    [(int) ($user['id'] ?? 0)]
);
$hasTotp = !empty($dbUser['two_factor_enabled']) && !empty($dbUser['two_factor_secret']);
$workspace = $workspaceId > 0
    ? Database::queryOne("SELECT name, slug FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId])
    : null;
$error = null;

if (isset($_GET['cancel'])) {
    Session::remove('pending_superadmin_workspace_login');
    header('Location: workspaces.php?notice=' . rawurlencode('Workspace login cancelled.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } elseif (!$hasTotp) {
            $error = 'Set up an authenticator app before logging in to workspaces.';
    } else {
        $code = preg_replace('/\s+/', '', (string) ($_POST['code'] ?? ''));
        if ($code === '') {
            $error = 'Please enter your authenticator code.';
        } else {
            $google2fa = new Google2FA();
            $valid = $google2fa->verifyKey((string) ($dbUser['two_factor_secret'] ?? ''), $code);
            if (!$valid) {
                $error = 'Invalid authenticator code. Please try again.';
            } else {
                try {
                    (new OperatorAuditService())->log(
                        'workspace_login_2fa_verified',
                        (int) ($user['id'] ?? 0),
                        $workspaceId,
                        $reason,
                        ['workspace_slug' => (string) ($workspace['slug'] ?? '')]
                    );
                    (new PlatformImpersonationService())->begin($user ?? [], $workspaceId, $targetUserId, $reason);
                    Session::remove('pending_superadmin_workspace_login');
                    header('Location: dashboard.php?impersonation=started');
                    exit;
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }
    }
}

$pageTitle = 'Verify Workspace Login - ' . brandProductName();
ob_start();
?>
<div style="max-width:520px;margin:3rem auto;background:#fff;border:1px solid var(--border-color);border-radius:16px;padding:2rem;">
    <h1 style="margin:0 0 .5rem;color:var(--midnight-black);font-size:1.6rem;">Verify workspace login</h1>
    <p style="margin:0 0 1.25rem;color:var(--charcoal-grey);">
        Enter your authenticator app code before logging in to
        <strong><?php echo htmlspecialchars((string) ($workspace['name'] ?? 'this workspace')); ?></strong>.
    </p>

    <?php if ($error): ?>
        <div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:1rem;border-radius:12px;margin-bottom:1rem;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if (!$hasTotp): ?>
        <div style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:1rem;border-radius:12px;margin-bottom:1rem;">
            Platform workspace login requires an authenticator app. Set up two-factor authentication before continuing.
        </div>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
            <a href="settings_2fa.php" class="btn-premium-primary">Set Up 2FA</a>
            <a href="workspace_login_2fa.php?cancel=1" class="btn-premium-secondary">Cancel</a>
        </div>
    <?php else: ?>
        <form method="POST" style="display:grid;gap:1rem;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
            <label style="display:grid;gap:.45rem;">
                <span style="font-weight:700;color:var(--midnight-black);">Authenticator code</span>
                <input type="text" name="code" maxlength="8" inputmode="numeric" autocomplete="one-time-code" autofocus
                       pattern="[0-9\s]+" placeholder="000000"
                       style="padding:.9rem;border:1px solid var(--border-color);border-radius:10px;font-size:1.35rem;letter-spacing:.25rem;text-align:center;">
            </label>
            <div style="display:flex;gap:.75rem;justify-content:flex-end;flex-wrap:wrap;">
                <a href="workspace_login_2fa.php?cancel=1" class="btn-premium-secondary">Cancel</a>
                <button type="submit" class="btn-premium-primary">Verify and Login</button>
            </div>
        </form>
    <?php endif; ?>
</div>
<script>
const codeInput = document.querySelector('input[name="code"]');
if (codeInput) {
    codeInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '');
    });
}
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
