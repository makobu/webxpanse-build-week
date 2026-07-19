<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use CRM\Session;

$sessionId = (string) ($argv[1] ?? '');
$mode = (string) ($argv[2] ?? 'release');
$holdMilliseconds = max(10, min(2000, (int) ($argv[3] ?? 250)));
if (!preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sessionId) || !in_array($mode, ['hold', 'release'], true)) {
    fwrite(STDERR, "Invalid session-lock probe arguments.\n");
    exit(2);
}

$startedAt = hrtime(true);
session_id($sessionId);
Session::start();
if ($mode === 'release') {
    Session::closeWrite();
}
usleep($holdMilliseconds * 1000);
Session::closeWrite();

echo json_encode([
    'mode' => $mode,
    'elapsed_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
