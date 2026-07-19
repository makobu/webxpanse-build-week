<?php
/**
 * Beginner Budget API
 * GET: Return budget + computed values (break-even, max leads).
 * POST: Save budget.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Security;
use CRM\Modules\BeginnerBudget;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

$budgetModule = new BeginnerBudget();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $budget = $budgetModule->get($userId);
    $data = [
        'monthly_marketing_budget' => 0,
        'monthly_fixed_costs' => 0,
        'target_deal_value' => 0,
        'target_cac' => null,
        'currency_code' => 'USD',
        'break_even_deals' => null,
        'max_leads_at_cac' => null,
    ];
    if ($budget) {
        $data['monthly_marketing_budget'] = (float) ($budget['monthly_marketing_budget'] ?? 0);
        $data['monthly_fixed_costs'] = (float) ($budget['monthly_fixed_costs'] ?? 0);
        $data['target_deal_value'] = (float) ($budget['target_deal_value'] ?? 0);
        $data['target_cac'] = $budget['target_cac'] !== null ? (float) $budget['target_cac'] : null;
        $data['currency_code'] = $budget['currency_code'] ?? 'USD';
        $data['break_even_deals'] = $budgetModule->getBreakEvenDeals($userId);
        $data['max_leads_at_cac'] = $budgetModule->getMaxLeadsAtCac($userId);
    }
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST;
    if (empty($input) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];
    }

    $csrfToken = $input['csrf_token'] ?? '';
    if (!Security::validateCSRF($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }

    try {
        $budgetModule->save($userId, $input);
        $budget = $budgetModule->get($userId);
        echo json_encode([
            'success' => true,
            'monthly_marketing_budget' => (float) ($budget['monthly_marketing_budget'] ?? 0),
            'monthly_fixed_costs' => (float) ($budget['monthly_fixed_costs'] ?? 0),
            'target_deal_value' => (float) ($budget['target_deal_value'] ?? 0),
            'target_cac' => $budget['target_cac'] !== null ? (float) $budget['target_cac'] : null,
            'currency_code' => $budget['currency_code'] ?? 'USD',
            'break_even_deals' => $budgetModule->getBreakEvenDeals($userId),
            'max_leads_at_cac' => $budgetModule->getMaxLeadsAtCac($userId),
        ]);
    } catch (\Throwable $e) {
        error_log('Beginner budget save error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save budget']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
