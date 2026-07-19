<?php
/**
 * Reset Password Page
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

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Redirect if already logged in
if (Auth::check()) {
    header('Location: dashboard.php');
    exit;
}

$token = $_GET['token'] ?? '';
$error = null;
$success = null;
$tokenValid = false;

// Validate token
if (!empty($token)) {
    $tokenValid = Auth::validatePasswordResetToken($token) !== null;
    
    if (!$tokenValid) {
        $error = 'Invalid or expired reset token. Please request a new password reset.';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($password)) {
            $error = 'Please enter a new password.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $pwdCheck = Security::validatePasswordStrength($password);
            if (!$pwdCheck['valid']) {
                $error = implode(' ', $pwdCheck['errors']);
            }
        }
        if (empty($error)) {
            if (Auth::resetPassword($token, $password)) {
                $success = 'Your password has been reset successfully. You can now log in with your new password.';
                $tokenValid = false; // Hide form after success
            } else {
                $error = 'Failed to reset password. The token may have expired. Please request a new password reset.';
            }
        }
    }
}

$pageTitle = 'Reset Password - ' . brandProductName();
include __DIR__ . '/../views/layouts/auth.php';
?>

<div style="max-width: 400px; margin: 100px auto; padding: var(--spacing-xl); background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm); text-align: center;">Reset Password</h1>
    
    <?php if ($error): ?>
        <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div style="background: #efe; border: 1px solid #cfc; color: #3c3; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
            <?php echo htmlspecialchars($success); ?>
        </div>
        <div style="text-align: center; margin-top: var(--spacing-lg);">
            <a href="login.php" style="background: var(--accent-blue); color: white; padding: var(--spacing-md) var(--spacing-lg); border-radius: 4px; text-decoration: none; font-weight: 500; display: inline-block;">
                Go to Login
            </a>
        </div>
    <?php elseif ($tokenValid): ?>
        <p style="color: var(--charcoal-grey); text-align: center; margin-bottom: var(--spacing-lg);">
            Enter your new password below.
        </p>
        
        <form method="POST" style="display: flex; flex-direction: column; gap: var(--spacing-md);">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            
            <div>
                <label for="password" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500; color: var(--midnight-black);">New Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    required 
                    autofocus
                    minlength="8"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 16px;"
                    placeholder="At least 8 characters"
                >
                <div id="password-strength" style="margin-top: var(--spacing-xs); font-size: 12px;"></div>
            </div>
            
            <div>
                <label for="confirm_password" style="display: block; margin-bottom: var(--spacing-xs); font-weight: 500; color: var(--midnight-black);">Confirm Password</label>
                <input 
                    type="password" 
                    id="confirm_password" 
                    name="confirm_password" 
                    required 
                    minlength="8"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 16px;"
                    placeholder="Confirm your password"
                >
                <div id="password-match" style="margin-top: var(--spacing-xs); font-size: 12px;"></div>
            </div>
            
            <button 
                type="submit" 
                style="background: var(--accent-blue); color: white; padding: var(--spacing-md); border: none; border-radius: 4px; font-weight: 500; cursor: pointer; font-size: 16px;"
            >
                Reset Password
            </button>
        </form>
        
        <div style="text-align: center; margin-top: var(--spacing-lg);">
            <a href="login.php" style="color: var(--accent-blue); text-decoration: none; font-size: 14px;">
                ← Back to Login
            </a>
        </div>
    <?php else: ?>
        <div style="text-align: center; margin-top: var(--spacing-lg);">
            <a href="forgot_password.php" style="color: var(--accent-blue); text-decoration: none; font-size: 14px;">
                Request a new password reset link
            </a>
        </div>
    <?php endif; ?>
</div>

<script>
// Password strength indicator
(function() {
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    const strengthDiv = document.getElementById('password-strength');
    const matchDiv = document.getElementById('password-match');
    
    if (passwordInput && strengthDiv) {
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            let feedback = [];
            
            if (password.length >= 8) strength++;
            else feedback.push('At least 8 characters');
            
            if (password.length >= 12) strength++;
            
            if (/[a-z]/.test(password)) strength++;
            else feedback.push('lowercase letter');
            
            if (/[A-Z]/.test(password)) strength++;
            else feedback.push('uppercase letter');
            
            if (/[0-9]/.test(password)) strength++;
            else feedback.push('number');
            
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            else feedback.push('special character');
            
            if (password.length === 0) {
                strengthDiv.textContent = '';
                strengthDiv.style.color = '';
            } else if (strength <= 2) {
                strengthDiv.textContent = 'Weak' + (feedback.length > 0 ? ' - Add: ' + feedback.slice(0, 2).join(', ') : '');
                strengthDiv.style.color = '#c33';
            } else if (strength <= 4) {
                strengthDiv.textContent = 'Medium - Consider adding: ' + feedback.slice(0, 2).join(', ');
                strengthDiv.style.color = '#f90';
            } else {
                strengthDiv.textContent = 'Strong';
                strengthDiv.style.color = '#3c3';
            }
        });
    }
    
    // Password match indicator
    if (confirmInput && matchDiv) {
        function checkMatch() {
            const password = passwordInput.value;
            const confirm = confirmInput.value;
            
            if (confirm.length === 0) {
                matchDiv.textContent = '';
                matchDiv.style.color = '';
            } else if (password === confirm) {
                matchDiv.textContent = '✓ Passwords match';
                matchDiv.style.color = '#3c3';
            } else {
                matchDiv.textContent = '✗ Passwords do not match';
                matchDiv.style.color = '#c33';
            }
        }
        
        if (passwordInput) {
            passwordInput.addEventListener('input', checkMatch);
        }
        confirmInput.addEventListener('input', checkMatch);
    }
})();
</script>
