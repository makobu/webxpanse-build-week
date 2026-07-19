<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Security;
use CRM\Services\GuidedDemoSessionService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    guidedDemoJson(['success' => false, 'error' => 'Method not allowed'], 405);
}
if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    guidedDemoJson(['success' => false, 'error' => 'Invalid security token.'], 403);
}

$reason = (string) ($_POST['reason'] ?? 'completed');
$reason = $reason === 'exited' ? 'exited' : 'completed';

try {
    $payload = (new GuidedDemoSessionService())->finish($guidedDemoWorkspaceId, $guidedDemoUserId, $reason);
    guidedDemoJson(['success' => true, 'data' => $payload]);
} catch (Throwable $e) {
    guidedDemoJson(['success' => false, 'error' => 'Could not open your dashboard yet. Try again.'], 500);
}
