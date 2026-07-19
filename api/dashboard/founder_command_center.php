<?php
/**
 * Calm, deterministic Founder Command Center payload.
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
use CRM\CacheManager;
use CRM\Database;
use CRM\Session;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\FounderCommandCenterAccessService;
use CRM\Services\FounderCommandCenterService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    Auth::jsonAuthError('', 401, 'Unauthorized');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $user = Auth::user();
    $viewerUserId = (int) ($user['id'] ?? 0);
    $workspace = new AnalyticsWorkspaceService();
    $workspaceId = $workspace->requireAnalyticsWorkspaceId();
    if (!(new FounderCommandCenterAccessService())->canView($user, $workspaceId)) {
        Session::closeWrite();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Founder workspace access is required.']);
        exit;
    }
    $subjectUserId = $viewerUserId;
    $requestedSubjectUserId = ctype_digit((string) ($_GET['subject_user_id'] ?? ''))
        ? (int) $_GET['subject_user_id']
        : 0;
    if ($requestedSubjectUserId > 0 && $requestedSubjectUserId !== $viewerUserId && Authorization::can('analytics.view_all', $user)) {
        $subjectUserId = $workspace->ensureScopedUserId($requestedSubjectUserId, $workspaceId) ?: $viewerUserId;
    }

    $allowMarketing = Authorization::can('marketing.read', $user);
    Session::closeWrite();
    $cacheKey = implode(':', [
        'founder_command_center',
        'v2',
        $workspaceId,
        $subjectUserId,
        $allowMarketing ? 'marketing' : 'no_marketing',
    ]);
    $commandCenter = (new CacheManager())->getWithCache(
        $cacheKey,
        static fn(): array => (new FounderCommandCenterService())->build($workspaceId, $subjectUserId, [
            'allow_marketing' => $allowMarketing,
        ]),
        45
    );

    echo json_encode([
        'success' => true,
        'command_center' => $commandCenter,
    ], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    error_log('Founder Command Center failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'The operating rhythm is temporarily unavailable.',
    ]);
}
