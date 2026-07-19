<?php
/**
 * Front Controller
 * 
 * Entry point for all requests
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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
        echo "<p>A fatal error occurred while loading the front controller.</p>";
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

// #region agent log
$logFile = __DIR__ . '/../.cursor/debug.log';
$logEntry = json_encode([
    'id' => 'log_' . time() . '_' . uniqid(),
    'timestamp' => round(microtime(true) * 1000),
    'location' => 'index.php:8',
    'message' => 'Entry point loaded',
    'data' => ['path' => $_SERVER['REQUEST_URI'] ?? '/'],
    'sessionId' => 'debug-session',
    'runId' => 'run1',
    'hypothesisId' => 'D'
]) . "\n";
@file_put_contents($logFile, $logEntry, FILE_APPEND);
// #endregion

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

// Load environment variables
$envFile = __DIR__ . '/../.env';
$envLoaded = false;
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Remove quotes if present
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || 
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
        $envLoaded = true;
    }
} else {
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

// Import classes (after autoloader and constants are loaded)
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Services\WebhookService;
use CRM\Services\WorkflowTriggerService;

// Try to initialize webhook service and database with error handling
try {
    // Initialize database first (required for services)
    $dbConfigPath = __DIR__ . '/../config/database.php';
    if (!file_exists($dbConfigPath)) {
        throw new \RuntimeException("Database config file not found at: $dbConfigPath");
    }
    $dbConfig = require $dbConfigPath;
    Database::init($dbConfig);
    
    // Initialize webhook service to subscribe to events
    new WebhookService();
    
    // Initialize workflow trigger service to subscribe workflows to events
    $workflowTriggerService = new WorkflowTriggerService();
    $workflowTriggerService->initializeWorkflows();
} catch (\Exception $e) {
    error_log('Index initialization error: ' . $e->getMessage());
    $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
               || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
               || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>500 Internal Server Error</title></head><body>";
    echo "<h1>500 Internal Server Error</h1>";
    echo "<p>An error occurred while initializing the application.</p>";
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
// #region agent log
$logEntry = json_encode([
    'id' => 'log_' . time() . '_' . uniqid(),
    'timestamp' => round(microtime(true) * 1000),
    'location' => 'index.php:32',
    'message' => 'Database initialized',
    'data' => ['initialized' => true],
    'sessionId' => 'debug-session',
    'runId' => 'run1',
    'hypothesisId' => 'D'
]) . "\n";
@file_put_contents($logFile, $logEntry, FILE_APPEND);
// #endregion

// Start session
Session::start();
// #region agent log
$logEntry = json_encode([
    'id' => 'log_' . time() . '_' . uniqid(),
    'timestamp' => round(microtime(true) * 1000),
    'location' => 'index.php:35',
    'message' => 'Session started in index',
    'data' => ['sessionStarted' => true],
    'sessionId' => 'debug-session',
    'runId' => 'run1',
    'hypothesisId' => 'D'
]) . "\n";
@file_put_contents($logFile, $logEntry, FILE_APPEND);
// #endregion

// When this file is required by a page or API endpoint, it acts as the shared
// bootstrap only. Route handling below should run only for direct front-controller
// requests; otherwise included API endpoints can inherit a 404 before emitting JSON.
$entryScript = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
$frontController = realpath(__FILE__) ?: __FILE__;
if ($entryScript !== $frontController) {
    return;
}

// Simple routing for now
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Remove leading slash
$path = ltrim($path, '/');

// Remove base path (crm/public/ or public/) if present
if (strpos($path, 'crm/public/') === 0) {
    $path = substr($path, strlen('crm/public/'));
} elseif ($path === 'crm/public' || $path === 'crm/public/') {
    $path = '';
} elseif (strpos($path, 'public/') === 0) {
    $path = substr($path, strlen('public/'));
} elseif ($path === 'public' || $path === 'public/') {
    $path = '';
}

// Default to dashboard if logged in, otherwise login
if (empty($path) || $path === 'index.php') {
    // #region agent log
    $logEntry = json_encode([
        'id' => 'log_' . time() . '_' . uniqid(),
        'timestamp' => round(microtime(true) * 1000),
        'location' => 'index.php:44',
        'message' => 'Checking authentication for routing',
        'data' => ['path' => $path],
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'D'
    ]) . "\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
    // #endregion
    if (Auth::check()) {
        // #region agent log
        $logEntry = json_encode([
            'id' => 'log_' . time() . '_' . uniqid(),
            'timestamp' => round(microtime(true) * 1000),
            'location' => 'index.php:46',
            'message' => 'User authenticated, redirecting to dashboard',
            'data' => ['redirect' => 'dashboard.php'],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'D'
        ]) . "\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
        // #endregion
        // Get base path dynamically (function defined in config/constants.php)
        $basePath = getBasePath();
        header('Location: ' . $basePath . '/dashboard.php');
        exit;
    } else {
        // #region agent log
        $logEntry = json_encode([
            'id' => 'log_' . time() . '_' . uniqid(),
            'timestamp' => round(microtime(true) * 1000),
            'location' => 'index.php:49',
            'message' => 'User not authenticated, redirecting to landing page',
            'data' => ['redirect' => 'landing'],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'D'
        ]) . "\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
        // #endregion
        // Get base path dynamically (function defined in config/constants.php)
        $basePath = getBasePath();
        // Redirect to landing page root dynamically by stripping trailing /public if present.
        $landingBase = preg_replace('#/public$#', '', rtrim($basePath, '/'));
        $landingPath = ($landingBase === '' || $landingBase === '/') ? '/' : ($landingBase . '/');
        header('Location: ' . $landingPath);
        exit;
    }
}

// Route to actual PHP files
$publicDir = __DIR__;
$requestedFile = $publicDir . '/' . $path;

// Check if it's a PHP file request
if (preg_match('/\.php$/', $path)) {
    // If the file exists, include it
    if (file_exists($requestedFile) && is_file($requestedFile)) {
        require $requestedFile;
        exit;
    }
}

// If no file matches, show 404
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>404 - Not Found</title></head><body>";
echo "<h1>404 - Page Not Found</h1>";
echo "<p>The requested page could not be found.</p>";
echo "</body></html>";
