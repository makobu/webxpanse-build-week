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
use CRM\Services\SaaSBillingService;

Database::init(require __DIR__ . '/../../config/database.php');

$rawPayload = file_get_contents('php://input') ?: '';
if ($rawPayload === '' && !empty($_POST)) {
    $rawPayload = json_encode($_POST, JSON_UNESCAPED_SLASHES) ?: '';
}

try {
    (new SaaSBillingService())->processMpesaCallback($rawPayload);
} catch (\Throwable $e) {
    error_log('M-Pesa webhook processing failed: ' . $e->getMessage());
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'ResultCode' => 0,
    'ResultDesc' => 'Success',
]);
