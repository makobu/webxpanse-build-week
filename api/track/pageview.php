<?php
/**
 * Page View Tracking Endpoint
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment and initialize
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
use CRM\Modules\Tracking;

// Initialize database
$dbConfig = require __DIR__ . '/../../config/database.php';
Database::init($dbConfig);

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['visitor_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'visitor_id required']);
        exit;
    }
    
    $tracking = new Tracking();
    $pageViewId = $tracking->trackPageView($data);
    
    http_response_code(200);
    echo json_encode(['success' => true, 'id' => $pageViewId]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
