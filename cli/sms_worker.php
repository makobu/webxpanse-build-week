<?php
/**
 * SMS Queue Worker
 * 
 * Processes SMS messages from the queue
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
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
use CRM\Services\AsyncWorkspaceRunner;
use CRM\Services\SMSService;
use CRM\Services\SMSQueue;
use CRM\Services\QueueWorkerHeartbeatService;

Database::init(require __DIR__ . '/../config/database.php');

$smsService = new SMSService();
$queue = new SMSQueue();
$heartbeat = new QueueWorkerHeartbeatService('sms_worker');
$heartbeat->start(['queue' => 'sms_queue']);
register_shutdown_function(static function () use ($heartbeat): void {
    try {
        $heartbeat->stop('stopped');
    } catch (\Throwable $e) {
    }
});

echo "SMS Worker started...\n";

while (true) {
    try {
        $heartbeat->touch();
        $message = $queue->pop();
        
        if (!$message) {
            sleep(5); // Wait 5 seconds if no messages
            continue;
        }
        
        $queueId = $message['queue_id'];
        $messageId = $message['message_id'];
        
        echo "Processing SMS message ID: {$messageId}\n";
        
        try {
            $workspaceId = (int) ($message['workspace_id'] ?? $message['queue_workspace_id'] ?? 0);
            $result = AsyncWorkspaceRunner::runWithWorkspace(
                $workspaceId,
                function () use ($smsService, $message, $workspaceId): array {
                    return $smsService->sendSMS(
                        $message['to_number'],
                        $message['message_body'],
                        [
                            'workspace_id' => $workspaceId,
                            'media_url' => $message['media_url'] ?? null,
                            'rate_reserved' => true,
                        ]
                    );
                },
                null,
                'SMS queue item is missing a valid workspace.'
            );
            
            // Update message status
            Database::execute(
                "UPDATE sms_messages SET status = 'sent', provider_message_id = ? WHERE workspace_id = ? AND id = ?",
                [$result['sid'] ?? '', $workspaceId, $messageId]
            );
            
            // Mark queue as completed
            $queue->complete($queueId, $workspaceId, (string) ($message['claim_token'] ?? ''));
            
            echo "SMS sent successfully. SID: " . ($result['sid'] ?? 'N/A') . "\n";
            
        } catch (\Throwable $e) {
            echo "Error sending SMS: " . $e->getMessage() . "\n";
            $queue->fail(
                $queueId,
                $e->getMessage(),
                (int) ($message['workspace_id'] ?? $message['queue_workspace_id'] ?? 0),
                (string) ($message['claim_token'] ?? '')
            );
        } finally {
        }
        
        sleep(1); // Small delay between messages
        
    } catch (\Throwable $e) {
        echo "Worker error: " . $e->getMessage() . "\n";
        sleep(5);
    }
}
