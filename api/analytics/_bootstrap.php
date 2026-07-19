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
use CRM\Session;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\OperatingAnalyticsService;
use CRM\Services\WorkspaceBusinessIntelligenceGateService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
$viewerUserId = (int) ($user['id'] ?? 0);
$analyticsWorkspace = new AnalyticsWorkspaceService();
$workspaceId = $analyticsWorkspace->requireAnalyticsWorkspaceId();
(new WorkspaceBusinessIntelligenceGateService())->enforceJson($workspaceId, $user, 'Business Intelligence');
$operatingAnalytics = new OperatingAnalyticsService($analyticsWorkspace);
$timeframe = $operatingAnalytics->normalizeTimeframe($_GET['timeframe'] ?? 'month');
$subjectUserId = $operatingAnalytics->resolveSubjectUserId($user, $workspaceId, $_GET['user_id'] ?? null);

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    $payload['success'] = $payload['success'] ?? true;
    $payload['generated_at'] = $payload['generated_at'] ?? gmdate('c');
    $payload['summary'] = $payload['summary'] ?? [];
    $payload['metrics'] = $payload['metrics'] ?? [];
    $payload['insights'] = $payload['insights'] ?? [];
    $payload['charts'] = $payload['charts'] ?? [];
    echo json_encode($payload);
    exit;
};

$forbidden = static function () use ($respond): void {
    $respond(['success' => false, 'error' => 'Forbidden'], 403);
};

$fail = static function (string $section, \Throwable $e) use ($respond): void {
    error_log('Analytics ' . $section . ' endpoint failed: ' . $e->getMessage());
    $respond(['success' => false, 'error' => 'Analytics section is temporarily unavailable.'], 500);
};
