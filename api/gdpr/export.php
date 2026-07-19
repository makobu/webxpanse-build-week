<?php
/**
 * GDPR Data Export Endpoint
 * Returns user data in JSON format
 * Note: This endpoint is called internally after email verification
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Database;
use CRM\Modules\GDPR;

Database::init(require __DIR__ . '/../../config/database.php');

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Token required']);
    exit;
}

try {
    $gdpr = new GDPR();
    $result = $gdpr->verifyAndProcess($token);
    
    // Return JSON data
    header('Content-Disposition: attachment; filename="gdpr-export-' . date('Y-m-d') . '.json"');
    echo json_encode($result['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
