<?php
/**
 * Activities API Endpoint
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment and initialize
require_once __DIR__ . '/../public/index.php';

use CRM\Auth;
use CRM\Modules\Activities;
use CRM\Modules\ActivityTimeline;
use CRM\Security;

header('Content-Type: application/json');

// Require authentication
Auth::requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$activities = new Activities();
$timeline = new ActivityTimeline();

try {
    switch ($method) {
        case 'GET':
            $id = $_GET['id'] ?? null;
            $contactId = $_GET['contact_id'] ?? null;
            $type = $_GET['type'] ?? null;
            $limit = (int) ($_GET['limit'] ?? 50);
            $offset = (int) ($_GET['offset'] ?? 0);
            
            if ($id) {
                $activity = $activities->getById((int) $id);
                echo json_encode($activity ?: ['error' => 'Activity not found'], JSON_PRETTY_PRINT);
            } elseif ($contactId) {
                if (isset($_GET['timeline']) && $_GET['timeline'] === 'true') {
                    $timelineData = $timeline->getTimeline((int) $contactId, $limit);
                    echo json_encode(['timeline' => $timelineData], JSON_PRETTY_PRINT);
                } else {
                    $results = $activities->getByContact((int) $contactId, $limit, $offset);
                    echo json_encode(['activities' => $results], JSON_PRETTY_PRINT);
                }
            } elseif ($type) {
                $results = $activities->getByType($type, $limit, $offset);
                echo json_encode(['activities' => $results], JSON_PRETTY_PRINT);
            } else {
                $results = $activities->getRecent($limit);
                echo json_encode(['activities' => $results], JSON_PRETTY_PRINT);
            }
            break;
            
        case 'POST':
            // Verify CSRF token
            $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!Security::validateCSRF($csrfToken)) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid CSRF token']);
                break;
            }
            
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            $required = ['contact_id', 'activity_type'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Missing required field: $field"]);
                    exit;
                }
            }
            
            $activityId = $activities->log(
                (int) $data['contact_id'],
                $data['activity_type'],
                $data['description'] ?? null,
                $data['metadata'] ?? [],
                $data['user_id'] ?? null
            );
            
            http_response_code(201);
            echo json_encode(['id' => $activityId, 'success' => true], JSON_PRETTY_PRINT);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
