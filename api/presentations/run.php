<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Authorization;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\PresentationDeliveryService;

$user = presentationRequire('presentation.workspace.manage');
$input = presentationJsonInput();
presentationValidateCsrf($input);

$operator = (new DemoSessionScopeService())->canOperateDemo($user);
$liveRequested = !empty($input['live']);
$canLive = $operator || Authorization::can('presentation.live_send', $user);
if ($liveRequested && !$canLive) {
    presentationJsonError('You do not have permission to request live presentation delivery.', 403);
}

try {
    $service = new PresentationDeliveryService();
    $sessionId = (int) ($input['session_id'] ?? 0);
    $scenarioKey = trim((string) ($input['scenario_key'] ?? ''));
    if (!empty($input['preview'])) {
        presentationJsonResponse($service->preview($sessionId, $scenarioKey, $input));
    }

    presentationJsonResponse($service->runScenario($sessionId, $scenarioKey, $input, (int) ($user['id'] ?? 0)));
} catch (\InvalidArgumentException $e) {
    presentationJsonError($e->getMessage(), 422);
} catch (\Throwable $e) {
    error_log('presentations/run failed: ' . $e->getMessage());
    presentationJsonError('Unable to run the presentation scenario.', 500);
}
