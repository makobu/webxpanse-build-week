<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Services\DemoPresentationSessionService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    demoJsonError('Method not allowed', 405);
}

$user = demoPresentationRequire('demo.presentation.revoke');
$input = demoJsonInput();
demoPresentationValidateCsrf($input);

try {
    demoJsonResponse((new DemoPresentationSessionService())->revoke((int) ($input['session_id'] ?? 0), (int) ($user['id'] ?? 0)));
} catch (\InvalidArgumentException $e) {
    demoJsonError($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    demoJsonError($e->getMessage(), 403);
} catch (\Throwable $e) {
    error_log('demo_presentation/revoke failed: ' . $e->getMessage());
    demoJsonError('Unable to revoke the presentation session.', 500);
}
