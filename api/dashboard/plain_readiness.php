<?php
/**
 * Plain-language dashboard readiness API.
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
use CRM\Database;
use CRM\Session;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\PlainLanguageReadinessService;
use CRM\Services\UIExperienceService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
Session::closeWrite();
$workspaceId = 0;
$mode = UIExperienceService::MODE_BEGINNER;
$service = new PlainLanguageReadinessService();

try {
    $workspaceId = (new AnalyticsWorkspaceService())->requireAnalyticsWorkspaceId();
    $mode = (new UIExperienceService())->modeForUser($user, $workspaceId);
    $readiness = $service->summaryFor($workspaceId, $userId, [
        'current_page' => 'dashboard.php',
        'mode' => $mode,
    ]);

    echo json_encode([
        'success' => true,
        'readiness' => $readiness,
    ]);
} catch (\Throwable $e) {
    error_log('Dashboard plain readiness failed: ' . $e->getMessage());
    echo json_encode([
        'success' => true,
        'readiness' => $service->fallbackPayload($userId, $mode),
        'fallback' => true,
    ]);
}
