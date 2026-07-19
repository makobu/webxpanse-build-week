<?php

declare(strict_types=1);

require_once __DIR__ . '/../demo_access/_bootstrap.php';

use CRM\Auth;
use CRM\Security;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\DemoWorkspaceSeedService;
use CRM\Services\DemoWorkspaceService;

if (!Auth::check() || !(new DemoSessionScopeService())->canOperateDemo(Auth::user())) {
    demoJsonError('Forbidden', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    demoJsonError('Method not allowed', 405);
}

$input = demoJsonInput();
if (!Security::validateCSRF((string) ($input['csrf_token'] ?? ''))) {
    demoJsonError('Invalid security token', 403);
}

$workspaceId = (new DemoWorkspaceService())->id();
$result = (new DemoWorkspaceSeedService())->reseed($workspaceId, (int) (Auth::userId() ?? 0));

demoJsonResponse(['success' => true] + $result);
