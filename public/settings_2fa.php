<?php
/**
 * Two-Factor Authentication Settings Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Security;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSecuritySettingsService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$pendingWorkspaceSetup = Auth::pendingWorkspace2FASetup();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$userEmail = $user['email'] ?? '';

$dbUser = Database::queryOne(
    "SELECT two_factor_enabled, two_factor_secret FROM users WHERE id = ?",
    [$userId]
);
$twoFactorEnabled = !empty($dbUser['two_factor_enabled']) && !empty($dbUser['two_factor_secret']);
$workspace2faSetupFlowActive = is_array($pendingWorkspaceSetup)
    && (int) ($pendingWorkspaceSetup['user_id'] ?? 0) === $userId
    && (int) ($pendingWorkspaceSetup['workspace_id'] ?? 0) > 0;
$pendingWorkspaceRequiresSetup = $workspace2faSetupFlowActive && !$twoFactorEnabled;
if ($workspace2faSetupFlowActive) {
    $disableSessionAutomationWorker = true;
}

$basePath = getBasePath();
$error = null;
$success = null;
$recoveryCodes = [];
$setupSecret = null;
$qrCodeUrl = null;
$workspaceSetupReturnUrl = null;

// Handle enable 2FA - step 1: generate secret and show QR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } elseif ($_POST['action'] === 'enable_2fa_setup') {
        $secretData = Auth::generate2FASecret($userEmail);
        Session::set('2fa_setup_secret', $secretData['secret']);
        $setupSecret = $secretData['secret'];
        $qrCodeUrl = $secretData['qr_code_url'];
    } elseif ($_POST['action'] === 'enable_2fa_verify') {
        $secret = Session::get('2fa_setup_secret');
        $code = $_POST['verification_code'] ?? '';
        if (empty($secret)) {
            $error = 'Session expired. Please start the setup again.';
        } elseif (empty($code)) {
            $error = 'Please enter the verification code from your authenticator app.';
        } else {
            $result = Auth::enable2FA($userId, $secret, $code);
            Session::remove('2fa_setup_secret');
            if ($result['success']) {
                $success = 'Two-factor authentication has been enabled successfully.';
                $recoveryCodes = $result['recovery_codes'] ?? [];
                $twoFactorEnabled = true;
                if (is_array($pendingWorkspaceSetup) && (int) ($pendingWorkspaceSetup['user_id'] ?? 0) === $userId) {
                    $workspaceId = (int) ($pendingWorkspaceSetup['workspace_id'] ?? 0);
                    $returnTo = (string) ($pendingWorkspaceSetup['return_to'] ?? 'dashboard.php');
                    $workspaceName = (string) ($pendingWorkspaceSetup['workspace_name'] ?? 'the workspace');
                    Auth::clearPendingWorkspace2FASetup();
                    if ($workspaceId > 0) {
                        WorkspaceContext::activateWorkspaceById($userId, $workspaceId);
                    }
                    $workspaceSetupReturnUrl = $basePath . '/' . ltrim($returnTo !== '' ? $returnTo : 'dashboard.php', '/');
                    $success .= ' You can now access ' . $workspaceName . '.';
                }
                $pendingAuthRedirect = trim((string) Session::get('pending_auth_redirect', ''));
                $basePrefix = rtrim($basePath, '/');
                if (
                    $pendingAuthRedirect !== ''
                    && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $pendingAuthRedirect)
                    && $pendingAuthRedirect[0] === '/'
                    && ($basePrefix === '' || $pendingAuthRedirect === $basePrefix || strpos($pendingAuthRedirect, $basePrefix . '/') === 0)
                ) {
                    $workspaceSetupReturnUrl = $pendingAuthRedirect;
                }
                Session::remove('pending_auth_redirect');
            } else {
                $error = $result['error'] ?? 'Failed to enable 2FA.';
            }
        }
    } elseif ($_POST['action'] === 'disable_2fa') {
        $password = $_POST['password'] ?? '';
        if (empty($password)) {
            $error = 'Please enter your password to disable 2FA.';
        } elseif (Auth::disable2FA($userId, $password)) {
            $success = 'Two-factor authentication has been disabled.';
            $twoFactorEnabled = false;
        } else {
            $error = 'Invalid password. Could not disable 2FA.';
        }
    }
}

// If we have setup secret in session (from previous step), show QR
if (!$twoFactorEnabled && Session::has('2fa_setup_secret')) {
    $secretData = Auth::generate2FASecret($userEmail);
    $setupSecret = Session::get('2fa_setup_secret');
    $google2fa = new \PragmaRX\Google2FA\Google2FA();
    $companyName = $_ENV['COMPANY_NAME'] ?? 'CRM';
    $qrCodeUrl = $google2fa->getQRCodeUrl($companyName, $userEmail, $setupSecret);
}

$pageTitle = 'Two-Factor Authentication - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/settings-ui.css?v=20260526c">

<div class="page-premium settings-page settings-aux-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Two-Factor Authentication</h1>
                <p>Add a second verification step to protect your account.</p>
            </div>
            <div class="page-header-actions">
                <a href="<?php echo htmlspecialchars($basePath . '/settings.php'); ?>" class="btn-premium-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Back to Settings
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="premium-banner premium-banner-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="premium-banner premium-banner-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($pendingWorkspaceRequiresSetup): ?>
            <div class="premium-banner" style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;">
                <?php echo htmlspecialchars((string) ($pendingWorkspaceSetup['workspace_name'] ?? 'This workspace')); ?> requires two-factor authentication before access is granted.
            </div>
        <?php endif; ?>

<?php if (!empty($recoveryCodes)): ?>
    <div class="premium-status-card settings-status-card--wide">
        <h2>Save Your Recovery Codes</h2>
        <p class="premium-result-note" style="margin-bottom: 1rem;">
            Store these codes in a safe place. Each can be used once to sign in if you lose access to your authenticator app.
        </p>
        <div class="premium-grid settings-mono-grid">
            <?php foreach ($recoveryCodes as $code): ?>
                <div class="settings-mono-cell"><?php echo htmlspecialchars($code); ?></div>
            <?php endforeach; ?>
        </div>
        <?php if ($workspaceSetupReturnUrl): ?>
            <div class="premium-inline-actions" style="margin-top: 1rem;">
                <a href="<?php echo htmlspecialchars($workspaceSetupReturnUrl); ?>" class="btn-premium-primary">
                    Continue to workspace
                </a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($twoFactorEnabled): ?>
    <div class="premium-status-card is-success settings-status-card settings-status-card--sm">
        <div class="settings-icon-heading">
            <span class="premium-status-icon"><i class="fas fa-shield-halved"></i></span>
            <div>
                <h2>2FA is enabled</h2>
                <p class="premium-result-note">
                    Your account is protected with two-factor authentication.
                </p>
            </div>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <input type="hidden" name="action" value="disable_2fa">
            <p class="premium-result-note" style="margin-bottom: 1rem;">
                To disable 2FA, enter your password below:
            </p>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn-premium-danger">
                <i class="fas fa-lock-open"></i>
                Disable Two-Factor Authentication
            </button>
        </form>
    </div>
<?php elseif ($setupSecret): ?>
    <div class="premium-status-card settings-status-card">
        <h2>Step 2: Verify your authenticator app</h2>
        <p class="premium-result-note" style="margin-bottom: 1.25rem;">
            Scan the QR code with your authenticator app (Google Authenticator, Authy, etc.), then enter the 6-digit code below.
        </p>
        <div class="settings-qr-frame">
            <?php
            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qrCodeUrl);
            ?>
            <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="QR Code">
        </div>
        <p class="premium-result-note" style="margin-bottom: 1rem;">
            Or enter this key manually: <code class="settings-manual-key"><?php echo htmlspecialchars($setupSecret); ?></code>
        </p>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <input type="hidden" name="action" value="enable_2fa_verify">
            <div class="form-group">
                <label for="verification_code">Verification code</label>
                <input type="text" id="verification_code" name="verification_code" maxlength="8" pattern="[0-9]+"
                       placeholder="000000" class="settings-verification-code">
            </div>
            <div class="premium-inline-actions">
                <button type="submit" class="btn-premium-primary">Verify and Enable 2FA</button>
                <a href="<?php echo htmlspecialchars($basePath . '/settings_2fa.php'); ?>" class="btn-premium-secondary">Cancel</a>
            </div>
        </form>
    </div>
<?php else: ?>
    <div class="premium-status-card settings-status-card settings-status-card--sm">
        <div class="settings-icon-heading">
            <span class="premium-status-icon"><i class="fas fa-mobile-screen-button"></i></span>
            <div>
                <h2>Enable Two-Factor Authentication</h2>
                <p class="premium-result-note">Start setup with an authenticator app.</p>
            </div>
        </div>
        <p class="premium-result-note" style="margin-bottom: 1.25rem;">
            Two-factor authentication adds an extra layer of security. You'll need an authenticator app (Google Authenticator, Authy, Microsoft Authenticator, etc.) on your phone.
        </p>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <input type="hidden" name="action" value="enable_2fa_setup">
            <button type="submit" class="btn-premium-primary">
                <i class="fas fa-shield-halved"></i>
                Enable Two-Factor Authentication
            </button>
        </form>
    </div>
<?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
