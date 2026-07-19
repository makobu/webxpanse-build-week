<?php
/**
 * Test Bootstrap
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables for testing
$envFile = __DIR__ . '/../.env.testing';
if (!file_exists($envFile)) {
    $envFile = __DIR__ . '/../.env';
}

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = trim($value);
        }
    }
}

// Set test database
$_ENV['DB_NAME'] = $_ENV['DB_NAME'] ?? 'crm_test';
$_ENV['DISABLE_CROSS_DOMAIN_ORCHESTRATION'] = 'true';
$_ENV['DISABLE_THREAD_SUMMARY_AI'] = 'true';
$_ENV['DISABLE_SMTP_CONFIG_LOGS'] = 'true';

// Load constants
require_once __DIR__ . '/../config/constants.php';
