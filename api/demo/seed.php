<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Auth;
use CRM\Modules\DemoModeManager;
use CRM\Security;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$csrf = (string) ($input['csrf_token'] ?? $_POST['csrf_token'] ?? '');
if (!Security::validateCSRF($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$runId = (int) ($input['run_id'] ?? $_POST['run_id'] ?? 0);
$profile = (string) ($input['seed_profile'] ?? $_POST['seed_profile'] ?? 'full');
$adminUserId = (int) (Auth::user()['id'] ?? 0);

$manager = new DemoModeManager();
$result = $manager->seed($runId, $adminUserId, $profile);
if (!($result['success'] ?? false)) {
    http_response_code(400);
}
echo json_encode($result);
