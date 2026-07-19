<?php
/**
 * Dashboard automation battery API.
 */

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
use CRM\Session;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\AutomationBatteryService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\ProtectedDemoShowcaseProfileService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    Auth::jsonAuthError('', 401, 'Unauthorized');
    exit;
}

$user = Auth::user();
$canViewBattery = Authorization::can('feature.automation_battery', $user)
    || Authorization::can('feature.automation_readiness', $user)
    || Authorization::hasAccessProfilePermission('feature.automation_readiness_card', $user);

if (!$canViewBattery) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $viewerUserId = (int) ($user['id'] ?? 0);
    $subjectUserId = $viewerUserId;
    $requestedSubjectUserId = ctype_digit((string) ($_GET['subject_user_id'] ?? ''))
        ? (int) $_GET['subject_user_id']
        : 0;

    if ($requestedSubjectUserId > 0 && $requestedSubjectUserId !== $viewerUserId && Authorization::can('analytics.view_all', $user)) {
        $workspace = new AnalyticsWorkspaceService();
        $workspaceId = $workspace->requireAnalyticsWorkspaceId();
        $subjectUserId = $workspace->ensureScopedUserId($requestedSubjectUserId, $workspaceId) ?: $viewerUserId;
    }

    $source = strtolower(trim((string) ($_GET['source'] ?? 'manual')));
    if (!in_array($source, ['manual', 'idle'], true)) {
        $source = 'manual';
    }

    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    $protectedDemoSession = null;
    if ($workspaceId > 0) {
        try {
            $protectedDemoSession = (new DemoSessionScopeService())->activeSession($workspaceId);
        } catch (\Throwable $e) {
            $protectedDemoSession = null;
        }
    }

    if ($protectedDemoSession !== null) {
        $status = (new ProtectedDemoShowcaseProfileService())->automationBatteryStatus($subjectUserId);
        echo json_encode([
            'success' => true,
            'status' => $status,
            'calculated_at' => (string) ($status['snapshot_calculated_at'] ?? ''),
            'expires_at' => (string) ($status['snapshot_expires_at'] ?? ''),
            'is_stale' => false,
            'protected_demo_showcase' => true,
        ]);
        exit;
    }

    $status = (new AutomationBatteryService())->refreshStatus($viewerUserId, $subjectUserId, $source);

    echo json_encode([
        'success' => true,
        'status' => $status,
        'calculated_at' => (string) ($status['snapshot_calculated_at'] ?? ''),
        'expires_at' => (string) ($status['snapshot_expires_at'] ?? ''),
        'is_stale' => !empty($status['is_stale']),
        'refresh_policy' => (array) ($status['refresh_policy'] ?? []),
        'refresh_skipped' => !empty($status['refresh_skipped']),
        'refresh_due_at' => $status['refresh_due_at'] ?? null,
    ]);
} catch (\Throwable $e) {
    error_log('Dashboard automation battery failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Automation readiness is temporarily unavailable.',
    ]);
}
