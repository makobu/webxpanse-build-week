<?php
/**
 * WhatsApp Personal Assistant digest worker.
 *
 * Safe to run from cron every few minutes. It sends each mapped user's
 * WhatsApp digest once per day after the configured send time.
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
use CRM\Services\WhatsAppAssistantDigestService;

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
$jobHealth->markStarted('whatsapp_assistant_digest', ['force' => $force]);
$service = new WhatsAppAssistantDigestService();
try {
    $result = $service->runScheduledForWorkspaces($force, new \DateTimeImmutable('now'));
} catch (\Throwable $e) {
    $jobHealth->markFailure('whatsapp_assistant_digest', $e->getMessage(), (int) round((microtime(true) - $startedAt) * 1000));
    throw $e;
}

if (empty($result['ran'])) {
    $message = "[" . date('Y-m-d H:i:s') . "] WhatsApp digest worker skipped: " . (string) ($result['reason'] ?? 'not_due');
    if (!empty($result['message'])) {
        $message .= " - " . (string) $result['message'];
    }
    $jobHealth->markSuccess('whatsapp_assistant_digest', (string) ($result['reason'] ?? 'not_due'), (int) round((microtime(true) - $startedAt) * 1000), $result);
    echo $message . "\n";
    exit(0);
}

foreach ((array) ($result['results'] ?? []) as $row) {
    $phoneNumber = (string) ($row['phone_number'] ?? '');
    $status = (string) ($row['status'] ?? 'unknown');
    if ($status === 'sent') {
        echo "[" . date('Y-m-d H:i:s') . "] Sent WhatsApp digest to {$phoneNumber}\n";
    } elseif ($status === 'failed') {
        $error = (string) ($row['error'] ?? 'unknown error');
        error_log("WhatsApp digest error for {$phoneNumber}: " . $error);
        echo "[" . date('Y-m-d H:i:s') . "] Failed for {$phoneNumber}: {$error}\n";
    } elseif ($status === 'skipped') {
        echo "[" . date('Y-m-d H:i:s') . "] Skipped {$phoneNumber}: " . (string) ($row['reason'] ?? 'skipped') . "\n";
    }
}

$jobHealth->markSuccess(
    'whatsapp_assistant_digest',
    'Sent: ' . (int) ($result['sent'] ?? 0) . ', Failed: ' . (int) ($result['failed'] ?? 0) . ', Skipped: ' . (int) ($result['skipped'] ?? 0),
    (int) round((microtime(true) - $startedAt) * 1000),
    $result
);
echo "[" . date('Y-m-d H:i:s') . "] WhatsApp digest completed. Sent: " . (int) ($result['sent'] ?? 0) . ", Failed: " . (int) ($result['failed'] ?? 0) . ", Skipped: " . (int) ($result['skipped'] ?? 0) . "\n";
exit(0);
