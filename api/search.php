<?php
/**
 * Search API
 * Returns JSON for AJAX search requests
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
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
use CRM\Modules\Search;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
Session::closeWrite();

$searchModule = new Search();
$action = $_GET['action'] ?? 'suggestions';
$query = trim($_GET['q'] ?? '');
$mode = $_GET['mode'] ?? '';

if (empty($query)) {
    echo json_encode(['suggestions' => []]);
    exit;
}

if ($action === 'search' && $mode === 'semantic') {
    $semantic = new \CRM\Modules\SemanticSearch();
    $results = $semantic->search($query, 20);
    echo json_encode(['results' => $results, 'mode' => 'semantic']);
    exit;
}

if ($action === 'suggestions') {
    $suggestions = $searchModule->getSuggestions($query, 5);
    
    // Format for JSON
    $formatted = [];
    foreach ($suggestions as $suggestion) {
        $formatted[] = [
            'id' => $suggestion['id'],
            'title' => $suggestion['title'],
            'subtitle' => $suggestion['subtitle'],
            'type' => $suggestion['type'],
            'url' => $suggestion['url'],
            'icon' => $searchModule->getEntityIcon($suggestion['type'])
        ];
    }
    
    echo json_encode(['suggestions' => $formatted]);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}
