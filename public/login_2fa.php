<?php
/**
 * Two-Factor Authentication Verification Page
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

$dbConfig = require __DIR__ . '/../config/database.php';
Database::init($dbConfig);
Session::start();

$basePath = getBasePath();
$pendingAuthRedirect = trim((string) Session::get('pending_auth_redirect', ''));
$safePendingAuthRedirect = static function (string $path, string $basePath): string {
    $path = trim(str_replace(["\r", "\n"], '', $path));
    if ($path === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
        return '';
    }
    if ($path[0] !== '/') {
        $path = rtrim($basePath, '/') . '/' . ltrim($path, '/');
    }
    $basePrefix = rtrim($basePath, '/');
    if ($basePrefix !== '' && $path !== $basePrefix && strpos($path, $basePrefix . '/') !== 0) {
        return '';
    }
    return $path;
};
$pendingAuthRedirect = $safePendingAuthRedirect($pendingAuthRedirect, $basePath);

// Redirect to login if not pending 2FA
if (!Auth::isPending2FA()) {
    header('Location: ' . $basePath . '/login.php');
    exit;
}

// Redirect if already fully logged in
if (Auth::check()) {
    Session::remove('pending_auth_redirect');
    header('Location: ' . ($pendingAuthRedirect !== '' ? $pendingAuthRedirect : $basePath . '/dashboard.php'));
    exit;
}

$error = null;
$codeHasError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $code = $_POST['code'] ?? '';
        if (empty($code)) {
            $error = 'Please enter your verification code.';
            $codeHasError = true;
        } elseif (Auth::verify2FA($code)) {
            if (Auth::isPendingWorkspace2FASetup()) {
                header('Location: ' . $basePath . '/settings_2fa.php?required=workspace');
                exit;
            }
            Session::remove('pending_auth_redirect');
            header('Location: ' . ($pendingAuthRedirect !== '' ? $pendingAuthRedirect : $basePath . '/dashboard.php'));
            exit;
        } else {
            $error = 'That code is incorrect. Check your authenticator app and try again, or use a recovery code.';
            $codeHasError = true;
        }
    }
}

// Handle "Cancel" - clear pending 2FA and go back to login
if (isset($_GET['cancel'])) {
    Auth::clearPending2FA();
    Session::remove('pending_auth_redirect');
    header('Location: ' . $basePath . '/login.php');
    exit;
}

$rememberPending = Session::get('pending_2fa_remember') == 1;

$pageTitle = 'Two-Factor Authentication - ' . brandProductName();
ob_start();
?>

<style>
    .container {
        width: 360px;
        padding: 30px;
        border-radius: 25px;
        background: var(--bg);
        box-shadow: 12px 12px 30px var(--shadow-dark), -12px -12px 30px var(--shadow-light);
        transition: all 0.6s ease;
        animation: fadeSlideIn 0.6s ease;
        position: relative;
    }
    .form-wrapper h2 {
        text-align: center;
        margin-bottom: 25px;
        color: var(--text);
        font-size: 28px;
        font-weight: 600;
    }
    .form-wrapper p.subtitle {
        text-align: center;
        color: var(--text);
        opacity: 0.7;
        margin-bottom: 30px;
        font-size: 14px;
    }
    .input-group {
        position: relative;
        margin-bottom: 20px;
        border-radius: 12px;
        background: var(--input);
        box-shadow: inset 5px 5px 10px var(--shadow-dark), inset -5px -5px 10px var(--shadow-light);
        padding: 14px 16px;
    }
    .input-group.has-error {
        background: rgba(220, 38, 38, 0.06);
        box-shadow: inset 0 0 0 2px #dc2626, inset 5px 5px 10px var(--shadow-dark), inset -5px -5px 10px var(--shadow-light);
    }
    .input-group input {
        width: 100%;
        border: none;
        background: none;
        outline: none;
        font-size: 20px;
        letter-spacing: 6px;
        text-align: center;
        color: #333;
    }
    button.neumorphic-btn {
        width: 100%;
        padding: 14px;
        border: none;
        border-radius: 12px;
        background: var(--accent);
        color: white;
        font-weight: bold;
        font-size: 16px;
        cursor: pointer;
        margin-top: 10px;
    }
    button.neumorphic-btn:hover { background: #7a93a8; }
    .error-message {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        color: #991b1b;
        font-size: 13px;
        font-weight: 600;
        line-height: 1.45;
        padding: 10px 12px;
        border: 1px solid #fecaca;
        border-radius: 10px;
        margin: -8px 0 15px;
        background: #fef2f2;
    }
    .error-message::before {
        content: "!";
        flex: 0 0 20px;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: #dc2626;
        color: #fff;
        font-size: 13px;
        line-height: 20px;
        text-align: center;
    }
    .link-text {
        text-align: center;
        font-size: 14px;
        margin-top: 20px;
    }
    .link-text a {
        color: var(--accent);
        text-decoration: none;
        font-weight: 600;
    }
</style>

<div class="container">
    <div class="form-wrapper">
        <h2>Two-Factor Authentication</h2>
        <p class="subtitle">
            Enter the 6-digit code from your authenticator app
            <?php if ($rememberPending): ?>
                <br><span style="font-size: 12px; opacity: 0.85;">This browser will stay signed in after verification.</span>
            <?php endif; ?>
        </p>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <div class="input-group<?php echo $codeHasError ? ' has-error' : ''; ?>">
                <input type="text" name="code" id="code" maxlength="8" placeholder="000000" 
                       pattern="[0-9\s]+" inputmode="numeric" autocomplete="one-time-code" autofocus
                       <?php if ($codeHasError): ?>aria-invalid="true" aria-describedby="code-error"<?php endif; ?>
                       value="<?php echo htmlspecialchars($_POST['code'] ?? ''); ?>">
            </div>
            <?php if ($error): ?>
                <div id="code-error" class="error-message" role="alert" aria-live="assertive"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <button type="submit" class="neumorphic-btn">Verify</button>
        </form>

        <div class="link-text">
            <a href="<?php echo htmlspecialchars($basePath . '/login_2fa.php?cancel=1'); ?>">Cancel and sign in again</a>
        </div>
    </div>
</div>

<script>
document.getElementById('code').addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '');
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/auth.php';
