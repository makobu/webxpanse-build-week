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
use CRM\Modules\Tasks;
use CRM\Security;
use CRM\Services\AIAdviceFollowThroughService;
use CRM\Services\ClarityOperatorControlsService;
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

$user = Auth::user();
$service = new AIAdviceFollowThroughService();
$tasks = new Tasks();

if ((string) ($input['surface'] ?? '') === 'clarity_chat' && !(new ClarityOperatorControlsService())->enabled()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Clarity operator controls are available only in the default workspace.',
        'blocked_reason' => ClarityOperatorControlsService::BLOCKED_REASON,
    ]);
    exit;
}

try {
    $taskId = (int) ($input['task_id'] ?? 0);
    $task = $tasks->getById($taskId);
    if (!$task || !in_array((int) ($user['id'] ?? 0), [(int) ($task['assigned_to'] ?? 0), (int) ($task['created_by'] ?? 0)], true)) {
        throw new \InvalidArgumentException('Task not found for the current user.');
    }

    $payload = [
        'task_id' => $taskId,
        'guidance_run_id' => (int) ($input['guidance_run_id'] ?? 0),
        'surface' => (string) ($input['surface'] ?? ''),
        'recommendation_key' => $input['recommendation_key'] ?? null,
        'message_hash' => $input['message_hash'] ?? null,
        'source_recommendation_type' => $input['source_recommendation_type'] ?? null,
        'linked_contact_id' => !empty($input['linked_contact_id']) ? (int) $input['linked_contact_id'] : null,
        'linked_deal_id' => !empty($input['linked_deal_id']) ? (int) $input['linked_deal_id'] : null,
        'metadata_json' => (array) ($input['metadata_json'] ?? []),
        'user_id' => (int) ($user['id'] ?? 0),
    ];

    $service->linkManualAction($payload);

    echo json_encode([
        'success' => true,
        'task_id' => (int) $payload['task_id'],
        'guidance_run_id' => (int) $payload['guidance_run_id'],
    ]);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('AI link_task_to_guidance API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to link task to guidance']);
}
