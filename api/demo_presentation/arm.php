<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Services\DemoPresentationSessionService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    demoJsonError('Method not allowed', 405);
}

$user = demoPresentationRequire('demo.presentation.live_send');
$input = demoJsonInput();
demoPresentationValidateCsrf($input);

try {
    $sessionId = (int) ($input['session_id'] ?? 0);
    $armed = !empty($input['armed']);
    demoJsonResponse((new DemoPresentationSessionService())->arm($sessionId, (int) ($user['id'] ?? 0), $armed));
} catch (\InvalidArgumentException $e) {
    demoJsonError($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    demoJsonError($e->getMessage(), 403);
} catch (\Throwable $e) {
    error_log('demo_presentation/arm failed: ' . $e->getMessage());
    demoJsonError('Unable to update live presentation mode.', 500);
}
