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
use CRM\Modules\AuditLog;
use CRM\Security;
use CRM\Session;
use CRM\Services\SessionAutomationCoordinator;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');
$perfEnabled = isset($_GET['perf_debug']) || (
    function_exists('crmPublicDebugEnabled') && crmPublicDebugEnabled()
);
$perfMarks = [['start', microtime(true)]];
$markPerf = static function (string $label) use (&$perfMarks, $perfEnabled): void {
    if ($perfEnabled) {
        $perfMarks[] = [$label, microtime(true)];
    }
};
$flushPerf = static function () use (&$perfMarks, $perfEnabled): void {
    if (!$perfEnabled || headers_sent()) {
        return;
    }
    $parts = [];
    for ($i = 1, $count = count($perfMarks); $i < $count; $i++) {
        $label = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $perfMarks[$i][0]);
        $duration = max(0, ($perfMarks[$i][1] - $perfMarks[$i - 1][1]) * 1000);
        $parts[] = $label . ';dur=' . number_format($duration, 1, '.', '');
    }
    $total = max(0, ($perfMarks[count($perfMarks) - 1][1] - $perfMarks[0][1]) * 1000);
    $parts[] = 'session_postload_total;dur=' . number_format($total, 1, '.', '');
    header('Server-Timing: ' . implode(', ', $parts));
};

if (!Auth::check()) {
    Auth::jsonAuthError('', 401, 'Unauthorized');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = $_POST;
if (empty($input)) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$csrfToken = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!Security::validateCSRF($csrfToken)) {
    Auth::jsonAuthError('csrf_invalid', 403, 'Invalid security token');
    exit;
}
$markPerf('auth_and_csrf');

$userId = (int) (Auth::user()['id'] ?? 0);
$context = [
    'current_page' => (string) ($input['current_page'] ?? basename(parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?: '')),
    'trigger' => 'session_postload',
];
Session::closeWrite();

try {
    $currentPage = trim((string) ($context['current_page'] ?? ''));
    if ($currentPage !== '' && $currentPage !== 'login.php') {
        try {
            (new AuditLog())->logPageView($currentPage);
        } catch (\Throwable $e) {
            error_log('Session postload page view logging failed: ' . $e->getMessage());
        }
    }
    $markPerf('page_view');

    $summary = (new SessionAutomationCoordinator())->runForUser($userId, $context);
    $markPerf('automation');
    $flushPerf();
    echo json_encode(['success' => true, 'summary' => $summary]);
} catch (\Throwable $e) {
    http_response_code(500);
    $markPerf('failed');
    $flushPerf();
    echo json_encode(['success' => false, 'error' => 'Unable to process session automations']);
}
