<?php
/**
 * Workflow Recommendations API
 * GET: Returns AI-powered workflow recommendations for the current user
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
use CRM\Modules\WorkflowRecommendationService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

try {
    $service = new WorkflowRecommendationService();
    $data = $service->getRecommendations($userId);
    echo json_encode([
        'recommended_templates' => $data['recommended_templates'] ?? [],
        'recommended_workflows' => $data['recommended_workflows'] ?? [],
        'ai_suggestions' => $data['ai_suggestions'] ?? [],
        'popular_templates' => $data['popular_templates'] ?? [],
    ]);
} catch (\Throwable $e) {
    error_log('Workflow recommendations API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to load recommendations',
        'recommended_templates' => [],
        'recommended_workflows' => [],
        'ai_suggestions' => [],
        'popular_templates' => [],
    ]);
}
