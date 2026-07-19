<?php

declare(strict_types=1);

require_once __DIR__ . '/../demo_access/_bootstrap.php';

use CRM\Auth;
use CRM\Services\DemoRealtimeEventService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\DemoSessionService;

if (!Auth::check()) {
    http_response_code(401);
    echo "event: error\ndata: {\"error\":\"Unauthorized\"}\n\n";
    exit;
}

try {
    $session = (new DemoSessionScopeService())->requireActiveSession();
} catch (\Throwable $e) {
    http_response_code(403);
    echo "event: error\ndata: {\"error\":\"An active demo session is required.\"}\n\n";
    exit;
}

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$events = new DemoRealtimeEventService();
$sessions = new DemoSessionService();
$afterId = (int) ($_GET['after_id'] ?? $_SERVER['HTTP_LAST_EVENT_ID'] ?? 0);
$deadline = time() + 25;

while (time() < $deadline) {
    $rows = $events->poll($session, $afterId, 25);
    foreach ($rows as $row) {
        $afterId = (int) $row['id'];
        echo 'id: ' . $afterId . "\n";
        echo 'event: ' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) $row['event_type']) . "\n";
        echo 'data: ' . json_encode($row, JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    $sessions->touch((int) $session['id']);
    echo ": keepalive\n\n";
    @ob_flush();
    @flush();
    if (connection_aborted()) {
        break;
    }
    sleep(2);
}
