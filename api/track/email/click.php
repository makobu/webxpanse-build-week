<?php
/**
 * Email Click Tracking Endpoint
 * Redirects to original URL after tracking
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../../config/constants.php';

use CRM\Database;
use CRM\Modules\EmailTracking;
use CRM\Security;

// Initialize database
$dbConfig = require __DIR__ . '/../../../config/database.php';
Database::init($dbConfig);

// Get email UUID and URL from query string
$path = $_SERVER['REQUEST_URI'] ?? '';
$parts = explode('/', trim($path, '/'));
$emailUuid = $parts[count($parts) - 1] ?? '';

$rawUrl = (string) ($_GET['url'] ?? '');
$url = Security::sanitizeRedirectUrl($rawUrl, '/');

if ($emailUuid && $rawUrl !== '' && $url !== '/') {
    $tracking = new EmailTracking();
    $tracking->trackClick($emailUuid, $url);
}

// Redirect to original URL
header('Location: ' . $url);
exit;
