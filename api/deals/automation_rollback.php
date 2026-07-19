<?php
/**
 * Deal Automation Rollback API
 * One-click rollback of last AI-applied stage change
 */

require_once __DIR__ . '/../../vendor/autoload.php';

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
use CRM\Session;
use CRM\Auth;
use CRM\Services\DealAutomationOrchestrator;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$dealId = (int) ($_GET['deal_id'] ?? $_POST['deal_id'] ?? 0);
if (!$dealId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'deal_id required']);
    exit;
}

try {
    $orchestrator = new DealAutomationOrchestrator();
    $ok = $orchestrator->rollbackLastChange($dealId);
    echo json_encode(['success' => $ok, 'message' => $ok ? 'Stage rolled back' : 'No rollback available']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
