<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Services\DemoSessionService;

try {
    demoJsonResponse(['success' => true, 'demo' => (new DemoSessionService())->state()]);
} catch (\Throwable $e) {
    error_log('demo_access/state failed: ' . $e->getMessage());
    demoJsonError('Unable to read demo state.', 500);
}
