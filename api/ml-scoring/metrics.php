<?php
/**
 * ML Metrics API
 * Get model performance metrics
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
use CRM\Modules\MLModelManager;
use CRM\Services\MLOutcomeTracker;
use CRM\Services\AnalyticsWorkspaceService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $workspaceId = (new AnalyticsWorkspaceService())->requireAnalyticsWorkspaceId();
    $modelType = $_GET['model_type'] ?? null;
    $modelId = isset($_GET['model_id']) ? (int) $_GET['model_id'] : null;
    
    $modelManager = new MLModelManager();
    $outcomeTracker = new MLOutcomeTracker();
    
    if ($modelId) {
        // Get metrics for specific model
        $model = $modelManager->getModel($modelId, $workspaceId);
        if (!$model) {
            throw new \Exception('Model not found');
        }
        
        $metrics = Database::query(
            "SELECT metric_name, metric_value, calculated_at
             FROM ml_model_metrics
             WHERE workspace_id = ? AND model_id = ?
             ORDER BY calculated_at DESC",
            [$workspaceId, $modelId]
        );
        
        $accuracy = $outcomeTracker->getPredictionAccuracy($model['model_type']);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'model' => $model,
                'metrics' => $metrics,
                'accuracy' => $accuracy
            ]
        ]);
        
    } elseif ($modelType) {
        // Get metrics for model type
        $activeModel = $modelManager->getActiveModel($modelType, $workspaceId);
        $accuracy = $outcomeTracker->getPredictionAccuracy($modelType);
        $history = $modelManager->getPerformanceHistory($modelType, $workspaceId);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'active_model' => $activeModel,
                'accuracy' => $accuracy,
                'history' => $history
            ]
        ]);
        
    } else {
        // Get all metrics
        $conversionModel = $modelManager->getActiveModel('conversion', $workspaceId);
        $churnModel = $modelManager->getActiveModel('churn', $workspaceId);
        $engagementModel = $modelManager->getActiveModel('engagement', $workspaceId);
        
        $conversionAccuracy = $outcomeTracker->getPredictionAccuracy('conversion');
        $churnAccuracy = $outcomeTracker->getPredictionAccuracy('churn');
        
        echo json_encode([
            'success' => true,
            'data' => [
                'conversion' => [
                    'model' => $conversionModel,
                    'accuracy' => $conversionAccuracy
                ],
                'churn' => [
                    'model' => $churnModel,
                    'accuracy' => $churnAccuracy
                ],
                'engagement' => [
                    'model' => $engagementModel
                ]
            ]
        ]);
    }
    
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
