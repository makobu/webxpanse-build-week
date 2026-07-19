<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Services\GuidedDemoSessionService;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    guidedDemoJson(['success' => false, 'error' => 'Method not allowed'], 405);
}

$payload = (new GuidedDemoSessionService())->state($guidedDemoWorkspaceId, $guidedDemoUserId);
if ($payload === null) {
    guidedDemoJson(['success' => true, 'data' => null]);
}

guidedDemoJson(['success' => true, 'data' => $payload]);
