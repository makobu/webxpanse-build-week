<?php
/**
 * Forgot Password Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
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
use CRM\Services\SMTPClient;
use CRM\Modules\RateLimiter;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Redirect if already logged in
if (Auth::check()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
$success = null;

$forgotRateLimiter = new RateLimiter('forgot_password', (int) ($_ENV['RATE_LIMIT_FORGOT_ATTEMPTS'] ?? 5), (int) ($_ENV['RATE_LIMIT_WINDOW_SECONDS'] ?? 900));
if ($forgotRateLimiter->isLimited()) {
    $error = 'Too many password reset requests. Please try again in ' . ceil($forgotRateLimiter->getRetryAfterSeconds() / 60) . ' minutes.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $forgotRateLimiter->recordAttempt();
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            $error = 'Please enter your email address.';
        } elseif (!Security::validateEmail($email)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Generate reset token (always returns null if user doesn't exist, for security)
            $token = Auth::generatePasswordResetToken($email);
            
            if ($token) {
                // Send reset email
                try {
                    $resetPath = function_exists('publicUrl')
                        ? publicUrl('reset_password.php')
                        : '/reset_password.php';
                    $resetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                        . '://' . $_SERVER['HTTP_HOST'] . $resetPath . '?token=' . urlencode($token);
                    
                    // Send email directly using SMTPClient (no contact_id needed)
                    $smtpClient = new \CRM\Services\SMTPClient();
                    $fromEmail = $smtpClient->getPreferredFromEmail('noreply@example.com') ?? 'noreply@example.com';
                    $fromName = $smtpClient->getPreferredFromName(brandProductName()) ?? brandProductName();
                    $bodyText = "You have requested to reset your password. Click the link below to reset it:\n\n" . $resetUrl . "\n\nThis link will expire in 1 hour.\n\nIf you did not request this, please ignore this email.";
                    $bodyHtml = "You have requested to reset your password. <a href=\"" . htmlspecialchars($resetUrl) . "\">Click here to reset your password</a>.<br><br>This link will expire in 1 hour.<br><br>If you did not request this, please ignore this email.";
                    $smtpClient->send(
                        $email,
                        $fromEmail,
                        $fromName,
                        'Password Reset Request',
                        $bodyText, // Plain text body
                        [], // No attachments
                        $bodyHtml // HTML body
                    );
                    
                    $success = 'If an account with that email exists, a password reset link has been sent.';
                } catch (\Exception $e) {
                    $error = 'Failed to send reset email. Please try again later.';
                }
            } else {
                // Don't reveal if user exists - show success anyway
                $success = 'If an account with that email exists, a password reset link has been sent.';
            }
        }
    }
}

$pageTitle = 'Forgot Password - ' . brandProductName();
include __DIR__ . '/../views/layouts/auth.php';
?>

<div style="max-width: 400px; margin: 100px auto; padding: var(--spacing-xl); background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm); text-align: center;">Forgot Password</h1>
    <p style="color: var(--charcoal-grey); text-align: center; margin-bottom: var(--spacing-lg);">
        Enter your email address and we'll send you a link to reset your password.
    </p>
    
    <?php if ($error): ?>
        <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div style="background: #efe; border: 1px solid #cfc; color: #3c3; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>
    
    <?php if (!$success): ?>
        <form method="POST" style="display: flex; flex-direction: column; gap: var(--spacing-md);">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            
            <div>
                <label for="email" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500; color: var(--midnight-black);">Email Address</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    required 
                    autofocus
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 16px;"
                    placeholder="your@email.com"
                >
            </div>
            
            <button 
                type="submit" 
                style="background: var(--accent-blue); color: white; padding: var(--spacing-md); border: none; border-radius: 4px; font-weight: 500; cursor: pointer; font-size: 16px;"
            >
                Send Reset Link
            </button>
        </form>
    <?php endif; ?>
    
    <div style="text-align: center; margin-top: var(--spacing-lg);">
        <a href="login.php" style="color: var(--accent-blue); text-decoration: none; font-size: 14px;">
            ← Back to Login
        </a>
    </div>
</div>
