<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Security;
use CRM\Services\GuidedDemoSessionService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    guidedDemoJson(['success' => false, 'error' => 'Method not allowed'], 405);
}
if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    guidedDemoJson(['success' => false, 'error' => 'Invalid security token.'], 403);
}

$service = new GuidedDemoSessionService();
$state = $service->state($guidedDemoWorkspaceId, $guidedDemoUserId);
if ($state === null) {
    guidedDemoJson(['success' => true, 'data' => null]);
}

$session = (array) ($state['session'] ?? []);
$step = (array) ($state['step'] ?? []);
$eventType = (string) ($_POST['event_type'] ?? 'shown');
if (!in_array($eventType, ['shown', 'fallback_target'], true)) {
    $eventType = 'shown';
}

$service->recordEvent(
    (int) ($session['id'] ?? 0),
    $guidedDemoWorkspaceId,
    $guidedDemoUserId,
    (string) ($step['key'] ?? ''),
    $eventType,
    basename(parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?: ''),
    ['target' => (string) ($_POST['target'] ?? '')]
);

guidedDemoJson(['success' => true]);
