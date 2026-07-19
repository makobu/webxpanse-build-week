<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Services\AIOperatingContextService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int) (Auth::user()['id'] ?? 0);
$surface = (string) ($_GET['surface'] ?? 'system');
$currentPage = (string) ($_GET['current_page'] ?? '');

$service = new AIOperatingContextService();
echo json_encode([
    'success' => true,
    'context' => $service->buildForSurface($userId, $surface, ['current_page' => $currentPage]),
]);
