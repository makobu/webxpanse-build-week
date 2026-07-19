<?php
/**
 * Email Verification Page
 * Verifies user email via token from verification email
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

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$basePath = getBasePath();
$token = $_GET['token'] ?? '';
$success = false;
$error = null;

if (!empty($token)) {
    $userId = Auth::verifyEmailToken($token);
    if ($userId) {
        $success = true;
        if (Auth::check() && (int) Auth::userId() === $userId) {
            header('Location: ' . $basePath . '/dashboard.php?verified=1');
            exit;
        }
    } else {
        $error = 'Invalid or expired verification link.';
    }
} else {
    $error = 'No verification token provided.';
}

$pageTitle = 'Email Verification - ' . brandProductName();
ob_start();
?>

<div style="max-width: 400px; margin: 100px auto; padding: var(--spacing-xl); background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: center;">
    <?php if ($success): ?>
        <h1 style="color: #28a745; margin-bottom: var(--spacing-md);">Email Verified</h1>
        <p style="color: var(--charcoal-grey); margin-bottom: var(--spacing-lg);">
            Your email has been verified successfully. You can now sign in to your account.
        </p>
        <a href="<?php echo htmlspecialchars($basePath . '/login.php'); ?>" style="display: inline-block; background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border-radius: 4px; text-decoration: none; font-weight: 500;">
            Sign In
        </a>
    <?php else: ?>
        <h1 style="color: #dc3545; margin-bottom: var(--spacing-md);">Verification Failed</h1>
        <p style="color: var(--charcoal-grey); margin-bottom: var(--spacing-lg);">
            <?php echo htmlspecialchars($error); ?>
        </p>
        <a href="<?php echo htmlspecialchars($basePath . '/login.php'); ?>" style="display: inline-block; background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border-radius: 4px; text-decoration: none; font-weight: 500;">
            Back to Login
        </a>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/auth.php';
