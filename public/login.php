<?php
/**
 * Login Page
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
$loginPerfStartedAt = microtime(true);

// Set up error handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
            || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
        
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html><head><title>500 Internal Server Error</title></head><body>";
        echo "<h1>500 Internal Server Error</h1>";
        echo "<p>A fatal error occurred while loading the login page.</p>";
        if ($showDebug) {
            echo "<p><strong>Error:</strong> " . htmlspecialchars($error['message']) . "</p>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($error['file']) . ":" . $error['line'] . "</p>";
            echo "<p><strong>Type:</strong> " . $error['type'] . "</p>";
        } else {
            echo "<p>Please contact the administrator or try again later.</p>";
        }
        echo "</body></html>";
        exit;
    }
});

// Try to load autoloader with error handling
try {
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new \RuntimeException("Composer autoloader not found at: $autoloadPath. Please run 'composer install'.");
    }
    require_once $autoloadPath;
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>500 Internal Server Error</title></head><body>";
    echo "<h1>500 Internal Server Error</h1>";
    echo "<p>Failed to load required files.</p>";
    $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
        || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
        || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    if ($showDebug) {
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    }
    echo "</body></html>";
    exit;
}

// Load environment
$envFile = __DIR__ . '/../.env';
$envLoaded = false;
$envValues = [];
if (file_exists($envFile)) {
    $content = file_get_contents($envFile);
    // Handle different line endings (Windows \r\n, Unix \n, Mac \r)
    $lines = preg_split('/\r\n|\r|\n/', $content);
    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        // Skip empty lines and comments
        if (empty($line) || strpos($line, '#') === 0) continue;
        // Must have an equals sign
        if (strpos($line, '=') === false) {
            error_log("WARNING: .env line " . ($lineNum + 1) . " skipped (no = sign): " . substr($line, 0, 50));
            continue;
        }
        // Split on first = only (in case value contains =)
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            error_log("WARNING: .env line " . ($lineNum + 1) . " skipped (invalid format): " . substr($line, 0, 50));
            continue;
        }
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        // Remove quotes if present (both single and double)
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || 
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        // Don't overwrite with empty values (unless explicitly set)
        if (!empty($key)) {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
            $envValues[$key] = $value;
            $envLoaded = true;
        }
    }
    // Only log in debug mode
    if (($_ENV['APP_ENV'] ?? 'production') === 'development'
        || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
        || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL)) {
        error_log('.env loaded - Found keys: ' . implode(', ', array_keys($envValues)));
    }
} else {
    // Log that .env file was not found
    error_log('WARNING: .env file not found at: ' . $envFile);
}

// Try to load constants file with error handling
try {
    $constantsPath = __DIR__ . '/../config/constants.php';
    if (!file_exists($constantsPath)) {
        throw new \RuntimeException("Constants file not found at: $constantsPath");
    }
    require_once $constantsPath;
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>500 Internal Server Error</title></head><body>";
    echo "<h1>500 Internal Server Error</h1>";
    echo "<p>Failed to load configuration files.</p>";
    $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
        || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
        || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    if ($showDebug) {
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    }
    echo "</body></html>";
    exit;
}

use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Security;
use CRM\Modules\WorkspaceBillingSettings;
use CRM\Modules\RateLimiter;
use CRM\Services\WorkspaceContext;

try {
    Session::start();
} catch (\Exception $e) {
    error_log('Login initialization error: ' . $e->getMessage());
    $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
               || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
               || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>500 Internal Server Error</title></head><body>";
    echo "<h1>500 Internal Server Error</h1>";
    echo "<p>An error occurred while initializing the login page.</p>";
    if ($showDebug) {
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
        if (!$envLoaded) {
            echo "<p style='color: #c33;'><strong>WARNING:</strong> .env file not found or not loaded.</p>";
        }
    } else {
        echo "<p>Please contact the administrator or try again later.</p>";
    }
    echo "</body></html>";
    exit;
}

$dbInitialized = false;
$initializeLoginDatabase = static function () use (&$dbInitialized): void {
    if ($dbInitialized) {
        return;
    }
    $dbConfigPath = __DIR__ . '/../config/database.php';
    if (!file_exists($dbConfigPath)) {
        throw new \RuntimeException("Database config file not found at: $dbConfigPath");
    }
    $dbConfig = require $dbConfigPath;
    Database::init($dbConfig);
    $dbInitialized = true;
};

// Get base path for redirects (function defined in config/constants.php)
$basePath = getBasePath();
$donateUrl = publicUrl('donate.php');
$donationsEnabled = true;
try {
    $initializeLoginDatabase();
    $donationsEnabled = WorkspaceBillingSettings::donationsEnabled();
} catch (\Throwable $e) {
    $donationsEnabled = true;
}
$loginPerfEnabled = (
    isset($_REQUEST['perf_debug'])
    || (($_ENV['APP_ENV'] ?? 'production') === 'development')
    || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
    || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
);
$loginPerfMarks = [['start', $loginPerfStartedAt]];
$loginPerfMark = static function (string $label) use (&$loginPerfMarks, $loginPerfEnabled): void {
    if ($loginPerfEnabled) {
        $loginPerfMarks[] = [$label, microtime(true)];
    }
};
$loginPerfFlush = static function () use (&$loginPerfMarks, $loginPerfEnabled): void {
    if (!$loginPerfEnabled || headers_sent()) {
        return;
    }
    $parts = [];
    for ($i = 1, $count = count($loginPerfMarks); $i < $count; $i++) {
        $label = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $loginPerfMarks[$i][0]);
        $duration = max(0, ($loginPerfMarks[$i][1] - $loginPerfMarks[$i - 1][1]) * 1000);
        $parts[] = $label . ';dur=' . number_format($duration, 1, '.', '');
    }
    $total = max(0, ($loginPerfMarks[count($loginPerfMarks) - 1][1] - $loginPerfMarks[0][1]) * 1000);
    $parts[] = 'login_total;dur=' . number_format($total, 1, '.', '');
    header('Server-Timing: ' . implode(', ', $parts));
};
$requestedRedirect = trim((string) ($_REQUEST['redirect_to'] ?? ''));
$sanitizeLoginRedirect = static function (string $path, string $basePath): string {
    $path = trim($path);
    if ($path === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
        return '';
    }

    $path = str_replace(["\r", "\n"], '', $path);
    if ($path[0] !== '/') {
        $prefix = rtrim($basePath, '/');
        $path = ($prefix !== '' ? $prefix . '/' : '/') . ltrim($path, '/');
    }

    $basePrefix = rtrim($basePath, '/');
    if ($basePrefix !== '' && strpos($path, $basePrefix . '/') !== 0 && $path !== $basePrefix) {
        return '';
    }

    return $path;
};
$safeRedirect = $sanitizeLoginRedirect($requestedRedirect, $basePath);
$reauthRequested = (string) ($_REQUEST['reauth'] ?? '') === '1';
$authenticatedUserId = Auth::userId();
$reauthActive = $reauthRequested && $authenticatedUserId !== null;
$reauthEmail = $reauthActive ? strtolower(trim((string) Session::get('user_email', ''))) : '';
$error = null;
$success = null;
if ($reauthActive) {
    $success = 'Confirm your password to continue securely. Your current session remains available until verification succeeds.';
} elseif (isset($_GET['expired']) && $safeRedirect !== '') {
    $success = 'Your previous browser session expired. Please sign in again to continue where you left off.';
} elseif (isset($_GET['expired'])) {
    $success = 'Your previous browser session expired. Please sign in again.';
}

// Redirect if already logged in
if ($authenticatedUserId !== null && !$reauthActive) {
    if (WorkspaceContext::currentWorkspaceId() === null) {
        Auth::discardRememberedBrowserSession();
        $success = 'Your previous browser session expired. Please sign in again.';
    } else {
        $loginPerfMark('already_authenticated');
        $loginPerfFlush();
        Session::closeWrite();
        header('Location: ' . ($safeRedirect !== '' ? $safeRedirect : $basePath . '/dashboard.php'));
        exit;
    }
}

// Rate limiting for login
$loginRateLimiter = new RateLimiter('login', (int) ($_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? 5), (int) ($_ENV['RATE_LIMIT_WINDOW_SECONDS'] ?? 900));
if ($loginRateLimiter->isLimited()) {
    $retryAfter = $loginRateLimiter->getRetryAfterSeconds();
    $error = 'Too many failed login attempts. Please try again in ' . ceil($retryAfter / 60) . ' minutes.';
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            $error = 'Invalid security token. Please try again.';
        } else {
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';

            if ($reauthActive && strtolower(trim((string) $email)) !== $reauthEmail) {
                $error = 'Please re-authenticate with the account that is currently signed in.';
            }
            
            if ($error === null && (empty($email) || empty($password))) {
                $error = 'Please enter both email and password.';
            } elseif ($error === null) {
                // Check database connection first
                try {
                    $initializeLoginDatabase();
                    Database::getInstance();
                    $loginPerfMark('database_ready');
                } catch (\Exception $dbError) {
                    // Log detailed error for debugging (not shown to user)
                    error_log('Database connection error: ' . $dbError->getMessage());
                    error_log('.env file exists: ' . (file_exists($envFile) ? 'yes' : 'no'));
                    error_log('.env loaded: ' . ($envLoaded ? 'yes' : 'no'));
                    
                    // Show user-friendly error message
                    $errorMsg = 'Database connection error. Please check your database configuration.';
                    // Show detailed error only in development or debug mode
                    $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
                               || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
                               || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
                    if ($showDebug) {
                        $errorMsg .= '<br><small>Error: ' . htmlspecialchars($dbError->getMessage()) . '</small>';
                        if (!$envLoaded) {
                            $errorMsg .= '<br><small style="color: #c33;"><strong>.env file not found or not loaded.</strong></small>';
                        } elseif (!array_key_exists('DB_USER', $_ENV) || !array_key_exists('DB_PASS', $_ENV)) {
                            $errorMsg .= '<br><small style="color: #c33;"><strong>DB_USER or DB_PASS not found in .env file.</strong></small>';
                        }
                    }
                    $error = $errorMsg;
                }

                if ($error === null) {
                    $rememberMe = !empty($_POST['remember_me']);
                    if (Auth::login($email, $password, $rememberMe)) {
                        $loginPerfMark('auth_login');
                        $loginRateLimiter->clear();
                        if (Auth::isPending2FA()) {
                            if ($safeRedirect !== '') {
                                Session::set('pending_auth_redirect', $safeRedirect);
                            } else {
                                Session::remove('pending_auth_redirect');
                            }
                            $loginPerfFlush();
                            Session::closeWrite();
                            header('Location: ' . $basePath . '/login_2fa.php');
                            exit;
                        }
                        if (Auth::isPendingWorkspace2FASetup()) {
                            if ($safeRedirect !== '') {
                                Session::set('pending_auth_redirect', $safeRedirect);
                            } else {
                                Session::remove('pending_auth_redirect');
                            }
                            $loginPerfFlush();
                            Session::closeWrite();
                            header('Location: ' . $basePath . '/settings_2fa.php?required=workspace');
                            exit;
                        }
                        $redirectUrl = $safeRedirect !== ''
                            ? $safeRedirect
                            : (empty($basePath) ? '/dashboard.php' : rtrim($basePath, '/') . '/dashboard.php');
                        Session::remove('pending_auth_redirect');
                        $loginPerfFlush();
                        Session::closeWrite();
                        header('Location: ' . $redirectUrl);
                        exit;
                    } else {
                        $loginPerfMark('auth_failed');
                        $loginRateLimiter->recordAttempt();
                        $error = 'Invalid email or password.';
                        if ($loginRateLimiter->isLimited()) {
                            $error .= ' Too many attempts. Please try again later.';
                        }
                    }
                }
            }
        }
    } catch (\PDOException $e) {
        // Database connection or query error
        error_log('Login database error: ' . $e->getMessage() . ' | Code: ' . $e->getCode());
        $error = 'Database connection error. Please check your database configuration.';
        // Show more details only in development or debug mode
        $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
                   || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
                   || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
        if ($showDebug) {
            $error .= '<br><small>Details: ' . htmlspecialchars($e->getMessage()) . '</small>';
            if ($e->getCode()) {
                $error .= '<br><small>Error Code: ' . htmlspecialchars($e->getCode()) . '</small>';
            }
            $error .= '<br><small>File: ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</small>';
        }
    } catch (\Exception $e) {
        // Other errors
        error_log('Login error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
        $error = 'An error occurred during login. Please try again.';
        // Show more details only in development or debug mode
        $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
                   || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
                   || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
        if ($showDebug) {
            $error .= '<br><small>Error: ' . htmlspecialchars($e->getMessage()) . '</small>';
            $error .= '<br><small>File: ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</small>';
        }
    }
}

$pageTitle = 'Login - ' . brandProductName();
$authBackgroundDesktopUrl = assetUrl('images/login-bg-desktop.jpg');
$authBackgroundMobileUrl = assetUrl('images/login-bg-mobile.jpg');
$authBackgroundPreviewUrl = assetUrl('images/login-bg-preview.jpg');
ob_start();
?>

<style>
    body {
        background: var(--auth-bg-image) center center / cover no-repeat fixed, var(--auth-bg-fallback) !important;
        position: relative;
        overflow-x: hidden;
        overflow-y: auto;
    }

    body.pink {
        background: var(--auth-bg-image) center center / cover no-repeat fixed, var(--auth-bg-fallback) !important;
    }

    /* Enhanced multi-layer sparkle field on upper half */
    .starfield,
    .starfield::before,
    .starfield::after {
        content: '';
        position: fixed;
        left: 0;
        top: 0;
        width: 100%;
        height: 56vh;
        pointer-events: none;
        z-index: 0;
    }

    .starfield {
        background:
            radial-gradient(circle at 4% 18%, rgba(255, 255, 255, 0.55) 0 1.4px, transparent 6px),
            radial-gradient(circle at 10% 9%, rgba(255, 255, 255, 0.46) 0 1.2px, transparent 6px),
            radial-gradient(circle at 16% 30%, rgba(255, 255, 255, 0.5) 0 1.3px, transparent 6px),
            radial-gradient(circle at 23% 12%, rgba(255, 255, 255, 0.58) 0 1.4px, transparent 6px),
            radial-gradient(circle at 29% 27%, rgba(255, 255, 255, 0.52) 0 1.3px, transparent 6px),
            radial-gradient(circle at 36% 10%, rgba(255, 255, 255, 0.62) 0 1.4px, transparent 6px),
            radial-gradient(circle at 43% 24%, rgba(255, 255, 255, 0.49) 0 1.2px, transparent 6px),
            radial-gradient(circle at 50% 8%, rgba(255, 255, 255, 0.56) 0 1.4px, transparent 6px),
            radial-gradient(circle at 57% 26%, rgba(255, 255, 255, 0.53) 0 1.3px, transparent 6px),
            radial-gradient(circle at 64% 11%, rgba(255, 255, 255, 0.6) 0 1.4px, transparent 6px),
            radial-gradient(circle at 71% 29%, rgba(255, 255, 255, 0.47) 0 1.2px, transparent 6px),
            radial-gradient(circle at 78% 12%, rgba(255, 255, 255, 0.58) 0 1.4px, transparent 6px),
            radial-gradient(circle at 85% 25%, rgba(255, 255, 255, 0.5) 0 1.3px, transparent 6px),
            radial-gradient(circle at 92% 9%, rgba(255, 255, 255, 0.62) 0 1.4px, transparent 6px),
            radial-gradient(circle at 97% 22%, rgba(255, 255, 255, 0.5) 0 1.2px, transparent 6px);
        animation: starsDrift 13s ease-in-out infinite alternate;
        filter: drop-shadow(0 0 10px rgba(255, 255, 255, 0.42));
    }

    .starfield::before {
        content: '';
        background:
            radial-gradient(circle at 2% 7%, rgba(255, 255, 255, 0.86) 0 1px, transparent 4px),
            radial-gradient(circle at 7% 24%, rgba(255, 255, 255, 0.78) 0 1px, transparent 4px),
            radial-gradient(circle at 14% 14%, rgba(255, 255, 255, 0.88) 0 1px, transparent 4px),
            radial-gradient(circle at 21% 33%, rgba(255, 255, 255, 0.72) 0 1px, transparent 4px),
            radial-gradient(circle at 28% 17%, rgba(255, 255, 255, 0.82) 0 1px, transparent 4px),
            radial-gradient(circle at 35% 6%, rgba(255, 255, 255, 0.92) 0 1px, transparent 4px),
            radial-gradient(circle at 41% 29%, rgba(255, 255, 255, 0.74) 0 1px, transparent 4px),
            radial-gradient(circle at 49% 13%, rgba(255, 255, 255, 0.86) 0 1px, transparent 4px),
            radial-gradient(circle at 56% 32%, rgba(255, 255, 255, 0.7) 0 1px, transparent 4px),
            radial-gradient(circle at 63% 18%, rgba(255, 255, 255, 0.8) 0 1px, transparent 4px),
            radial-gradient(circle at 70% 8%, rgba(255, 255, 255, 0.88) 0 1px, transparent 4px),
            radial-gradient(circle at 76% 27%, rgba(255, 255, 255, 0.74) 0 1px, transparent 4px),
            radial-gradient(circle at 83% 15%, rgba(255, 255, 255, 0.84) 0 1px, transparent 4px),
            radial-gradient(circle at 89% 31%, rgba(255, 255, 255, 0.72) 0 1px, transparent 4px),
            radial-gradient(circle at 95% 10%, rgba(255, 255, 255, 0.9) 0 1px, transparent 4px);
        animation: starsTwinkle 6.2s ease-in-out infinite;
    }

    .starfield::after {
        content: '';
        background:
            radial-gradient(circle at 14% 12%, rgba(255, 255, 255, 0.95) 0 1.6px, transparent 6px),
            radial-gradient(circle at 36% 22%, rgba(255, 255, 255, 0.9) 0 1.5px, transparent 6px),
            radial-gradient(circle at 58% 14%, rgba(255, 255, 255, 0.92) 0 1.7px, transparent 6px),
            radial-gradient(circle at 81% 11%, rgba(255, 255, 255, 0.9) 0 1.6px, transparent 6px),
            radial-gradient(circle at 93% 27%, rgba(255, 255, 255, 0.86) 0 1.4px, transparent 6px);
        animation: starsFlash 4.8s ease-in-out infinite;
        filter: drop-shadow(0 0 14px rgba(255, 255, 255, 0.5));
    }

    @keyframes starsDrift {
        0% {
            opacity: 0.5;
            transform: translate3d(0, 0, 0) scale(1);
        }
        50% {
            opacity: 0.8;
            transform: translate3d(0, -3px, 0) scale(1.02);
        }
        100% {
            opacity: 0.58;
            transform: translate3d(0, 2px, 0) scale(1);
        }
    }

    @keyframes starsTwinkle {
        0%, 100% { opacity: 0.3; transform: scale(1); }
        20% { opacity: 0.65; transform: scale(1.05); }
        45% { opacity: 0.42; transform: scale(0.98); }
        70% { opacity: 0.78; transform: scale(1.08); }
    }

    @keyframes starsFlash {
        0%, 100% { opacity: 0.18; }
        30% { opacity: 0.62; }
        52% { opacity: 0.24; }
        76% { opacity: 0.58; }
    }

    .auth-page-shell {
        width: 420px;
        max-width: calc(100vw - 56px);
        display: flex;
        flex-direction: column;
        align-items: stretch;
        position: relative;
        z-index: 2;
        animation: fadeSlideIn 0.6s ease;
    }

    .auth-page-shell .container {
        width: 100%;
        box-sizing: border-box;
    }

    .container {
        width: 360px;
        padding: 30px;
        border-radius: 25px;
        background: var(--bg);
        box-shadow: 12px 12px 30px var(--shadow-dark), -12px -12px 30px var(--shadow-light);
        transition: all 0.6s ease;
        position: relative;
        z-index: 2;
    }

    @keyframes fadeSlideIn {
        from {
            opacity: 0;
            transform: translateY(20px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .back-to-home {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--text);
        text-decoration: none;
        font-size: 13px;
        opacity: 0.8;
        margin-bottom: 16px;
        transition: color 0.2s ease, opacity 0.2s ease;
    }

    .back-to-home:hover {
        color: var(--accent);
        opacity: 1;
    }

    .back-to-home i {
        font-size: 12px;
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
        box-shadow: inset 5px 5px 10px var(--shadow-dark),
                    inset -5px -5px 10px var(--shadow-light);
        padding: 14px 16px;
        transition: box-shadow 0.3s ease;
    }

    .input-group:hover {
        box-shadow: 0 0 0 3px var(--accent), inset 5px 5px 10px var(--shadow-dark), inset -5px -5px 10px var(--shadow-light);
    }

    .input-group input {
        width: 100%;
        border: none;
        background: none;
        outline: none;
        font-size: 16px;
        color: #333;
        padding-top: 8px;
    }

    .password-group input {
        padding-right: 58px;
    }

    .input-group .password-toggle {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        width: auto;
        min-width: 0;
        margin: 0;
        border: none;
        box-shadow: none;
        appearance: none;
        background: none;
        color: var(--accent);
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        padding: 4px 6px;
        border-radius: 6px;
        line-height: 1;
        z-index: 2;
    }

    .input-group .password-toggle:hover {
        background: rgba(0, 0, 0, 0.06);
    }

    .input-group label {
        position: absolute;
        top: 16px;
        left: 16px;
        font-size: 14px;
        color: #999;
        transition: 0.3s ease;
        pointer-events: none;
    }

    .input-group input:focus + label,
    .input-group input:not(:placeholder-shown):valid + label,
    .input-group input:not(:placeholder-shown) + label {
        top: 4px;
        font-size: 11px;
        color: var(--accent);
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
        box-shadow: 5px 5px 10px var(--shadow-dark),
                    -5px -5px 10px var(--shadow-light);
        cursor: pointer;
        transition: background 0.3s ease, transform 0.2s ease;
        margin-top: 10px;
    }

    button.neumorphic-btn:hover {
        background: #7a93a8;
        transform: scale(1.02);
    }

    button.neumorphic-btn:active {
        transform: scale(0.98);
        box-shadow: inset 5px 5px 10px var(--shadow-dark),
                    inset -5px -5px 10px var(--shadow-light);
    }

    button.neumorphic-btn:disabled,
    button.neumorphic-btn[aria-busy="true"] {
        cursor: wait;
        opacity: 0.78;
        transform: none;
    }

    .donate-cta-gold {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        width: 100%;
        box-sizing: border-box;
        margin-top: 14px;
        padding: 13px 16px;
        border: 1px solid rgba(146, 64, 14, 0.16);
        border-radius: 12px;
        background: linear-gradient(145deg, #fde68a, #f59e0b);
        color: #3f2705;
        font-size: 14px;
        font-weight: 900;
        text-decoration: none;
        box-shadow:
            5px 5px 12px rgba(163, 177, 198, 0.48),
            -5px -5px 12px rgba(255, 255, 255, 0.58),
            inset 0 1px 0 rgba(255, 255, 255, 0.55);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .donate-cta-gold:hover,
    .donate-cta-gold:focus-visible {
        transform: translateY(-1px);
        outline: 3px solid rgba(245, 158, 11, 0.24);
        outline-offset: 3px;
        box-shadow:
            7px 7px 16px rgba(163, 177, 198, 0.54),
            -6px -6px 14px rgba(255, 255, 255, 0.64),
            inset 0 1px 0 rgba(255, 255, 255, 0.62);
    }

    .error-message {
        color: #ff4757;
        font-size: 12px;
        margin-top: 5px;
        display: block;
        padding-left: 5px;
        background: rgba(255, 71, 87, 0.1);
        padding: 8px;
        border-radius: 8px;
        margin-top: 8px;
        box-shadow: inset 2px 2px 5px rgba(255, 71, 87, 0.2);
    }

    .success-message {
        color: #4caf50;
        font-size: 12px;
        margin-top: 5px;
        display: block;
        padding-left: 5px;
        background: rgba(76, 175, 80, 0.1);
        padding: 8px;
        border-radius: 8px;
        margin-top: 8px;
        margin-bottom: 20px;
        box-shadow: inset 2px 2px 5px rgba(76, 175, 80, 0.2);
    }

    .link-text {
        text-align: center;
        font-size: 14px;
        margin-top: 20px;
        color: var(--text);
    }

    .link-text a {
        color: var(--accent);
        cursor: pointer;
        text-decoration: none;
        font-weight: 600;
        margin-left: 5px;
        transition: color 0.3s ease;
    }

    .link-text a:hover {
        text-decoration: underline;
    }

    .demo-workspace-link {
        width: fit-content;
        max-width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin: 14px auto 0;
        padding: 8px 12px;
        border: 1px solid rgba(94, 127, 151, 0.22);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.48);
        box-shadow:
            3px 3px 9px rgba(163, 177, 198, 0.26),
            -3px -3px 9px rgba(255, 255, 255, 0.42),
            inset 0 1px 0 rgba(255, 255, 255, 0.66);
        color: #4f6f89;
        font-size: 13px;
        font-weight: 700;
        line-height: 1.2;
        text-decoration: none;
        animation: demoLinkGlow 4.8s ease-in-out infinite;
        transition: color 0.2s ease, background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
    }

    .demo-workspace-link:hover,
    .demo-workspace-link:focus-visible {
        color: #24445f;
        border-color: rgba(79, 111, 137, 0.38);
        background: rgba(255, 255, 255, 0.72);
        box-shadow:
            5px 5px 12px rgba(163, 177, 198, 0.3),
            -5px -5px 12px rgba(255, 255, 255, 0.54),
            inset 0 1px 0 rgba(255, 255, 255, 0.72);
        transform: translateY(-1px);
    }

    .demo-workspace-link:focus-visible {
        outline: 3px solid rgba(157, 178, 191, 0.38);
        outline-offset: 3px;
    }

    .demo-workspace-link .demo-workspace-link-arrow {
        font-size: 11px;
        color: #5f7f97;
        animation: demoLinkArrowNudge 1.8s ease-in-out infinite;
    }

    @keyframes demoLinkGlow {
        0%, 100% {
            box-shadow:
                3px 3px 9px rgba(163, 177, 198, 0.24),
                -3px -3px 9px rgba(255, 255, 255, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.62);
        }
        50% {
            box-shadow:
                4px 4px 11px rgba(163, 177, 198, 0.3),
                -4px -4px 11px rgba(255, 255, 255, 0.5),
                0 0 0 4px rgba(157, 178, 191, 0.12),
                inset 0 1px 0 rgba(255, 255, 255, 0.72);
        }
    }

    @keyframes demoLinkArrowNudge {
        0%, 100% {
            transform: translateX(0);
            opacity: 0.72;
        }
        50% {
            transform: translateX(3px);
            opacity: 1;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .demo-workspace-link,
        .demo-workspace-link .demo-workspace-link-arrow {
            animation: none;
        }
    }

    .workspace-setup-card {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 12px;
        align-items: center;
        margin-top: 16px;
        padding: 14px;
        border: 1px solid rgba(122, 147, 168, 0.38);
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.68);
        box-shadow: inset 1px 1px 0 rgba(255, 255, 255, 0.72),
                    5px 5px 12px rgba(163, 177, 198, 0.36),
                    -5px -5px 12px rgba(255, 255, 255, 0.5);
        color: var(--text);
        text-decoration: none;
        transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease, background 0.22s ease;
    }

    .workspace-setup-card:hover {
        transform: translateY(-1px);
        border-color: rgba(122, 147, 168, 0.55);
        background: rgba(255, 255, 255, 0.82);
        box-shadow: 7px 7px 16px rgba(163, 177, 198, 0.42),
                    -7px -7px 16px rgba(255, 255, 255, 0.58);
    }

    .workspace-setup-icon {
        width: 38px;
        height: 38px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 13px;
        background: #8fa8b8;
        color: #fff;
        box-shadow: 4px 4px 10px rgba(163, 177, 198, 0.52),
                    -3px -3px 8px rgba(255, 255, 255, 0.55);
    }

    .workspace-setup-copy {
        min-width: 0;
    }

    .workspace-setup-title {
        display: block;
        color: #334155;
        font-size: 14px;
        font-weight: 700;
        line-height: 1.25;
    }

    .workspace-setup-subtitle {
        display: block;
        margin-top: 3px;
        color: var(--text);
        font-size: 12px;
        line-height: 1.35;
        opacity: 0.82;
    }

    .workspace-setup-arrow {
        width: 28px;
        height: 28px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        color: var(--accent);
        background: rgba(236, 240, 243, 0.74);
        box-shadow: inset 2px 2px 5px rgba(163, 177, 198, 0.28),
                    inset -2px -2px 5px rgba(255, 255, 255, 0.56);
    }

    .footer-links {
        margin-top: 26px;
        text-align: center;
        color: var(--text);
        font-size: 12px;
        opacity: 0.7;
        padding-top: 18px;
        border-top: 1px solid rgba(0, 0, 0, 0.1);
    }

    .footer-links a {
        color: var(--text);
        text-decoration: none;
        margin: 0 5px;
    }

    .footer-links a:hover {
        color: var(--accent);
        text-decoration: underline;
    }

    @media (max-width: 480px) {
        .container {
            padding: 20px;
        }

        .workspace-setup-card {
            grid-template-columns: auto minmax(0, 1fr);
        }

        .workspace-setup-arrow {
            display: none;
        }
        
    }

    .container::before {
        content: '';
        position: absolute;
        top: -10px;
        left: -10px;
        right: -10px;
        bottom: -10px;
        border-radius: 35px;
        background: linear-gradient(145deg, var(--shadow-light), var(--shadow-dark));
        z-index: -1;
        opacity: 0.5;
    }
</style>

<div class="starfield" aria-hidden="true"></div>
<div class="auth-page-shell">
    <div class="container">
        <div class="form-wrapper">
            <a href="<?php echo htmlspecialchars($basePath . '/'); ?>" class="back-to-home" title="Back to landing page"><i class="fas fa-arrow-left"></i> Back to home</a>
            <h2>Welcome Back</h2>
            <p class="subtitle"><?php echo htmlspecialchars(brandTaglinePrimary()); ?><br><span style="font-size: 12px; opacity: 0.85;"><?php echo htmlspecialchars(brandTaglineSecondary()); ?></span></p>

            <?php if ($error): ?>
                <div class="error-message">
                    <?php echo $error; // Allow HTML for detailed error messages ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-message">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <?php if ($safeRedirect !== ''): ?>
                    <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($safeRedirect); ?>">
                <?php endif; ?>
                <?php if ($reauthActive): ?>
                    <input type="hidden" name="reauth" value="1">
                <?php endif; ?>

                <div class="input-group">
                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                        <?php echo $reauthActive ? 'readonly' : 'autofocus'; ?>
                        value="<?php echo htmlspecialchars($reauthActive ? $reauthEmail : ($_POST['email'] ?? '')); ?>"
                    >
                    <label for="email">Email Address</label>
                </div>

                <div class="input-group password-group">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        <?php echo $reauthActive ? 'autofocus' : ''; ?>
                    >
                    <label for="password">Password</label>
                    <button type="button" class="password-toggle" id="passwordToggle" aria-controls="password" aria-label="Show password">Show</button>
                </div>

                <div style="display:flex; align-items:center; justify-content:space-between; margin: 4px 4px 12px;">
                    <label for="remember_me" style="display:flex; align-items:center; gap:8px; font-size:13px; color: var(--text); cursor:pointer;">
                        <input
                            type="checkbox"
                            id="remember_me"
                            name="remember_me"
                            value="1"
                            <?php echo !empty($_POST['remember_me']) ? 'checked' : ''; ?>
                        >
                        Keep me signed in on this browser
                    </label>
                </div>

                <button type="submit" class="neumorphic-btn" id="loginSubmitButton" data-ready-label="Sign In" data-loading-label="Signing in...">Sign In</button>
            </form>

            <a href="demo.php" class="demo-workspace-link" aria-label="Preview the demo workspace">
                <i class="fas fa-eye" aria-hidden="true"></i>
                <span>Preview the demo workspace</span>
                <i class="fas fa-arrow-right demo-workspace-link-arrow" aria-hidden="true"></i>
            </a>

            <a href="signup.php" class="workspace-setup-card" aria-label="Set up your company workspace">
                <span class="workspace-setup-icon" aria-hidden="true"><i class="fas fa-building"></i></span>
                <span class="workspace-setup-copy">
                    <span class="workspace-setup-title">Set up your company workspace</span>
                    <span class="workspace-setup-subtitle">Create the owner account, Compass Free workspace, and onboarding for your team.</span>
                </span>
                <span class="workspace-setup-arrow" aria-hidden="true"><i class="fas fa-arrow-right"></i></span>
            </a>

            <div class="footer-links">
                <p style="margin-bottom: 10px;">Already on a team? Ask your administrator to invite you.</p>
                <p>
                    <a href="privacy-policy.php">Privacy Policy</a> |
                    <a href="cookie-policy.php">Cookie Policy</a> |
                    <a href="terms-of-service.php">Terms of Service</a>
                </p>
            </div>
        </div>
    </div>

    <?php if ($donationsEnabled): ?>
    <a href="<?php echo htmlspecialchars($donateUrl); ?>" class="donate-cta-gold" data-public-donate-cta>
        <i class="fas fa-hand-holding-heart" aria-hidden="true"></i>
        Support Startup AI Access
    </a>
    <?php endif; ?>
</div>

<script>
    // Floating label functionality
    document.querySelectorAll('.input-group input').forEach(input => {
        // Check if input has value on load
        if (input.value) {
            input.classList.add('has-value');
        }

        input.addEventListener('focus', function() {
            this.parentElement.style.boxShadow = '0 0 0 3px var(--accent), inset 5px 5px 10px var(--shadow-dark), inset -5px -5px 10px var(--shadow-light)';
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.style.boxShadow = 'inset 5px 5px 10px var(--shadow-dark), inset -5px -5px 10px var(--shadow-light)';
        });

        input.addEventListener('input', function() {
            if (this.value) {
                this.classList.add('has-value');
            } else {
                this.classList.remove('has-value');
            }
        });
    });

    // Auto-focus email field on page load
    window.addEventListener('load', () => {
        const emailInput = document.getElementById('email');
        if (emailInput && !emailInput.value) {
            emailInput.focus();
        }
    });

    // Toggle password visibility for easier login on shared devices.
    const passwordInput = document.getElementById('password');
    const passwordToggle = document.getElementById('passwordToggle');
    if (passwordInput && passwordToggle) {
        passwordToggle.addEventListener('click', () => {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            passwordToggle.textContent = isHidden ? 'Hide' : 'Show';
            passwordToggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    }

    const loginForm = document.getElementById('loginForm');
    const loginSubmitButton = document.getElementById('loginSubmitButton');
    if (loginForm && loginSubmitButton) {
        loginForm.addEventListener('submit', () => {
            loginSubmitButton.disabled = true;
            loginSubmitButton.setAttribute('aria-busy', 'true');
            loginSubmitButton.textContent = loginSubmitButton.dataset.loadingLabel || 'Signing in...';
        });
    }
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/auth.php';
?>
