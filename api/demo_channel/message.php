<?php

declare(strict_types=1);

require_once __DIR__ . '/../demo_access/_bootstrap.php';

use CRM\Auth;
use CRM\Security;
use CRM\Services\DemoChannelSimulatorService;
use CRM\Services\DemoSessionScopeService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    demoJsonError('Method not allowed', 405);
}

if (!Auth::check()) {
    demoJsonError('Unauthorized', 401);
}

$input = demoJsonInput();
$csrf = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if ($csrf === '' || !Security::validateCSRF($csrf)) {
    demoJsonError('Invalid security token', 403);
}

try {
    (new DemoSessionScopeService())->requireActiveSession();
    demoJsonResponse((new DemoChannelSimulatorService())->simulate($input));
} catch (\InvalidArgumentException $e) {
    demoJsonError($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    demoJsonError($e->getMessage(), 403);
} catch (\Throwable $e) {
    error_log('demo_channel/message failed: ' . $e->getMessage());
    demoJsonError('Unable to simulate demo message.', 500);
}
