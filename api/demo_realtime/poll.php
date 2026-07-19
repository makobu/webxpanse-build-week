<?php

declare(strict_types=1);

require_once __DIR__ . '/../demo_access/_bootstrap.php';

use CRM\Auth;
use CRM\Services\DemoRealtimeEventService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\DemoSessionService;

if (!Auth::check()) {
    demoJsonError('Unauthorized', 401);
}

try {
    $session = (new DemoSessionScopeService())->requireActiveSession();
    (new DemoSessionService())->touch((int) $session['id']);
    $afterId = (int) ($_GET['after_id'] ?? $_GET['after'] ?? 0);
    $events = (new DemoRealtimeEventService())->poll($session, $afterId);
    demoJsonResponse([
        'success' => true,
        'events' => $events,
        'last_event_id' => $events !== [] ? (int) end($events)['id'] : $afterId,
    ]);
} catch (\RuntimeException $e) {
    demoJsonError($e->getMessage(), 403);
} catch (\Throwable $e) {
    error_log('demo_realtime/poll failed: ' . $e->getMessage());
    demoJsonError('Unable to poll demo events.', 500);
}
