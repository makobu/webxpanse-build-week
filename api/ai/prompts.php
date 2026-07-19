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
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\AIPromptRegistryService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
Authorization::requirePermission('ai.prompt_control.manage', true);

$service = new AIPromptRegistryService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $surface = trim((string) ($_GET['surface'] ?? ''));
    $promptKey = trim((string) ($_GET['prompt_key'] ?? ''));
    $version = (int) ($_GET['version'] ?? 0);
    if ($surface !== '' && $promptKey !== '') {
        echo json_encode([
            'success' => true,
            'active' => $service->getActivePrompt($surface, $promptKey),
            'selected' => $version > 0 ? $service->getPromptVersion($surface, $promptKey, $version) : null,
            'history' => $service->getPromptHistory($surface, $promptKey),
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'prompts' => $service->getAllActivePrompts(),
    ]);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: $_POST;
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '');
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = trim((string) ($payload['action'] ?? 'register'));
$surface = trim((string) ($payload['surface'] ?? ''));
$promptKey = trim((string) ($payload['prompt_key'] ?? ''));
$version = (int) ($payload['version'] ?? 0);

if ($action === 'activate' || $action === 'rollback') {
    $ok = $service->activatePromptVersion($surface, $promptKey, $version);
    if (!$ok) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unable to activate prompt version']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'active' => $service->getActivePrompt($surface, $promptKey),
    ]);
    exit;
}

$id = $service->registerPrompt([
    'surface' => $surface,
    'prompt_key' => $promptKey,
    'status' => trim((string) ($payload['status'] ?? 'draft')),
    'system_prompt_text' => (string) ($payload['system_prompt_text'] ?? ''),
    'instruction_text' => (string) ($payload['instruction_text'] ?? ''),
    'output_contract_json' => $payload['output_contract_json'] ?? null,
    'metadata_json' => $payload['metadata_json'] ?? [],
    'created_by' => (int) ($user['id'] ?? 0),
]);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unable to register prompt']);
    exit;
}

echo json_encode([
    'success' => true,
    'id' => $id,
]);
