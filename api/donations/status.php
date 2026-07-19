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
use CRM\Security;
use CRM\Services\SaaSBillingService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

try {
    $status = (new SaaSBillingService())->donationCheckoutStatus(
        (int) ($_POST['checkout_session_id'] ?? 0),
        (string) ($_POST['reference'] ?? '')
    );

    echo json_encode(['success' => true, 'data' => $status]);
} catch (\RuntimeException $e) {
    http_response_code(str_contains($e->getMessage(), 'not found') ? 404 : 422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Donation status is temporarily unavailable.']);
}
