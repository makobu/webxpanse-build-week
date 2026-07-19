<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Services\DemoPresentationDeliveryService;
use CRM\Services\DemoSessionScopeService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    demoJsonError('Method not allowed', 405);
}

if (!Auth::check()) {
    demoJsonError('Unauthorized', 401);
}

$input = demoJsonInput();
demoPresentationValidateCsrf($input);

$user = Auth::user() ?: [];
$live = !empty($input['live']);
$operator = (new DemoSessionScopeService())->canOperateDemo($user);
$canRun = $operator || Authorization::can('demo.presentation.create', $user);
$canLive = $operator || Authorization::can('demo.presentation.live_send', $user);
if (!$canRun || ($live && !$canLive)) {
    demoJsonError('Forbidden', 403);
}

try {
    $sessionId = (int) ($input['session_id'] ?? 0);
    $scenarioKey = trim((string) ($input['scenario_key'] ?? ''));
    if ($scenarioKey === '') {
        demoJsonError('Scenario is required.', 422);
    }
    $service = new DemoPresentationDeliveryService();
    if (!empty($input['preview'])) {
        demoJsonResponse($service->preview($sessionId, $scenarioKey, $input));
    }

    demoJsonResponse($service->runScenario($sessionId, $scenarioKey, $input, (int) ($user['id'] ?? 0)));
} catch (\InvalidArgumentException $e) {
    demoJsonError($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    demoJsonError($e->getMessage(), 403);
} catch (\Throwable $e) {
    error_log('demo_presentation/run failed: ' . $e->getMessage());
    demoJsonError('Unable to run the presentation scenario.', 500);
}
