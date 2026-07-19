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
use CRM\Security;
use CRM\Session;
use CRM\Modules\Activities;
use CRM\Modules\DealAutomationConfig;
use CRM\Modules\Deals;
use CRM\Modules\Notes;
use CRM\Services\DealStageTransitionService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = $_POST;
if (empty($input)) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$dealId = (int) ($input['deal_id'] ?? 0);
$toStage = (string) ($input['to_stage'] ?? '');
$reason = trim((string) ($input['reason'] ?? ''));
$closeDate = trim((string) ($input['close_date'] ?? ''));
$closeNote = trim((string) ($input['close_note'] ?? ''));

if ($dealId <= 0 || $toStage === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'deal_id and to_stage are required']);
    exit;
}

if (!in_array($toStage, DealAutomationConfig::getAllowedStages(), true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid stage']);
    exit;
}

$deals = new Deals();
$deal = $deals->getById($dealId);
if (!$deal) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Deal not found']);
    exit;
}

if ($toStage === (string) ($deal['stage'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Deal is already in that stage']);
    exit;
}

try {
    $result = (new DealStageTransitionService())->transition($dealId, $toStage, [
        'reason' => $reason,
        'close_date' => $closeDate,
        'close_note' => $closeNote,
        'created_by' => (int) (Auth::user()['id'] ?? 0),
        'note_title' => 'Stage transition note',
        'actor_label' => 'Manual',
    ]);
    echo json_encode($result);
} catch (\Throwable $e) {
    $isExpected = !($e instanceof \PDOException)
        && ($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException);
    $status = $isExpected && (str_contains($e->getMessage(), 'required')
        || str_contains($e->getMessage(), 'blocked')
        || str_contains($e->getMessage(), 'already')
        || str_contains($e->getMessage(), 'Invalid stage'))
        ? 422
        : 500;
    http_response_code($status);
    if (!$isExpected) {
        error_log('Deal stage transition failed: ' . $e->getMessage());
    }
    echo json_encode([
        'success' => false,
        'error' => $isExpected ? $e->getMessage() : 'The deal stage could not be updated.',
    ]);
}
