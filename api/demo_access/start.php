<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Security;
use CRM\Services\DemoSessionService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    demoJsonError('Method not allowed', 405);
}

try {
    $input = demoJsonInput();
    if (!Security::validateCSRF((string) ($input['csrf_token'] ?? ''))) {
        demoJsonError('Invalid security token', 403);
    }

    $state = (new DemoSessionService())->start($input);
    demoJsonResponse(['success' => true, 'demo' => $state]);
} catch (\InvalidArgumentException $e) {
    demoJsonError($e->getMessage(), 422);
} catch (\Throwable $e) {
    error_log('demo_access/start failed: ' . $e->getMessage());
    demoJsonError('Unable to start the demo right now.', 500);
}
