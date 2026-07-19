<?php
/**
 * Test WhatsApp Webhook - Simulate incoming message
 *
 * This script simulates a WhatsApp webhook payload to test message processing
 * without Meta webhook or ngrok.
 *
 * Usage:
 *   php scripts/test_whatsapp_webhook.php
 *   php scripts/test_whatsapp_webhook.php --phone=254712345678 --message="Hello"
 *   php scripts/test_whatsapp_webhook.php --type=image --message="Photo caption"
 *   php scripts/test_whatsapp_webhook.php --type=document
 *   php scripts/test_whatsapp_webhook.php --type=interactive
 *   php scripts/test_whatsapp_webhook.php --name="Custom Contact"
 *   php scripts/test_whatsapp_webhook.php --status=delivered --message-id=wamid.xxx  (simulate status update)
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env - match test_db_connection.php path (which works); try both for different server layouts
$envPaths = [__DIR__ . '/../.env', __DIR__ . '/../../.env'];
$envFile = null;
foreach ($envPaths as $p) {
    if (file_exists($p)) { $envFile = $p; break; }
}
if ($envFile) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$rootDir = $envFile ? dirname($envFile) : dirname(__DIR__);
require_once $rootDir . '/config/constants.php';

use CRM\Database;
use CRM\Services\WhatsAppWebhook;

// Initialize database - use same root as .env
$dbConfig = require $rootDir . '/config/database.php';
$dbConfig['charset'] = null;
Database::init($dbConfig);

// Parse CLI options
$options = [
    'phone' => '254737002242',
    'message' => 'This is a test WhatsApp message from the test script',
    'name' => 'Test User',
    'type' => 'text',
    'status' => null,
    'message-id' => null
];

foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--') === 0 && strpos($arg, '=') !== false) {
        list($key, $val) = explode('=', substr($arg, 2), 2);
        $options[$key] = $val;
    }
}

$phone = $options['phone'];
$messageBody = $options['message'];
$contactName = $options['name'];
$messageType = $options['type'];
$statusSim = $options['status'] ?? null;
$statusMessageId = $options['message-id'] ?? null;

// Status update simulation
if ($statusSim && in_array($statusSim, ['sent', 'delivered', 'read', 'failed'])) {
    $messageId = $statusMessageId;
    if (!$messageId) {
        $lastMsg = Database::queryOne(
            "SELECT whatsapp_message_id FROM whatsapp_messages WHERE direction = 'outbound' AND whatsapp_message_id IS NOT NULL ORDER BY created_at DESC LIMIT 1"
        );
        $messageId = $lastMsg['whatsapp_message_id'] ?? null;
    }
    if (!$messageId) {
        echo "Error: --status requires --message-id=wamid.xxx or an existing outbound message with whatsapp_message_id.\n";
        exit(1);
    }
    $testPayload = [
        'entry' => [[
            'id' => 'test_entry_id',
            'changes' => [[
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['phone_number_id' => $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? 'test'],
                    'statuses' => [array_merge(
                        ['id' => $messageId, 'status' => $statusSim, 'timestamp' => time()],
                        $statusSim === 'failed' ? ['errors' => [['message' => 'Simulated failure']]] : []
                    )]
                ],
                'field' => 'messages'
            ]]
        ]]
    ];
    echo "=== Testing WhatsApp Status Update ===\n\n";
    echo "Simulating status: {$statusSim} for message: {$messageId}\n\n";
    $webhook = new WhatsAppWebhook();
    $webhook->handle($testPayload);
    echo "Status update processed.\n";
    exit(0);
}

// Build message based on type
$message = [
    'from' => $phone,
    'id' => 'wamid.test_' . time() . '_' . bin2hex(random_bytes(4)),
    'timestamp' => time(),
    'type' => $messageType
];

switch ($messageType) {
    case 'image':
        $message['image'] = !empty($messageBody) && $messageBody !== '[Image]'
            ? ['caption' => $messageBody]
            : [];
        break;
    case 'video':
        $message['video'] = !empty($messageBody) && $messageBody !== '[Video]'
            ? ['caption' => $messageBody]
            : [];
        break;
    case 'audio':
        $message['audio'] = ['mime_type' => 'audio/ogg'];
        break;
    case 'document':
        $message['document'] = [
            'filename' => !empty($messageBody) && $messageBody !== '[Document]' ? $messageBody : 'test.pdf',
            'mime_type' => 'application/pdf'
        ];
        break;
    case 'interactive':
        $message['interactive'] = [
            'type' => 'button_reply',
            'button_reply' => [
                'id' => 'btn_1',
                'title' => !empty($messageBody) && $messageBody !== '[Interactive]' ? $messageBody : 'Option A'
            ]
        ];
        break;
    default:
        $message['text'] = ['body' => $messageBody];
        break;
}

// Simulate webhook payload
$testPayload = [
    'entry' => [
        [
            'id' => 'test_entry_id',
            'changes' => [
                [
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '1234567890',
                            'phone_number_id' => $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? 'test_phone_id'
                        ],
                        'contacts' => [
                            [
                                'profile' => ['name' => $contactName],
                                'wa_id' => $phone
                            ]
                        ],
                        'messages' => [$message]
                    ],
                    'field' => 'messages'
                ]
            ]
        ]
    ]
];

echo "=== Testing WhatsApp Webhook Message Processing ===\n\n";
echo "Options: --phone={$phone}, --message=\"" . substr($messageBody, 0, 40) . "...\", --name={$contactName}, --type={$messageType}\n\n";
echo "Simulating incoming {$messageType} message from: {$phone}\n";
echo "Message body: " . substr($messageBody, 0, 80) . (strlen($messageBody) > 80 ? '...' : '') . "\n\n";

try {
    $webhook = new WhatsAppWebhook();
    $webhook->handle($testPayload);

    echo "Message processed successfully!\n\n";

    // Check if message was stored
    $storedMessage = Database::queryOne(
        "SELECT wm.*, c.first_name, c.last_name
         FROM whatsapp_messages wm
         LEFT JOIN contacts c ON wm.contact_id = c.id
         WHERE wm.direction = 'inbound'
         ORDER BY wm.created_at DESC
         LIMIT 1"
    );

    if ($storedMessage) {
        echo "Message found in whatsapp_messages table:\n";
        echo "   - ID: {$storedMessage['id']}\n";
        echo "   - Contact: {$storedMessage['first_name']} {$storedMessage['last_name']} (ID: {$storedMessage['contact_id']})\n";
        echo "   - From: {$storedMessage['from_number']}\n";
        echo "   - Type: {$storedMessage['message_type']}\n";
        echo "   - Body: " . substr($storedMessage['message_body'], 0, 60) . "...\n";
        echo "   - Status: {$storedMessage['status']}\n\n";
    } else {
        echo "Message NOT found in whatsapp_messages table\n\n";
    }

    // Check if message was added to communications table
    $comm = Database::queryOne(
        "SELECT c.*, ct.first_name, ct.last_name
         FROM communications c
         LEFT JOIN contacts ct ON c.contact_id = ct.id
         WHERE c.channel = 'whatsapp' AND c.direction = 'inbound'
         ORDER BY c.created_at DESC
         LIMIT 1"
    );

    if ($comm) {
        echo "Message found in communications table (inbox):\n";
        echo "   - ID: {$comm['id']}\n";
        echo "   - Contact: {$comm['first_name']} {$comm['last_name']} (ID: {$comm['contact_id']})\n";
        echo "   - Subject: {$comm['subject']}\n";
        echo "   - Body: " . substr($comm['body'], 0, 60) . "...\n";
        echo "   - Status: {$comm['status']}\n\n";
    } else {
        echo "Message NOT found in communications table\n\n";
    }

    // Summary
    $totalInbound = Database::queryOne(
        "SELECT COUNT(*) as count FROM whatsapp_messages WHERE direction = 'inbound'"
    )['count'] ?? 0;

    $totalInboundComm = Database::queryOne(
        "SELECT COUNT(*) as count FROM communications WHERE channel = 'whatsapp' AND direction = 'inbound'"
    )['count'] ?? 0;

    echo "Summary:\n";
    echo "   - Total inbound (whatsapp_messages): {$totalInbound}\n";
    echo "   - Total inbound (communications): {$totalInboundComm}\n";
    echo "   - Message type tested: {$messageType}\n\n";

    echo "Test completed! Check inbox at: /crm/public/inbox.php?channel=whatsapp\n";

} catch (\Exception $e) {
    echo "Error processing message: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
