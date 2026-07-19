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
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMarketplaceRecommendationEventService;

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

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'No active workspace']);
    exit;
}

$skillKey = trim((string) ($input['skill_key'] ?? ''));
if ($skillKey === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Missing Marketplace item']);
    exit;
}

$user = Auth::user() ?: [];
$canViewMarketplace = Authorization::isSuperAdmin($user)
    || Authorization::can('workspace.skills.view', $user)
    || Authorization::can('workspace.skills.manage', $user);
if (!$canViewMarketplace) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your access profile cannot view Marketplace recommendations.']);
    exit;
}

$surface = (string) ($input['surface'] ?? 'marketplace');
$eventType = (string) ($input['event_type'] ?? 'cta_clicked');

try {
    (new WorkspaceMarketplaceRecommendationEventService())->recordEvent(
        $workspaceId,
        (int) ($user['id'] ?? 0),
        $skillKey,
        $surface,
        $eventType,
        [
            'metadata' => [
                'source' => (string) ($input['source'] ?? $surface),
                'target_url' => (string) ($input['target_url'] ?? ''),
                'activation_bundle_key' => (string) ($input['activation_bundle_key'] ?? ''),
                'setup_journey_present' => !empty($input['setup_journey_present']),
                'setup_journey_next_step_key' => (string) ($input['setup_journey_next_step_key'] ?? ''),
            ],
        ]
    );

    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    error_log('Marketplace recommendation event API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to record event']);
}
