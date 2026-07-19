<?php
/**
 * ML Score Recalculation API
 * Recalculate ML scores for all contacts
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Modules\AILeadScoring;
use CRM\Services\AnalyticsWorkspaceService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
Authorization::requirePermission('feature.ml_training', true);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new \Exception('POST method required');
    }

    (new AnalyticsWorkspaceService())->requireAnalyticsWorkspaceId();
    
    $scoringService = new AILeadScoring();
    $result = $scoringService->recalculateAllScores('conversion', 1000);
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
