<?php
/**
 * Deal Intelligence API
 * AI next steps, summary, close date prediction
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
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Modules\AIDealIntelligence;
use CRM\Services\AIExecutionStatusService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$dealId = (int) ($_GET['deal_id'] ?? $_POST['deal_id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? 'next_steps';

if (!$dealId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'deal_id required']);
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

try {
    $intel = new AIDealIntelligence();
    switch ($action) {
        case 'next_steps':
            $result = $intel->getNextSteps($dealId, $userId);
            echo json_encode([
                'success' => true,
                'next_steps' => $result['next_steps'] ?? [],
                'ai_status' => (new AIExecutionStatusService())->present($intel->getLastProviderStatus(), ['surface' => 'commercial_assistant']),
            ]);
            break;
        case 'summary':
            $summary = $intel->getDealSummary($dealId, $userId);
            echo json_encode([
                'success' => true,
                'summary' => $summary,
                'ai_status' => (new AIExecutionStatusService())->present($intel->getLastProviderStatus(), ['surface' => 'commercial_assistant']),
            ]);
            break;
        case 'close_date':
            $prediction = $intel->predictCloseDate($dealId, $userId);
            echo json_encode([
                'success' => true,
                'prediction' => $prediction,
                'ai_status' => (new AIExecutionStatusService())->present($intel->getLastProviderStatus(), ['surface' => 'commercial_assistant']),
            ]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action. Use next_steps, summary, or close_date']);
    }
} catch (\Exception $e) {
    error_log('Deal intelligence API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Deal intelligence could not complete this request.',
        'ai_status' => (new AIExecutionStatusService())->present([], [
            'surface' => 'commercial_assistant',
            'blocked_reason' => 'request_failed',
            'message' => 'Deal intelligence could not complete this request.',
        ]),
    ]);
}
