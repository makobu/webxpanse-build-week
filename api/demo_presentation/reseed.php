<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Services\DemoWorkspaceService;
use CRM\Services\MetroDriveDemoSeedService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    demoJsonError('Method not allowed', 405);
}

$user = demoPresentationRequire('demo.presentation.create');
$input = demoJsonInput();
demoPresentationValidateCsrf($input);

try {
    $workspaceId = (new DemoWorkspaceService())->metroDriveId();
    $result = (new MetroDriveDemoSeedService())->reseed($workspaceId, (int) ($user['id'] ?? 0));
    demoJsonResponse(['success' => true] + $result);
} catch (\Throwable $e) {
    error_log('demo_presentation/reseed failed: ' . $e->getMessage());
    demoJsonError('Unable to reseed MetroDrive Academy.', 500);
}
