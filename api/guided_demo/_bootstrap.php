<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/env.php';

loadEnvFile(__DIR__ . '/../../.env');
require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!function_exists('guidedDemoJson')) {
    function guidedDemoJson(array $payload, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!Auth::check()) {
    guidedDemoJson(['success' => false, 'error' => 'Unauthorized'], 401);
}

$guidedDemoUser = Auth::user() ?: [];
$guidedDemoUserId = (int) ($guidedDemoUser['id'] ?? 0);
$guidedDemoWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($guidedDemoWorkspaceId <= 0 || $guidedDemoUserId <= 0) {
    guidedDemoJson(['success' => false, 'error' => 'Workspace context is required.'], 422);
}
