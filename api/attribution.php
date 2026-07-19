<?php
/**
 * Attribution API
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Modules\AttributionReports;
use CRM\Services\AttributionService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (($_ENV['ATTRIBUTION_ENABLED'] ?? '1') !== '1') {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Attribution disabled']);
    exit;
}

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$reports = new AttributionReports();
$service = new AttributionService();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

try {
    if ($method === 'GET') {
        $model = Security::sanitizeInput($_GET['model'] ?? 'last_touch', 'string');
        $dateFrom = !empty($_GET['date_from']) ? Security::sanitizeInput($_GET['date_from'], 'string') : null;
        $dateTo = !empty($_GET['date_to']) ? Security::sanitizeInput($_GET['date_to'], 'string') : null;
        echo json_encode([
            'success' => true,
            'models' => $reports->getModelOptions(),
            'revenue_by_campaign' => $reports->getRevenueByCampaign($model, $dateFrom, $dateTo),
            'channel_contribution' => $reports->getChannelContribution($model, $dateFrom, $dateTo),
            'journeys' => $reports->getTopJourneys($model, 20)
        ]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    Authorization::requirePermission('analytics.view_all', true);

    if (!Security::validateCSRF($input['csrf_token'] ?? $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $action = Security::sanitizeInput($input['action'] ?? 'recompute', 'string');
    if ($action === 'recompute') {
        $dealId = (int) ($input['deal_id'] ?? 0);
        $model = !empty($input['model']) ? Security::sanitizeInput($input['model'], 'string') : null;
        if ($dealId > 0) {
            $rows = $service->recomputeForDeal($dealId, $model);
            echo json_encode(['success' => true, 'rows' => $rows]);
            exit;
        }
        $result = $service->recomputeAllWonDeals($model, 5000);
        echo json_encode(['success' => true, 'result' => $result]);
        exit;
    }

    if ($action === 'compare' && !empty($input['deal_id'])) {
        echo json_encode([
            'success' => true,
            'data' => $service->compareModelsForDeal((int) $input['deal_id'])
        ]);
        exit;
    }

    throw new RuntimeException('Unsupported action');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
