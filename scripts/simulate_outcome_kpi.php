<?php
/**
 * Local Outcome KPI simulation (transactional, auto-rollback).
 *
 * Simulates:
 * 1) contact created
 * 2) inbound processed
 * 3) qualified follow-up task completed
 *
 * Prints KPI before/after and deltas, then rolls back.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CRM\Database;
use CRM\Modules\OutcomeMetrics;
use CRM\Modules\Tasks;
use CRM\Services\OutcomeEventService;

// Load .env for DB connection values
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

Database::init(require __DIR__ . '/../config/database.php');

function sim_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function out(string $line): void
{
    echo $line . PHP_EOL;
}

$metrics = new OutcomeMetrics();
$events = new OutcomeEventService();
$tasks = new Tasks();

$userRow = Database::queryOne(
    "SELECT u.id
     FROM users u
     LEFT JOIN activation_progress ap ON ap.user_id = u.id
     ORDER BY (ap.user_id IS NULL) DESC, u.id ASC
     LIMIT 1"
);

if (!$userRow || empty($userRow['id'])) {
    out('ERROR: No user found for simulation.');
    exit(1);
}
$userId = (int) $userRow['id'];

$before = [
    'ttfv' => $metrics->getTTFVSummary(14),
    'activation' => $metrics->getActivationRateSummary(7),
    'revenue_action' => $metrics->getRevenueActionRateSummary(7),
];

out('Simulation user_id: ' . $userId);
out('Before: ' . json_encode($before, JSON_PRETTY_PRINT));

Database::beginTransaction();
$rolledBack = false;

try {
    Database::execute("DELETE FROM activation_progress WHERE user_id = ?", [$userId]);

    $contactUuid = sim_uuid_v4();
    $email = 'sim_' . time() . '_' . mt_rand(1000, 9999) . '@example.local';
    Database::execute(
        "INSERT INTO contacts (uuid, first_name, last_name, email, lead_source, stage, assigned_to, created_by, created_at)
         VALUES (?, 'Outcome', 'Simulation', ?, 'simulation', 'new', ?, ?, NOW())",
        [$contactUuid, $email, $userId, $userId]
    );
    $contactId = (int) Database::lastInsertId();

    $events->track('user.first_login', [
        'user_id' => $userId,
        'event_source' => 'simulation',
        'event_at' => date('Y-m-d H:i:s', time() - 900),
        'metadata' => ['script' => 'simulate_outcome_kpi.php'],
    ]);
    $events->track('channel.connected', [
        'user_id' => $userId,
        'contact_id' => $contactId,
        'event_source' => 'simulation',
        'metadata' => ['channel' => 'email'],
    ]);
    $events->track('contact.created', [
        'user_id' => $userId,
        'contact_id' => $contactId,
        'event_source' => 'simulation',
    ]);

    $commUuid = sim_uuid_v4();
    Database::execute(
        "INSERT INTO communications (uuid, contact_id, channel, direction, subject, body, status, created_at)
         VALUES (?, ?, 'email', 'inbound', 'Simulation inbound', 'Interested, can we book a call?', 'sent', NOW())",
        [$commUuid, $contactId]
    );
    $commId = (int) Database::lastInsertId();

    $events->track('inbound.processed', [
        'user_id' => $userId,
        'contact_id' => $contactId,
        'event_source' => 'simulation',
        'metadata' => ['channel' => 'email', 'communication_id' => $commId],
    ]);

    $taskId = $tasks->create([
        'title' => 'Follow up with simulation contact',
        'description' => '[INBOX_TRIAGE] Simulation follow-up',
        'contact_id' => $contactId,
        'assigned_to' => $userId,
        'created_by' => $userId,
        'status' => 'pending',
        'priority' => 'high',
        'due_date' => date('Y-m-d H:i:s', time() + 3600),
    ]);

    $tasks->update($taskId, ['status' => 'completed']);

    $after = [
        'ttfv' => $metrics->getTTFVSummary(14),
        'activation' => $metrics->getActivationRateSummary(7),
        'revenue_action' => $metrics->getRevenueActionRateSummary(7),
    ];

    $delta = [
        'ttfv_median_hours_delta' => round((float) ($after['ttfv']['median_hours'] ?? 0) - (float) ($before['ttfv']['median_hours'] ?? 0), 2),
        'ttfv_sample_size_delta' => (int) ($after['ttfv']['sample_size'] ?? 0) - (int) ($before['ttfv']['sample_size'] ?? 0),
        'activation_rate_delta' => round((float) ($after['activation']['rate'] ?? 0) - (float) ($before['activation']['rate'] ?? 0), 2),
        'revenue_action_rate_delta' => round((float) ($after['revenue_action']['rate'] ?? 0) - (float) ($before['revenue_action']['rate'] ?? 0), 2),
    ];

    out('After: ' . json_encode($after, JSON_PRETTY_PRINT));
    out('Delta: ' . json_encode($delta, JSON_PRETTY_PRINT));
    out('Simulation inserted (transactional): contact_id=' . $contactId . ', communication_id=' . $commId . ', task_id=' . $taskId);
} catch (\Throwable $e) {
    out('ERROR: ' . $e->getMessage());
    if (Database::getInstance()->inTransaction()) {
        Database::rollBack();
        $rolledBack = true;
    }
    exit(1);
} finally {
    if (!$rolledBack && Database::getInstance()->inTransaction()) {
        Database::rollBack();
        out('Rolled back simulation transaction (no persistent data changes).');
    }
}

exit(0);
