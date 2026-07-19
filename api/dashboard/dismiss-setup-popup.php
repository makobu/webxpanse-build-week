<?php
/**
 * Persist dismissal for dashboard setup surfaces.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Modules\UserPreferences;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = $_POST;
if (empty($input) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$csrfToken = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

$userId = (int) (Auth::user()['id'] ?? 0);
$dismissType = (string) ($input['dismiss_type'] ?? '');
$preferenceKey = 'dashboard_setup_popup_dismissed';

if ($dismissType === 'setup_complete') {
    $workspaceId = (int) ($_SESSION['active_workspace_id'] ?? 0);
    if ($workspaceId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Workspace context is required']);
        exit;
    }
    $preferenceKey = 'dashboard_setup_complete_dismissed:' . $workspaceId;
}

(new UserPreferences())->setPreference($userId, $preferenceKey, '1');

echo json_encode(['success' => true]);
