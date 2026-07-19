<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
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

use CRM\Auth;
use CRM\Database;
use CRM\Modules\Invoices;
use CRM\Session;

header('Content-Type: application/json');

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

try {
    $lineItem = [
        'product_id' => !empty($payload['product_id']) ? (int) $payload['product_id'] : null,
        'description' => (string) ($payload['description'] ?? ''),
        'pricing_context' => (string) ($payload['pricing_context'] ?? ''),
        'unit_price' => isset($payload['unit_price']) ? (float) $payload['unit_price'] : 0.0,
    ];
    $result = (new Invoices())->inferLineItemPrice($lineItem);
    echo json_encode(['success' => true] + $result);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
