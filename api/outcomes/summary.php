<?php
/**
 * Outcome summary API
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Modules\OutcomeMetrics;
use CRM\Services\OutcomeRolloutService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$days = max(1, min(90, (int) ($_GET['days'] ?? 14)));

try {
    $rollout = new OutcomeRolloutService();
    $enabled = $rollout->isEnabledForUser($userId);
    if (!$enabled) {
        echo json_encode([
            'success' => true,
            'enabled' => false,
            'north_star' => null,
            'kpis' => null,
            'today_focus' => [],
            'checklist' => [],
        ]);
        exit;
    }

    $metrics = new OutcomeMetrics();
    echo json_encode([
        'success' => true,
        'enabled' => true,
        'north_star' => $metrics->getTTFVSummary($days),
        'kpis' => [
            'activation_7d_rate' => $metrics->getActivationRateSummary(7),
            'revenue_action_rate_7d' => $metrics->getRevenueActionRateSummary(7),
        ],
        'today_focus' => $metrics->getTodayRevenueFocus($userId),
        'checklist' => $metrics->getChecklist($userId),
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
