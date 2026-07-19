<?php
/**
 * Users Autocomplete API for @mentions
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
use CRM\Security;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

// Require authentication
if (!Auth::check()) {
    echo json_encode(['error' => 'Unauthorized'], 401);
    exit;
}

$query = $_GET['q'] ?? '';
$limit = (int) ($_GET['limit'] ?? 10);

if (empty($query)) {
    echo json_encode([]);
    exit;
}

$searchTerm = '%' . Security::sanitizeInput($query, 'string') . '%';

$users = Database::query(
    "SELECT id, email 
     FROM users 
     WHERE email LIKE ? 
     ORDER BY email ASC 
     LIMIT ?",
    [$searchTerm, $limit]
);

$results = [];
foreach ($users as $user) {
    $email = $user['email'];
    $username = explode('@', $email)[0]; // Get part before @
    
    $results[] = [
        'id' => (int) $user['id'],
        'email' => $email,
        'username' => $username,
        'display' => $email,
        'mention' => '@' . $username // For autocomplete
    ];
}

echo json_encode($results);
exit;
