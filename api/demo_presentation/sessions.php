<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Services\DemoPresentationSessionService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    demoJsonError('Method not allowed', 405);
}

$user = demoPresentationRequire('demo.presentation.create');
$input = demoJsonInput();
demoPresentationValidateCsrf($input);

try {
    $result = (new DemoPresentationSessionService())->create($input, (int) ($user['id'] ?? 0));
    demoJsonResponse($result);
} catch (\InvalidArgumentException $e) {
    demoJsonError($e->getMessage(), 422);
} catch (\Throwable $e) {
    error_log('demo_presentation/sessions failed: ' . $e->getMessage());
    demoJsonError('Unable to create the presentation demo session.', 500);
}
