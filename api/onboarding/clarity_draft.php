<?php
/**
 * Inline Clarity draft helper for onboarding fields.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
use CRM\Services\OnboardingClarityDraftService;
use CRM\Services\WorkspaceConnectService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$rawInput = file_get_contents('php://input') ?: '';
$input = json_decode($rawInput !== '' ? $rawInput : '{}', true);
if (!is_array($input) || $input === []) {
    $input = $_POST;
}

if (!Security::validateCSRF((string) ($input['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh and try again.']);
    exit;
}

try {
    $user = Auth::user();
    $workspaceId = (new WorkspaceConnectService())->requireWorkspaceAdmin($user);
    $result = (new OnboardingClarityDraftService())->draft(
        $workspaceId,
        (int) ($user['id'] ?? 0),
        (int) ($input['step'] ?? 0),
        (string) ($input['field'] ?? ''),
        (string) ($input['current_value'] ?? ''),
        is_array($input['form_context'] ?? null) ? (array) $input['form_context'] : []
    );

    echo json_encode($result, JSON_UNESCAPED_SLASHES);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    if (stripos($e->getMessage(), 'Workspace admin access') !== false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Workspace admin access is required.']);
        exit;
    }

    error_log('Onboarding Clarity draft error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Clarity could not draft this field right now.']);
} catch (\Throwable $e) {
    error_log('Onboarding Clarity draft error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Clarity could not draft this field right now.']);
}
