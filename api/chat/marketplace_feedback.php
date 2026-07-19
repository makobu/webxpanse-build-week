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
use CRM\Services\WorkspaceMarketplaceRecommendationService;

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

$user = Auth::user() ?: [];
$userId = (int) ($user['id'] ?? 0);
$canManageMarketplace = Authorization::isSuperAdmin($user)
    || Authorization::can('workspace.skills.manage', $user);

if (!$canManageMarketplace) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your access profile cannot manage Marketplace recommendations.']);
    exit;
}

$skillKey = trim((string) ($input['skill_key'] ?? ''));
if ($skillKey === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Missing Marketplace item']);
    exit;
}

$feedbackType = (string) ($input['feedback_type'] ?? 'dismissed');
$feedbackType = $feedbackType === 'snoozed' ? 'snoozed' : 'dismissed';
$snoozedUntil = $feedbackType === 'snoozed' ? date('Y-m-d H:i:s', strtotime('+14 days')) : null;
$source = trim((string) ($input['source'] ?? 'clarity_chat'));
$source = preg_replace('/[^a-z0-9_]+/i', '_', $source) ?: 'clarity_chat';
$source = strtolower(substr($source, 0, 40));

try {
    (new WorkspaceMarketplaceRecommendationService())->recordFeedback(
        $workspaceId,
        $userId,
        $skillKey,
        $feedbackType,
        $source,
        $snoozedUntil,
        ['source' => $source]
    );
    (new WorkspaceMarketplaceRecommendationEventService())->recordEvent(
        $workspaceId,
        $userId,
        $skillKey,
        $source,
        $feedbackType,
        ['metadata' => ['source' => $source]]
    );

    echo json_encode([
        'success' => true,
        'feedback_type' => $feedbackType,
        'snoozed_until' => $snoozedUntil,
    ]);
} catch (\Throwable $e) {
    error_log('Marketplace feedback API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save feedback']);
}
