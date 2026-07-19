<?php

ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
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

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Security;
use CRM\Services\AutomationJobHealthService;
use CRM\Services\ColdOutreachWarmupService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$queueSecret = $_ENV['PROCESS_QUEUE_SECRET'] ?? '';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$authenticated = (!empty($queueSecret) && hash_equals($queueSecret, (string) $token))
    || (Auth::check() && Authorization::can('settings.email_assistant') && Security::validateCSRF($csrfToken));

if (!$authenticated) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$jobHealth = new AutomationJobHealthService();
$startedAt = microtime(true);
$jobHealth->markStarted('cold_outreach_warmup');

try {
    $service = new ColdOutreachWarmupService();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $results = $service->run($userId > 0 ? $userId : null);
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $jobHealth->markSuccess('cold_outreach_warmup', 'Cold outreach warmup processed.', $durationMs, ['results' => $results]);

    ob_clean();
    echo json_encode([
        'success' => true,
        'results' => $results,
    ], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $jobHealth->markFailure('cold_outreach_warmup', $e->getMessage(), $durationMs);
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
