<?php

declare(strict_types=1);

require_once __DIR__ . '/../demo_access/_bootstrap.php';

use CRM\Auth;
use CRM\Security;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\DemoSessionService;

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

$sessionId = (int) ($input['session_id'] ?? 0);
demoJsonResponse(['success' => (new DemoSessionService())->revoke($sessionId)]);
