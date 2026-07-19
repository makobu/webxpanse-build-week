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

use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Services\WorkflowGraphService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    $graph = $payload['graph'] ?? null;
    if (!$graph) {
        throw new \Exception('Graph payload is required');
    }

    $service = new WorkflowGraphService();
    echo json_encode(['success' => true] + $service->validateGraph($graph));
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
