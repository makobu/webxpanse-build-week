<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CRM\Database;
use CRM\Services\WhatsAppWebhook;

function loadEnvFile(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || strpos($trimmed, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

function optionValue(array $argv, string $name, string $default = ''): string
{
    foreach ($argv as $arg) {
        if (strpos($arg, '--' . $name . '=') === 0) {
            return (string) substr($arg, strlen($name) + 3);
        }
    }

    return $default;
}

function hasFlag(array $argv, string $name): bool
{
    foreach ($argv as $arg) {
        if ($arg === '--' . $name) {
            return true;
        }
    }

    return false;
}

function cleanupLocalSeedRows(): array
{
    $prefix = 'wamid.localseed.';
    $phoneLike = '0700000111';
    $removed = [
        'communications' => 0,
        'whatsapp_messages' => 0,
        'contacts' => 0,
    ];

    $contactIds = Database::query(
        "SELECT DISTINCT contact_id
         FROM communications
         WHERE channel = 'whatsapp'
           AND direction = 'inbound'
           AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp_message_id')) LIKE ?",
        [$prefix . '%']
    );

    $contactIds = array_values(array_unique(array_map(
        static fn(array $row): int => (int) ($row['contact_id'] ?? 0),
        $contactIds
    )));
    $contactIds = array_filter($contactIds, static fn(int $id): bool => $id > 0);

    $removed['communications'] = Database::execute(
        "DELETE FROM communications
         WHERE channel = 'whatsapp'
           AND direction = 'inbound'
           AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp_message_id')) LIKE ?",
        [$prefix . '%']
    );

    $removed['whatsapp_messages'] = Database::execute(
        "DELETE FROM whatsapp_messages
         WHERE direction = 'inbound'
           AND whatsapp_message_id LIKE ?",
        [$prefix . '%']
    );

    foreach ($contactIds as $contactId) {
        $hasOtherComms = Database::queryOne(
            "SELECT 1 FROM communications WHERE contact_id = ? LIMIT 1",
            [$contactId]
        );
        $contact = Database::queryOne(
            "SELECT email, phone FROM contacts WHERE id = ? LIMIT 1",
            [$contactId]
        );

        if ($hasOtherComms || !$contact) {
            continue;
        }

        $email = (string) ($contact['email'] ?? '');
        $phone = preg_replace('/\D+/', '', (string) ($contact['phone'] ?? ''));
        if ($email === 'whatsapp_254700000111@whatsapp.local' || $phone === $phoneLike || $phone === '254700000111') {
            $removed['contacts'] += Database::execute(
                "DELETE FROM contacts WHERE id = ?",
                [$contactId]
            );
        }
    }

    return $removed;
}

$rootDir = dirname(__DIR__);
loadEnvFile($rootDir . '/.env');
require_once $rootDir . '/config/constants.php';

Database::init(require $rootDir . '/config/database.php');

$argv = $argv ?? [];
$cleanupOnly = hasFlag($argv, 'cleanup');
$phone = optionValue($argv, 'phone', '254700000111');
$message = optionValue($argv, 'message', 'Hey test');
$name = optionValue($argv, 'name', 'Local WhatsApp Test');

if ($cleanupOnly) {
    $removed = cleanupLocalSeedRows();
    echo "Cleaned local seed rows:\n";
    echo "  communications: {$removed['communications']}\n";
    echo "  whatsapp_messages: {$removed['whatsapp_messages']}\n";
    echo "  contacts: {$removed['contacts']}\n";
    exit(0);
}

$removed = cleanupLocalSeedRows();

$messageId = 'wamid.localseed.' . date('YmdHis') . '.' . substr(bin2hex(random_bytes(4)), 0, 8);
$payload = [
    'entry' => [[
        'id' => 'local_seed_entry',
        'changes' => [[
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => [
                    'display_phone_number' => 'LOCAL-SEED',
                    'phone_number_id' => $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? 'local_seed_phone_id',
                ],
                'contacts' => [[
                    'profile' => ['name' => $name],
                    'wa_id' => $phone,
                ]],
                'messages' => [[
                    'from' => $phone,
                    'id' => $messageId,
                    'timestamp' => time(),
                    'type' => 'text',
                    'text' => ['body' => $message],
                ]],
            ],
            'field' => 'messages',
        ]],
    ]],
];

$webhook = new WhatsAppWebhook();
$webhook->handle($payload);

$communication = Database::queryOne(
    "SELECT c.id, c.contact_id, c.created_at, c.body
     FROM communications c
     WHERE c.channel = 'whatsapp'
       AND c.direction = 'inbound'
       AND JSON_UNQUOTE(JSON_EXTRACT(c.metadata, '$.whatsapp_message_id')) = ?
     LIMIT 1",
    [$messageId]
);

if (!$communication) {
    fwrite(STDERR, "Seed failed: communication row was not created.\n");
    exit(1);
}

$contact = Database::queryOne(
    "SELECT first_name, last_name, email, phone FROM contacts WHERE id = ? LIMIT 1",
    [(int) $communication['contact_id']]
);

$conversationUrl = '/crm/public/conversation.php?id=' . (int) $communication['id']
    . '&contact_id=' . (int) $communication['contact_id']
    . '&channel=whatsapp';

echo "Inserted temporary inbound WhatsApp message.\n";
echo "Cleanup before seed:\n";
echo "  communications: {$removed['communications']}\n";
echo "  whatsapp_messages: {$removed['whatsapp_messages']}\n";
echo "  contacts: {$removed['contacts']}\n";
echo "\n";
echo "Seed details:\n";
echo "  message_id: {$messageId}\n";
echo "  communication_id: " . (int) $communication['id'] . "\n";
echo "  contact_id: " . (int) $communication['contact_id'] . "\n";
echo "  contact: " . trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))) . "\n";
echo "  phone: " . (string) ($contact['phone'] ?? $phone) . "\n";
echo "  body: " . (string) ($communication['body'] ?? $message) . "\n";
echo "  created_at: " . (string) ($communication['created_at'] ?? '') . "\n";
echo "\n";
echo "Open in browser:\n";
echo "  Inbox: /crm/public/inbox.php?channel=whatsapp\n";
echo "  Conversation: {$conversationUrl}\n";
echo "\n";
echo "To remove the temp seed later:\n";
echo "  php scripts/seed_temp_whatsapp_message.php --cleanup\n";
