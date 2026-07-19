<?php
/**
 * WhatsApp Queue Worker (CLI - optional)
 *
 * Runs continuously to process the queue. For most users, the "Send pending
 * messages" button on the WhatsApp Messages page is easier - no terminal needed.
 *
 * To run: php cli/whatsapp_worker.php
 * To stop: Ctrl+C or bash cli/stop_whatsapp_worker.sh
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\WhatsAppQueueProcessor;
use CRM\Services\QueueWorkerHeartbeatService;

Database::init(require __DIR__ . '/../config/database.php');

$processor = new WhatsAppQueueProcessor();
$heartbeat = new QueueWorkerHeartbeatService('whatsapp_worker');
$heartbeat->start(['mode' => 'continuous']);
register_shutdown_function(static function () use ($heartbeat): void {
    try {
        $heartbeat->stop();
    } catch (\Throwable $e) {
    }
});

echo "WhatsApp worker started. Use the 'Send pending messages' button in the app for easier use.\n";

while (true) {
    $heartbeat->touch();
    $stats = $processor->process(1);
    $heartbeat->touch(['last_processed' => (int) ($stats['processed'] ?? 0)], true);

    if ($stats['processed'] === 0) {
        sleep(5);
    }
}
