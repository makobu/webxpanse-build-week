<?php
/**
 * AI Auto-responder end-to-end smoke test (CLI)
 *
 * Creates synthetic inbound communications, enqueues them, processes immediately,
 * and prints decisions per channel.
 *
 * Example:
 * php cli/test_ai_autoresponder_e2e.php --contact-id=123
 * php cli/test_ai_autoresponder_e2e.php --channels=email,whatsapp --email-contact-id=123 --whatsapp-contact-id=124
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
use CRM\Services\AIAutoResponderQueueService;
use CRM\Services\AIAutoResponderService;

Database::init(require __DIR__ . '/../config/database.php');

function parseArgs(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $pair = substr($arg, 2);
        if (strpos($pair, '=') === false) {
            $args[$pair] = '1';
            continue;
        }
        [$k, $v] = explode('=', $pair, 2);
        $args[$k] = $v;
    }
    return $args;
}

function uuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function out(string $text): void
{
    echo $text . PHP_EOL;
}

$args = parseArgs($argv);

$channelsRaw = strtolower(trim((string) ($args['channels'] ?? 'email,whatsapp,sms')));
$channels = array_values(array_filter(array_map('trim', explode(',', $channelsRaw))));
$channels = array_values(array_intersect($channels, ['email', 'whatsapp', 'sms']));
if (empty($channels)) {
    out('No valid channels selected. Use --channels=email,whatsapp,sms');
    exit(1);
}

$defaultContactId = (int) ($args['contact-id'] ?? 0);
$contactByChannel = [
    'email' => (int) ($args['email-contact-id'] ?? $defaultContactId),
    'whatsapp' => (int) ($args['whatsapp-contact-id'] ?? $defaultContactId),
    'sms' => (int) ($args['sms-contact-id'] ?? $defaultContactId),
];

$messages = [
    'email' => (string) ($args['email-message'] ?? 'Hi, can you share pricing and implementation timeline?'),
    'whatsapp' => (string) ($args['whatsapp-message'] ?? 'Hi, I need pricing details and onboarding steps.'),
    'sms' => (string) ($args['sms-message'] ?? 'Please share your pricing info and setup ETA.'),
];

$subjects = [
    'email' => 'Pricing question',
    'whatsapp' => 'WhatsApp inbound test',
    'sms' => 'SMS inbound test',
];

$queueService = new AIAutoResponderQueueService();
$autoResponder = new AIAutoResponderService();
$runId = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

out("AI auto-responder E2E test run: {$runId}");
out('------------------------------------------------------------');

$results = [];

foreach ($channels as $channel) {
    $contactId = (int) ($contactByChannel[$channel] ?? 0);
    if ($contactId <= 0) {
        $results[] = [
            'channel' => $channel,
            'status' => 'skipped',
            'note' => "Missing contact id for {$channel} (use --{$channel}-contact-id or --contact-id).",
        ];
        continue;
    }

    $contact = Database::queryOne(
        "SELECT id, first_name, last_name, email, phone FROM contacts WHERE id = ?",
        [$contactId]
    );
    if (!$contact) {
        $results[] = [
            'channel' => $channel,
            'status' => 'failed',
            'note' => "Contact {$contactId} not found.",
        ];
        continue;
    }

    if ($channel === 'email' && trim((string) ($contact['email'] ?? '')) === '') {
        $results[] = [
            'channel' => $channel,
            'status' => 'failed',
            'note' => "Contact {$contactId} has no email.",
        ];
        continue;
    }
    if (in_array($channel, ['whatsapp', 'sms'], true) && trim((string) ($contact['phone'] ?? '')) === '') {
        $results[] = [
            'channel' => $channel,
            'status' => 'failed',
            'note' => "Contact {$contactId} has no phone.",
        ];
        continue;
    }

    $message = trim((string) ($messages[$channel] ?? ''));
    if ($message === '') {
        $results[] = [
            'channel' => $channel,
            'status' => 'failed',
            'note' => "Message for {$channel} is empty.",
        ];
        continue;
    }

    $maxRow = Database::queryOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM communications");
    $nextCommunicationId = ((int) ($maxRow['max_id'] ?? 0)) + 1;

    Database::execute(
        "INSERT INTO communications
         (id, uuid, contact_id, channel, direction, subject, body, metadata, status, created_at)
         VALUES (?, ?, ?, ?, 'inbound', ?, ?, ?, 'sent', NOW())",
        [
            $nextCommunicationId,
            uuidV4(),
            $contactId,
            $channel,
            $subjects[$channel],
            $message,
            json_encode([
                'source' => 'cli_e2e_test',
                'run_id' => $runId,
                'channel' => $channel,
            ]),
        ]
    );
    $communicationId = (int) Database::lastInsertId();
    if ($communicationId <= 0) {
        $communicationId = $nextCommunicationId;
    }

    $queueId = $queueService->enqueueInboundCommunication(
        $communicationId,
        $contactId,
        $channel,
        $message,
        [
            'source' => 'cli_e2e_test',
            'run_id' => $runId,
            'channel' => $channel,
        ]
    );

    if (!$queueId) {
        $results[] = [
            'channel' => $channel,
            'status' => 'failed',
            'note' => 'Failed to enqueue inbound item.',
        ];
        continue;
    }

    $item = Database::queryOne("SELECT * FROM ai_autoresponder_queue WHERE id = ?", [(int) $queueId]);
    if (!$item) {
        $results[] = [
            'channel' => $channel,
            'status' => 'failed',
            'note' => "Queue item {$queueId} not found.",
        ];
        continue;
    }

    try {
        $queueService->markProcessing((int) $queueId);
        $result = $autoResponder->processQueueItem($item);
        $action = (string) ($result['action'] ?? 'failed');

        if ($action === 'auto_sent') {
            $queueService->markCompleted((int) $queueId);
        } elseif (in_array($action, ['draft', 'blocked', 'skipped', 'review'], true)) {
            $queueService->markSkipped((int) $queueId, (string) ($result['reason'] ?? $action));
        } else {
            $queueService->markFailed((int) $queueId, (string) ($result['error'] ?? 'Unknown processing error'));
        }

        $log = Database::queryOne(
            "SELECT decision, reason_code, confidence, status, created_at
             FROM ai_autoresponder_logs
             WHERE queue_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [(int) $queueId]
        ) ?: [];

        $results[] = [
            'channel' => $channel,
            'status' => 'ok',
            'note' => sprintf(
                "action=%s decision=%s reason=%s confidence=%s queue_id=%d communication_id=%d",
                $action,
                (string) ($log['decision'] ?? '-'),
                (string) ($log['reason_code'] ?? '-'),
                isset($log['confidence']) ? number_format((float) $log['confidence'], 3) : '-',
                (int) $queueId,
                $communicationId
            ),
        ];
    } catch (\Throwable $e) {
        $queueService->markFailed((int) $queueId, $e->getMessage());
        $results[] = [
            'channel' => $channel,
            'status' => 'failed',
            'note' => $e->getMessage(),
        ];
    }
}

foreach ($results as $row) {
    out(strtoupper($row['channel']) . ' [' . strtoupper($row['status']) . '] ' . $row['note']);
}

out('------------------------------------------------------------');
out('Done.');
