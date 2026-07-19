<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Modules\DealAutomationConfig;
use CRM\Services\InboxTriageService;

// Load .env for CLI execution
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

Database::init(require __DIR__ . '/../config/database.php');

function out($label, $value): void {
    echo $label . ': ' . (is_string($value) ? $value : json_encode($value)) . PHP_EOL;
}

$db = Database::queryOne('SELECT DATABASE() AS db');
out('database', $db['db'] ?? 'unknown');

$configModule = new DealAutomationConfig();
$config = $configModule->get();
$config['inbox_triage_enabled'] = true;
$config['inbox_triage_auto_apply'] = true;
$config['inbox_triage_channels'] = ['email' => true, 'whatsapp' => true];
$config['inbox_triage_min_confidence'] = 0.80;
$config['inbox_triage_task_due_hours'] = 24;
$config['inbox_triage_dedupe_hours'] = 24;
$configModule->save($config);
out('triage_enabled', 'true');

$admin = Database::queryOne("SELECT id FROM users ORDER BY CASE WHEN role='admin' THEN 0 ELSE 1 END, id ASC LIMIT 1");
$adminId = (int) ($admin['id'] ?? 0);
if ($adminId <= 0) {
    throw new RuntimeException('No user found for assignment fallback');
}
out('fallback_owner', (string)$adminId);

$uuid = function (): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
};

$stamp = date('Ymd_His');
$email = "triage.sim.$stamp@example.com";
$phone = '2547' . substr(preg_replace('/\D+/', '', (string) microtime(true)), -8);

Database::execute(
    "INSERT INTO contacts (uuid, first_name, last_name, email, phone, assigned_to, stage, created_at)
     VALUES (?, 'Triage', 'Simulation', ?, ?, NULL, 'new', NOW())",
    [$uuid(), $email, $phone]
);
$contactId = (int) Database::lastInsertId();
out('contact_id', (string)$contactId);

$insertComm = function (string $channel, string $subject, string $body, array $metadata) use ($uuid, $contactId): int {
    Database::execute(
        "INSERT INTO communications (uuid, contact_id, channel, direction, subject, body, status, metadata, created_at)
         VALUES (?, ?, ?, 'inbound', ?, ?, 'delivered', ?, NOW())",
        [$uuid(), $contactId, $channel, $subject, $body, json_encode($metadata)]
    );
    return (int) Database::lastInsertId();
};

$emailCommId = $insertComm(
    'email',
    'Need pricing and demo',
    'Hi team, we are interested in your CRM. Can we schedule a demo and get pricing this week?',
    [
        'from_email' => $email,
        'intent' => ['intent' => 'purchase', 'confidence' => 0.93],
        'sentiment' => ['sentiment' => 'positive', 'score' => 0.62],
    ]
);

$waCommId = $insertComm(
    'whatsapp',
    'WhatsApp Message',
    'Hello, we are ready to buy this week. Please share pricing and book a demo call today.',
    [
        'from_number' => $phone,
        'intent' => ['intent' => 'purchase', 'confidence' => 0.94],
        'sentiment' => ['sentiment' => 'positive', 'score' => 0.49],
    ]
);

$triage = new InboxTriageService();
$resultEmail = $triage->processCommunication($emailCommId);
$resultWa = $triage->processCommunication($waCommId);

$fetch = function (int $commId): array {
    return Database::queryOne(
        "SELECT id, channel, triage_priority, triage_score, triage_confidence, triage_status, triage_owner_id, triage_task_id, triage_reason_codes
         FROM communications
         WHERE id = ?",
        [$commId]
    ) ?: [];
};

$emailRow = $fetch($emailCommId);
$waRow = $fetch($waCommId);

$auditEmail = Database::queryOne("SELECT decision, score, confidence, reason_codes FROM inbox_triage_audit WHERE communication_id = ? ORDER BY id DESC LIMIT 1", [$emailCommId]);
$auditWa = Database::queryOne("SELECT decision, score, confidence, reason_codes FROM inbox_triage_audit WHERE communication_id = ? ORDER BY id DESC LIMIT 1", [$waCommId]);

out('email_result', $resultEmail);
out('email_comm', $emailRow);
out('email_audit', $auditEmail ?: []);

out('whatsapp_result', $resultWa);
out('whatsapp_comm', $waRow);
out('whatsapp_audit', $auditWa ?: []);

$taskIds = array_filter([(int)($emailRow['triage_task_id'] ?? 0), (int)($waRow['triage_task_id'] ?? 0)]);
if (!empty($taskIds)) {
    $ph = implode(',', array_fill(0, count($taskIds), '?'));
    $tasks = Database::query("SELECT id, assigned_to, status, priority, due_date, description FROM tasks WHERE id IN ($ph) ORDER BY id ASC", array_values($taskIds));
    out('tasks', $tasks);
} else {
    out('tasks', []);
}
