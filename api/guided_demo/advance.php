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

$direction = (string) ($_POST['direction'] ?? 'next');
$direction = $direction === 'back' ? 'back' : 'next';

try {
    $payload = (new GuidedDemoSessionService())->advance($guidedDemoWorkspaceId, $guidedDemoUserId, $direction);
    guidedDemoJson(['success' => true, 'data' => $payload]);
} catch (Throwable $e) {
    guidedDemoJson(['success' => false, 'error' => $e->getMessage()], 422);
}
