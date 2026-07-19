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
use CRM\Session;
use CRM\Services\WorkflowAutomationControlService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

Authorization::requirePermission('settings.workflow_automation', true);

$service = new WorkflowAutomationControlService();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'state' => $service->buildSettingsState()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if ($service->isManagedByAutoAdmin()) {
    http_response_code(423);
    echo json_encode(['success' => false, 'error' => 'Workflow automation is managed by Auto Admin for this workspace right now.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$mode = (string) ($input['mode'] ?? 'suggest_only');

try {
    $state = $service->saveWorkspaceControl([
        'autonomy_mode' => $mode,
        'promotion_status' => (string) ($input['promotion_status'] ?? $mode),
        'demonstration_capture_enabled' => array_key_exists('demonstration_capture_enabled', $input) ? !empty($input['demonstration_capture_enabled']) : true,
        'policy_learning_enabled' => array_key_exists('policy_learning_enabled', $input) ? !empty($input['policy_learning_enabled']) : true,
        'review_ui_enabled' => array_key_exists('review_ui_enabled', $input) ? !empty($input['review_ui_enabled']) : true,
        'fast_promotion_enabled' => array_key_exists('fast_promotion_enabled', $input) ? !empty($input['fast_promotion_enabled']) : true,
        'auto_downgrade_on_drift' => array_key_exists('auto_downgrade_on_drift', $input) ? !empty($input['auto_downgrade_on_drift']) : true,
        'metadata' => [
            'block_customer_facing_full_auto' => array_key_exists('block_customer_facing_full_auto', $input) ? !empty($input['block_customer_facing_full_auto']) : true,
            'require_human_checkpoint_actions' => array_values(array_filter(array_map('trim', explode(',', (string) ($input['require_human_checkpoint_actions'] ?? ''))))),
            'allowed_actions' => array_values(array_filter(array_map('trim', explode(',', (string) ($input['allowed_actions'] ?? ''))))),
            'max_daily_auto_actions' => (int) ($input['max_daily_auto_actions'] ?? 50),
            'max_customer_facing_risk' => (float) ($input['max_customer_facing_risk'] ?? 0.95),
            'approval_required_for_promotion' => !empty($input['approval_required_for_promotion']),
        ],
    ], (int) (Auth::user()['id'] ?? 0));

    echo json_encode(['success' => true, 'state' => $service->buildSettingsState(), 'control' => $state]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
