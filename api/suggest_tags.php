<?php
/**
 * Suggest tags for contact (AI)
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
use CRM\Modules\SmartTagging;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$contactId = (int) ($_GET['contact_id'] ?? $_POST['contact_id'] ?? 0);
if (!$contactId) {
    http_response_code(400);
    echo json_encode(['error' => 'contact_id required']);
    exit;
}

try {
    $smartTagging = new SmartTagging();
    $suggestions = $smartTagging->suggestTagsForContact($contactId);
    echo json_encode(['success' => true, 'tags' => $suggestions]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'tags' => []]);
}
