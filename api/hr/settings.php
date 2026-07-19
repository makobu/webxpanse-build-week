<?php

require_once __DIR__ . '/_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Modules\HRAnalyticsSettings;
use CRM\Security;
use CRM\Services\AnalyticsWorkspaceService;

Authorization::requirePermission('hr.analytics.settings', true);

$module = new HRAnalyticsSettings();
$workspaceId = (new AnalyticsWorkspaceService())->requireAnalyticsWorkspaceId();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'settings' => $module->get($workspaceId)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
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

try {
    $payload = [];
    if (array_key_exists('ai_enabled', $input)) {
        $payload['ai_enabled'] = !empty($input['ai_enabled']);
    }
    if (array_key_exists('scoring_weights', $input)) {
        $payload['scoring_weights'] = $input['scoring_weights'];
    }
    if (array_key_exists('thresholds', $input)) {
        $payload['thresholds'] = $input['thresholds'];
    }
    if (array_key_exists('department_mappings', $input)) {
        $payload['department_mappings'] = $input['department_mappings'];
    }
    if (array_key_exists('prompt_config', $input)) {
        $payload['prompt_config'] = $input['prompt_config'];
    }

    $module->save($payload, (int) (Auth::user()['id'] ?? 0), $workspaceId);

    echo json_encode(['success' => true, 'settings' => $module->get($workspaceId)]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
