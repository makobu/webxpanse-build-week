<?php
/**
 * Apply suggested tags to contact
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
use CRM\Modules\Tags;

Database::init(require __DIR__ . '/../config/database.php');
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

if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$contactId = (int) ($_POST['contact_id'] ?? 0);
$tagNames = $_POST['tags'] ?? [];

if (!$contactId || !is_array($tagNames)) {
    http_response_code(400);
    echo json_encode(['error' => 'contact_id and tags[] required']);
    exit;
}

$tagsModule = new Tags();
$applied = 0;

foreach ($tagNames as $name) {
    $name = trim($name);
    if (empty($name)) continue;
    $tag = $tagsModule->getByName($name);
    if (!$tag) {
        try {
            $tagId = $tagsModule->create(['name' => $name]);
            $tag = ['id' => $tagId];
        } catch (\Exception $e) {
            continue;
        }
    } else {
        $tagId = $tag['id'];
    }
    if ($tagsModule->assign($tagId, 'contact', $contactId)) {
        $applied++;
    }
}

echo json_encode(['success' => true, 'applied' => $applied]);