<?php
/**
 * Predictive Analytics API Endpoint
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../public/index.php';

use CRM\Auth;
use CRM\Modules\PredictiveAnalytics;
use CRM\Security;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\WorkspaceBusinessIntelligenceGateService;

header('Content-Type: application/json');

// Require authentication
Auth::requireAuth();

$user = Auth::user();
$workspaceId = (new AnalyticsWorkspaceService())->requireAnalyticsWorkspaceId();
(new WorkspaceBusinessIntelligenceGateService())->enforceJson($workspaceId, $user, 'Predictive Analytics');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$analytics = new PredictiveAnalytics();

try {
    switch ($method) {
        case 'GET':
            $action = $_GET['action'] ?? 'dashboard';
            $contactId = $_GET['contact_id'] ?? null;
            
            switch ($action) {
                case 'conversion_prediction':
                    if (!$contactId) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Contact ID required']);
                        break;
                    }
                    $prediction = $analytics->predictLeadConversion((int) $contactId);
                    echo json_encode($prediction, JSON_PRETTY_PRINT);
                    break;
                    
                case 'churn_prediction':
                    if (!$contactId) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Contact ID required']);
                        break;
                    }
                    $prediction = $analytics->predictChurn((int) $contactId);
                    echo json_encode($prediction, JSON_PRETTY_PRINT);
                    break;
                    
                case 'revenue_forecast':
                    $period = $_GET['period'] ?? 'month';
                    $months = (int) ($_GET['months'] ?? 3);
                    $forecast = $analytics->forecastRevenue($period, $months);
                    echo json_encode($forecast, JSON_PRETTY_PRINT);
                    break;
                    
                case 'clv':
                    if (!$contactId) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Contact ID required']);
                        break;
                    }
                    $clv = $analytics->calculateCLV((int) $contactId);
                    echo json_encode($clv, JSON_PRETTY_PRINT);
                    break;
                    
                case 'dashboard':
                default:
                    $dashboard = $analytics->getDashboardData();
                    echo json_encode($dashboard, JSON_PRETTY_PRINT);
                    break;
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
