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
use CRM\Services\AIAdviceFollowThroughService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = $_POST;
if (empty($input) && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
}

if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$surface = trim((string) ($input['surface'] ?? ''));
if ($surface === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Surface is required']);
    exit;
}

if (empty($input['guidance_run_id']) && empty($input['assistant_run_id']) && empty($input['commercial_run_id']) && empty($input['approval_id'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'A linked AI source is required']);
    exit;
}

$user = Auth::user();
$service = new AIAdviceFollowThroughService();

try {
    $payload = [
        'surface' => $surface,
        'guidance_run_id' => !empty($input['guidance_run_id']) ? (int) $input['guidance_run_id'] : null,
        'assistant_run_id' => !empty($input['assistant_run_id']) ? (int) $input['assistant_run_id'] : null,
        'commercial_run_id' => !empty($input['commercial_run_id']) ? (int) $input['commercial_run_id'] : null,
        'approval_id' => !empty($input['approval_id']) ? (int) $input['approval_id'] : null,
        'task_id' => !empty($input['task_id']) ? (int) $input['task_id'] : null,
        'linked_contact_id' => !empty($input['linked_contact_id']) ? (int) $input['linked_contact_id'] : null,
        'linked_deal_id' => !empty($input['linked_deal_id']) ? (int) $input['linked_deal_id'] : null,
        'linked_invoice_id' => !empty($input['linked_invoice_id']) ? (int) $input['linked_invoice_id'] : null,
        'recommendation_key' => $input['recommendation_key'] ?? null,
        'message_hash' => $input['message_hash'] ?? null,
        'source_recommendation_type' => $input['source_recommendation_type'] ?? null,
        'decision_type' => $input['decision_type'] ?? null,
        'notes' => $input['notes'] ?? null,
        'user_id' => (int) ($user['id'] ?? 0),
    ];

    $service->linkManualAction($payload);

    echo json_encode([
        'success' => true,
        'surface' => $surface,
        'task_id' => $payload['task_id'],
    ]);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('AI link_followthrough API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to record follow-through']);
}
