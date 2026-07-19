<?php

/**
 * Rollback-only production-v1 voice capacity check.
 *
 * This harness is intentionally restricted to databases whose name contains
 * "test". It simulates the provider ceiling of 40 active calls and a burst of
 * 400 duplicated/out-of-order events without leaving persistent rows behind.
 */

require_once __DIR__ . '/../vendor/autoload.php';
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        putenv(trim($key) . '=' . trim($value));
    }
}
require_once __DIR__ . '/../config/constants.php';
$databaseConfig = require __DIR__ . '/../config/database.php';
$databaseName = strtolower((string) ($databaseConfig['name'] ?? ''));
if (!str_contains($databaseName, 'test')) {
    fwrite(STDERR, "Refusing to run voice capacity verification outside a test database.\n");
    exit(2);
}
\CRM\Database::init($databaseConfig);

$workspaceId = (int) (\CRM\Database::queryOne('SELECT workspace_id FROM workspace_voice_configs ORDER BY workspace_id ASC LIMIT 1')['workspace_id'] ?? 0);
if ($workspaceId <= 0) {
    fwrite(STDERR, "Create an isolated voice workspace configuration before running this harness.\n");
    exit(2);
}

$prefix = 'vcc-capacity-' . bin2hex(random_bytes(4));
$eventLatencies = [];
\CRM\Database::beginTransaction();
try {
    for ($i = 0; $i < 40; $i++) {
        $uuid = sprintf('7%07d-0000-4000-8000-%012d', $i, $i);
        \CRM\Database::execute(
            "INSERT INTO voice_calls (workspace_id, uuid, provider_session_id, direction, state, consent_status, recording_status, transcription_status, requested_at)
             VALUES (?, ?, ?, 'inbound', 'in_progress', 'granted', 'disabled', 'disabled', NOW())",
            [$workspaceId, $uuid, $prefix . '-session-' . $i]
        );
    }
    $calls = \CRM\Database::query(
        "SELECT id FROM voice_calls WHERE workspace_id = ? AND provider_session_id LIKE ? ORDER BY id ASC",
        [$workspaceId, $prefix . '-session-%']
    );
    if (count($calls) !== 40) throw new RuntimeException('Expected exactly 40 simulated active calls.');

    for ($i = 0; $i < 400; $i++) {
        $callId = (int) $calls[$i % 40]['id'];
        $eventSlot = $i % 20;
        $state = ['ringing', 'in_progress', 'completed', 'ringing'][$i % 4];
        $started = hrtime(true);
        \CRM\Database::execute(
            "INSERT IGNORE INTO voice_call_events (workspace_id, call_id, provider, provider_event_key, provider_session_id,
                event_type, normalized_state, payload_hash, metadata_json, accepted, occurred_at)
             VALUES (?, ?, 'africastalking', ?, ?, 'capacity_event', ?, ?, '{}', 1, NOW())",
            [$workspaceId, $callId, $prefix . '-event-' . $eventSlot, $prefix . '-session-' . ($i % 40), $state, hash('sha256', $prefix . '|' . $eventSlot)]
        );
        $eventLatencies[] = (hrtime(true) - $started) / 1_000_000;
    }
    $storedEvents = (int) (\CRM\Database::queryOne(
        'SELECT COUNT(*) AS c FROM voice_call_events WHERE workspace_id = ? AND provider_event_key LIKE ?',
        [$workspaceId, $prefix . '-event-%']
    )['c'] ?? 0);
    if ($storedEvents !== 20) throw new RuntimeException('Replay/idempotency protection did not collapse duplicate events.');

    $statusStarted = hrtime(true);
    $status = (new \CRM\Services\VoiceCallService())->list($workspaceId, 0, true, 0, 100);
    $statusMs = (hrtime(true) - $statusStarted) / 1_000_000;
    sort($eventLatencies);
    $p95Index = max(0, min(count($eventLatencies) - 1, (int) ceil(count($eventLatencies) * 0.95) - 1));
    $eventP95 = $eventLatencies[$p95Index];
    if ($eventP95 >= 500) throw new RuntimeException('Event persistence p95 exceeded 500 ms.');
    if ($statusMs >= 300) throw new RuntimeException('Active-call status query exceeded 300 ms.');
    if ((int) $status['active_count'] < 40) throw new RuntimeException('Status service did not return the 40 active sessions.');

    \CRM\Database::rollBack();
    echo json_encode([
        'success' => true,
        'database' => $databaseConfig['name'],
        'simulated_active_calls' => 40,
        'submitted_events' => 400,
        'stored_idempotent_events' => $storedEvents,
        'event_p95_ms' => round($eventP95, 3),
        'status_query_ms' => round($statusMs, 3),
        'persistent_rows' => 0,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    \CRM\Database::rollBack();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
