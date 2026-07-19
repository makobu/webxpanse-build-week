<?php
/**
 * Diagnostic Test Page
 * This file helps identify what's causing the 500 errors
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('log_errors', 1);

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

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$diagnosticsEnabled = ($_ENV['APP_ENV'] ?? 'production') === 'development'
    || filter_var($_ENV['ENABLE_PUBLIC_DIAGNOSTICS'] ?? false, FILTER_VALIDATE_BOOL);

require_once __DIR__ . '/../config/constants.php';
$diagnosticsEnabled = function_exists('crmPublicDiagnosticsEnabled') && crmPublicDiagnosticsEnabled();

if (!$isLocalRequest || !$diagnosticsEnabled) {
    http_response_code(404);
    echo "Not found";
    exit;
}

ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><title>CRM Diagnostic Test</title>";
echo "<style>body{font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;} .success{color:green;} .error{color:red;} .info{color:blue;} pre{background:#fff;padding:10px;border:1px solid #ddd;overflow:auto;}</style>";
echo "</head><body>";
$diagnosticAppName = function_exists('brandProductName') ? brandProductName() : 'CRM';
echo "<h1>" . htmlspecialchars($diagnosticAppName) . " Diagnostic Test</h1>";

$errors = [];
$warnings = [];
$success = [];

// Test 1: PHP Version
echo "<h2>1. PHP Version</h2>";
$phpVersion = phpversion();
echo "<p class='info'>PHP Version: <strong>$phpVersion</strong></p>";
if (version_compare($phpVersion, '7.4.0', '<')) {
    $errors[] = "PHP version $phpVersion is too old. Requires PHP 7.4 or higher.";
    echo "<p class='error'>❌ PHP version is too old</p>";
} else {
    $success[] = "PHP version is compatible";
    echo "<p class='success'>✅ PHP version is compatible</p>";
}

// Test 2: Check vendor/autoload.php
echo "<h2>2. Composer Autoloader</h2>";
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    echo "<p class='success'>✅ File exists: $autoloadPath</p>";
    $success[] = "Composer autoloader found";
    
    // Try to require it
    try {
        require_once $autoloadPath;
        echo "<p class='success'>✅ Autoloader loaded successfully</p>";
        $success[] = "Autoloader loaded";
    } catch (\Throwable $e) {
        $errors[] = "Failed to load autoloader: " . $e->getMessage();
        echo "<p class='error'>❌ Failed to load: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    }
} else {
    $errors[] = "Composer autoloader not found at: $autoloadPath";
    echo "<p class='error'>❌ File not found: $autoloadPath</p>";
    echo "<p class='error'>Run 'composer install' in the project root directory.</p>";
}

// Test 3: Check .env file
echo "<h2>3. Environment File (.env)</h2>";
if (file_exists($envFile)) {
    echo "<p class='success'>✅ File exists: $envFile</p>";
    $success[] = ".env file found";
    
    // Try to read it
    if (is_readable($envFile)) {
        echo "<p class='success'>✅ File is readable</p>";
        $envContent = file_get_contents($envFile);
        $envLines = explode("\n", $envContent);
        echo "<p class='info'>File has " . count($envLines) . " lines</p>";
        
        // Check for required variables
        $requiredVars = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
        foreach ($requiredVars as $var) {
            if (strpos($envContent, $var) !== false) {
                echo "<p class='success'>✅ Found: $var</p>";
            } else {
                $warnings[] = "Missing required variable: $var";
                echo "<p class='error'>❌ Missing: $var</p>";
            }
        }
    } else {
        $errors[] = ".env file exists but is not readable";
        echo "<p class='error'>❌ File is not readable (check permissions)</p>";
    }
} else {
    $warnings[] = ".env file not found";
    echo "<p class='error'>❌ File not found: $envFile</p>";
}

// Test 4: Check config/constants.php
echo "<h2>4. Constants File</h2>";
$constantsPath = __DIR__ . '/../config/constants.php';
if (file_exists($constantsPath)) {
    echo "<p class='success'>✅ File exists: $constantsPath</p>";
    $success[] = "Constants file found";
    
    // Try to require it
    try {
        require_once $constantsPath;
        echo "<p class='success'>✅ Constants file loaded successfully</p>";
        $success[] = "Constants loaded";
        
        // Check if getBasePath function exists
        if (function_exists('getBasePath')) {
            echo "<p class='success'>✅ getBasePath() function exists</p>";
        } else {
            $errors[] = "getBasePath() function not found";
            echo "<p class='error'>❌ getBasePath() function not found</p>";
        }
    } catch (\Throwable $e) {
        $errors[] = "Failed to load constants: " . $e->getMessage();
        echo "<p class='error'>❌ Failed to load: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
} else {
    $errors[] = "Constants file not found";
    echo "<p class='error'>❌ File not found: $constantsPath</p>";
}

// Test 5: Check config/database.php
echo "<h2>5. Database Config File</h2>";
$dbConfigPath = __DIR__ . '/../config/database.php';
if (file_exists($dbConfigPath)) {
    echo "<p class='success'>✅ File exists: $dbConfigPath</p>";
    $success[] = "Database config file found";
    
    // Try to require it
    try {
        $dbConfig = require $dbConfigPath;
        echo "<p class='success'>✅ Database config loaded successfully</p>";
        $success[] = "Database config loaded";
        
        if (is_array($dbConfig)) {
            echo "<p class='success'>✅ Config is an array</p>";
            echo "<p class='info'>Host: " . htmlspecialchars($dbConfig['host'] ?? 'not set') . "</p>";
            echo "<p class='info'>Database: " . htmlspecialchars($dbConfig['name'] ?? 'not set') . "</p>";
            echo "<p class='info'>User: " . htmlspecialchars($dbConfig['user'] ?? 'not set') . "</p>";
            echo "<p class='info'>Password: " . (!empty($dbConfig['pass']) ? '***set***' : 'not set') . "</p>";
        } else {
            $errors[] = "Database config is not an array";
            echo "<p class='error'>❌ Config is not an array</p>";
        }
    } catch (\Throwable $e) {
        $errors[] = "Failed to load database config: " . $e->getMessage();
        echo "<p class='error'>❌ Failed to load: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    }
} else {
    $errors[] = "Database config file not found";
    echo "<p class='error'>❌ File not found: $dbConfigPath</p>";
}

// Test 6: Check core classes
echo "<h2>6. Core Classes</h2>";
if (class_exists('CRM\Database')) {
    echo "<p class='success'>✅ CRM\Database class exists</p>";
    $success[] = "Database class found";
} else {
    $errors[] = "CRM\Database class not found";
    echo "<p class='error'>❌ CRM\Database class not found</p>";
}

if (class_exists('CRM\Session')) {
    echo "<p class='success'>✅ CRM\Session class exists</p>";
    $success[] = "Session class found";
} else {
    $errors[] = "CRM\Session class not found";
    echo "<p class='error'>❌ CRM\Session class not found</p>";
}

if (class_exists('CRM\Auth')) {
    echo "<p class='success'>✅ CRM\Auth class exists</p>";
    $success[] = "Auth class found";
} else {
    $errors[] = "CRM\Auth class not found";
    echo "<p class='error'>❌ CRM\Auth class not found</p>";
}

// Test 7: Check file permissions
echo "<h2>7. File Permissions</h2>";
$dirsToCheck = [
    __DIR__ . '/../cache',
    __DIR__ . '/../logs',
    __DIR__ . '/../uploads',
];
foreach ($dirsToCheck as $dir) {
    if (file_exists($dir)) {
        if (is_writable($dir)) {
            echo "<p class='success'>✅ Writable: $dir</p>";
        } else {
            $warnings[] = "Directory not writable: $dir";
            echo "<p class='error'>❌ Not writable: $dir</p>";
        }
    } else {
        echo "<p class='info'>⚠️ Directory doesn't exist: $dir</p>";
    }
}

// Summary
echo "<h2>Summary</h2>";
echo "<p class='success'><strong>✅ Successes:</strong> " . count($success) . "</p>";
if (count($warnings) > 0) {
    echo "<p class='info'><strong>⚠️ Warnings:</strong> " . count($warnings) . "</p>";
    echo "<ul>";
    foreach ($warnings as $warning) {
        echo "<li>" . htmlspecialchars($warning) . "</li>";
    }
    echo "</ul>";
}
if (count($errors) > 0) {
    echo "<p class='error'><strong>❌ Errors:</strong> " . count($errors) . "</p>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p class='success'><strong>✅ No critical errors found!</strong></p>";
}

echo "</body></html>";
?>
