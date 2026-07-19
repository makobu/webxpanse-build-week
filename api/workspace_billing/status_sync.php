<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Database;
use CRM\Services\WorkspaceBillingService;

Database::init(require __DIR__ . '/../../config/database.php');

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['accepted' => false, 'message' => 'Method not allowed.']);
    exit;
}

$rawPayload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_BILLING_SIGNATURE'] ?? null;

try {
    $result = (new WorkspaceBillingService())->processHubStatusSync($rawPayload, $signature);
    http_response_code(!empty($result['accepted']) ? 200 : 400);
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['accepted' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
