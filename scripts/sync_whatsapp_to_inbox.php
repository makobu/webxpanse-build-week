<?php
/**
 * Sync existing WhatsApp messages from whatsapp_messages to communications table
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

Database::init(require __DIR__ . '/../config/database.php');

echo "=== Syncing WhatsApp Messages to Communications Table ===\n\n";

// Get inbound WhatsApp messages that don't have a corresponding communication (inbox backfill)
$messages = Database::query(
    "SELECT wm.*, wm.uuid as whatsapp_uuid
     FROM whatsapp_messages wm
     LEFT JOIN communications c ON JSON_UNQUOTE(JSON_EXTRACT(c.metadata, '$.whatsapp_uuid')) = wm.uuid
     WHERE c.id IS NULL AND wm.direction = 'inbound'
     ORDER BY wm.created_at ASC"
);

$total = count($messages);
echo "Found {$total} messages to sync.\n\n";

if ($total === 0) {
    echo "✅ All messages are already synced!\n";
    exit;
}

$synced = 0;
$errors = 0;

foreach ($messages as $msg) {
    try {
        // Generate new UUID for communication
        $commUuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        
        // Determine subject and status
        $subject = 'WhatsApp Message';
        if ($msg['message_type'] === 'template' && !empty($msg['template_name'])) {
            $subject = 'WhatsApp Template: ' . $msg['template_name'];
        }
        
        $status = match($msg['status']) {
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
            default => 'sent'
        };
        
        // Build metadata (whatsapp_message_id = Meta's message id, not DB primary key)
        $metadata = [
            'whatsapp_uuid' => $msg['whatsapp_uuid'],
            'whatsapp_message_id' => $msg['whatsapp_message_id'] ?? null,
            'message_type' => $msg['message_type'] ?? 'text',
            'from_number' => $msg['from_number'] ?? null,
            'to_number' => $msg['to_number'] ?? null
        ];
        
        if (!empty($msg['template_name'])) {
            $metadata['template_name'] = $msg['template_name'];
        }
        
        if (!empty($msg['template_params'])) {
            $metadata['template_params'] = json_decode($msg['template_params'], true);
        }
        
        $body = (isset($msg['message_body']) && trim((string) $msg['message_body']) !== '')
            ? $msg['message_body']
            : '[Media]';
        $metadataJson = json_encode($metadata);
        if ($metadataJson === false) {
            $metadataJson = '{}';
        }

        // Insert into communications
        Database::execute(
            "INSERT INTO communications (uuid, contact_id, channel, direction, subject, body, status, metadata, created_at) 
             VALUES (?, ?, 'whatsapp', ?, ?, ?, ?, ?, ?)",
            [
                $commUuid,
                $msg['contact_id'],
                $msg['direction'],
                $subject,
                $body,
                $status,
                $metadataJson,
                $msg['created_at']
            ]
        );
        
        $synced++;
        echo "✓ Synced message ID {$msg['id']} ({$msg['direction']})\n";
        
    } catch (\Exception $e) {
        $errors++;
        echo "✗ Error syncing message ID {$msg['id']}: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Summary ===\n";
echo "Total messages: {$total}\n";
echo "Successfully synced: {$synced}\n";
echo "Errors: {$errors}\n";

if ($synced > 0) {
    echo "\n✅ Sync complete! Messages should now appear in the Inbox.\n";
}
