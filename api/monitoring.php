<?php
/**
 * Monitoring API Endpoint
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../public/index.php';

use CRM\Auth;
use CRM\Modules\SystemMonitor;
use CRM\Modules\PerformanceMonitor;
use CRM\Modules\ErrorTracker;
use CRM\Logger;

header('Content-Type: application/json');

// Require authentication
Auth::requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'health';
        
        switch ($action) {
            case 'health':
                $systemMonitor = new SystemMonitor();
                echo json_encode($systemMonitor->getHealthStatus(), JSON_PRETTY_PRINT);
                break;
                
            case 'performance':
                $performanceMonitor = new PerformanceMonitor();
                $queryStats = $performanceMonitor->getQueryStats(24);
                $apiStats = $performanceMonitor->getAPIStats(24);
                $cacheStats = $performanceMonitor->getCacheStats();
                
                echo json_encode([
                    'queries' => $queryStats,
                    'api' => $apiStats,
                    'cache' => $cacheStats
                ], JSON_PRETTY_PRINT);
                break;
                
            case 'errors':
                $errorTracker = new ErrorTracker();
                $stats = $errorTracker->getErrorStats(7);
                echo json_encode($stats, JSON_PRETTY_PRINT);
                break;
                
            case 'logs':
                $logger = new Logger();
                $level = $_GET['level'] ?? null;
                $limit = (int) ($_GET['limit'] ?? 100);
                $offset = (int) ($_GET['offset'] ?? 0);
                
                $logs = $logger->getLogs($level, $limit, $offset);
                echo json_encode(['logs' => $logs], JSON_PRETTY_PRINT);
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
