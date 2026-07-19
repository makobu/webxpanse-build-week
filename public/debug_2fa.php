<?php
/**
 * Temporary debug script for settings_2fa.php - DELETE after fixing
 * Access: crm.makdennis.devdebug_2fa.php
 */
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

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

$steps = [];

try {
    $steps[] = '1. PHP version: ' . PHP_VERSION;
    
    $vendorPath = __DIR__ . '/../vendor/autoload.php';
    $steps[] = '2. Vendor exists: ' . (file_exists($vendorPath) ? 'YES' : 'NO');
    
    require_once $vendorPath;
    $steps[] = '3. Autoload: OK';
    
    if (file_exists($envFile)) {
        $steps[] = '4. .env loaded: OK';
    } else {
        $steps[] = '4. .env: NOT FOUND';
    }
    
    require_once __DIR__ . '/../config/constants.php';
    $steps[] = '5. Constants: OK';
    
    \CRM\Database::init(require __DIR__ . '/../config/database.php');
    $steps[] = '6. Database init: OK';
    
    $pdo = \CRM\Database::getInstance();
    $steps[] = '7. DB connection: OK';
    
    // Check if two_factor columns exist
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'two_factor%'")->fetchAll(PDO::FETCH_COLUMN);
    $steps[] = '8. users.two_factor columns: ' . implode(', ', $cols ?: ['NONE - RUN MIGRATION 064']);
    
    $tableExists = $pdo->query("SHOW TABLES LIKE 'user_recovery_codes'")->rowCount() > 0;
    $steps[] = '9. user_recovery_codes table: ' . ($tableExists ? 'EXISTS' : 'MISSING - RUN MIGRATION 064');
    
    $steps[] = '10. Google2FA class: ' . (class_exists('PragmaRX\Google2FA\Google2FA') ? 'OK' : 'MISSING');
    
} catch (Throwable $e) {
    $steps[] = 'ERROR: ' . $e->getMessage();
    $steps[] = 'File: ' . $e->getFile() . ':' . $e->getLine();
    $steps[] = 'Trace: ' . $e->getTraceAsString();
}

echo implode("\n", $steps);
