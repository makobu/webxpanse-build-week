<?php
/**
 * WhatsApp Personal Assistant session maintenance worker.
 *
 * Safe to run from cron every few minutes. It sends keepalive reminders and
 * reopen templates based on each mapped number's 24-hour session state.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\AutomationJobHealthService;
use CRM\Services\WhatsAppAssistantSessionService;

$dbConfig = require __DIR__ . '/../config/database.php';
Database::init($dbConfig);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from command line.\n";
    exit(1);
}

$force = in_array('--force', $argv ?? [], true);
$startedAt = microtime(true);
$jobHealth = new AutomationJobHealthService();
$jobHealth->markStarted('whatsapp_assistant_session', ['force' => $force]);
$service = new WhatsAppAssistantSessionService();

try {
    $result = $service->runMaintenanceForWorkspaces($force, new \DateTimeImmutable('now'));
} catch (\Throwable $e) {
    $jobHealth->markFailure('whatsapp_assistant_session', $e->getMessage(), (int) round((microtime(true) - $startedAt) * 1000));
    throw $e;
}

foreach ((array) ($result['results'] ?? []) as $row) {
    $type = (string) ($row['type'] ?? 'session');
    $phoneNumber = (string) ($row['phone_number'] ?? '');
    $status = (string) ($row['status'] ?? 'unknown');
    if ($status === 'success') {
        echo "[" . date('Y-m-d H:i:s') . "] {$type} ok for {$phoneNumber}\n";
    } elseif ($status === 'failed') {
        $error = (string) ($row['error'] ?? 'unknown error');
        error_log("WhatsApp assistant session worker error for {$phoneNumber}: " . $error);
        echo "[" . date('Y-m-d H:i:s') . "] {$type} failed for {$phoneNumber}: {$error}\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] {$type} skipped for {$phoneNumber}\n";
    }
}

$jobHealth->markSuccess(
    'whatsapp_assistant_session',
    'Reminders: ' . (int) ($result['reminders'] ?? 0) . ', Reopens: ' . (int) ($result['reopens'] ?? 0) . ', Failed: ' . (int) ($result['failures'] ?? 0),
    (int) round((microtime(true) - $startedAt) * 1000),
    $result
);

echo "[" . date('Y-m-d H:i:s') . "] WhatsApp assistant session worker completed. Reminders: " . (int) ($result['reminders'] ?? 0) . ", Reopens: " . (int) ($result['reopens'] ?? 0) . ", Failed: " . (int) ($result['failures'] ?? 0) . ", Skipped: " . (int) ($result['skipped'] ?? 0) . "\n";
exit(0);
