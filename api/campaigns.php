<?php
/**
 * Campaigns API
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
use CRM\Services\CampaignOrchestrator;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (($_ENV['CAMPAIGNS_ENABLED'] ?? '1') !== '1') {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Campaign automation disabled']);
    exit;
}

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

Authorization::requirePermission('campaigns.manage', true);

$orchestrator = new CampaignOrchestrator();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

try {
    if ($method === 'GET') {
        $campaignId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($campaignId > 0) {
            echo json_encode(['success' => true, 'data' => $orchestrator->getCampaignById($campaignId)]);
            exit;
        }

        $status = isset($_GET['status']) ? Security::sanitizeInput($_GET['status'], 'string') : null;
        $list = $orchestrator->listCampaigns(['status' => $status]);
        echo json_encode(['success' => true, 'data' => $list]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    if (!Security::validateCSRF($input['csrf_token'] ?? $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $action = Security::sanitizeInput($input['action'] ?? 'create', 'string');
    $campaignId = (int) ($input['id'] ?? 0);

    if ($action === 'create') {
        $campaignId = $orchestrator->createCampaign(array_merge($input, ['created_by' => Auth::user()['id'] ?? null]));
        echo json_encode(['success' => true, 'id' => $campaignId]);
        exit;
    }

    if ($campaignId <= 0) {
        throw new RuntimeException('Campaign id is required');
    }

    if ($action === 'update') {
        $orchestrator->updateCampaign($campaignId, $input);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'launch') {
        $result = $orchestrator->launchCampaign($campaignId, $input['filters'] ?? [], true);
        echo json_encode(['success' => true, 'data' => $result]);
        exit;
    }

    if ($action === 'pause') {
        $orchestrator->pauseCampaign($campaignId);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'resume') {
        $orchestrator->resumeCampaign($campaignId);
        echo json_encode(['success' => true]);
        exit;
    }

    throw new RuntimeException('Unsupported action');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
