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
use CRM\Services\AIPromptDryRunService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
Authorization::requirePermission('ai.prompt_control.manage', true);

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload) || empty($payload)) {
    $payload = $_POST;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '');
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$surface = trim((string) ($payload['surface'] ?? ''));
$promptKey = trim((string) ($payload['prompt_key'] ?? ''));
$version = (int) ($payload['version'] ?? 0);
$maxBlocks = max(1, (int) ($payload['max_blocks'] ?? 12));
$maxChars = max(500, (int) ($payload['max_chars'] ?? 12000));
$sampleInput = $payload['sample_input'] ?? [];
if (!is_array($sampleInput)) {
    $decoded = json_decode((string) $sampleInput, true);
    $sampleInput = is_array($decoded) ? $decoded : [];
}

if ($surface === '' || $promptKey === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'surface and prompt_key are required']);
    exit;
}

$service = new AIPromptDryRunService();
$result = $service->dryRun($surface, $promptKey, $version, $sampleInput, $maxBlocks, $maxChars);

echo json_encode([
    'success' => true,
    'dry_run' => $result,
], JSON_UNESCAPED_SLASHES);
