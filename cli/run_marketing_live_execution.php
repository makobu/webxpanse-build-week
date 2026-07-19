<?php
/**
 * Cron-safe Marketing live execution launcher.
 *
 * Usage:
 *   php cli/run_marketing_live_execution.php all 25
 *   php cli/run_marketing_live_execution.php 123 25
 *   php cli/run_marketing_live_execution.php --workspace-id=123 --limit=25 --source=cron
 *   php cli/run_marketing_live_execution.php all 25 --readiness --json
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../scripts/marketing_live_cli_context.php';

use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\WorkspaceContext;

function marketingLiveExecutionUsage(int $exitCode = 0): void
{
    $message = <<<TXT
Marketing Live Execution Launcher

Usage:
  php cli/run_marketing_live_execution.php all 25
  php cli/run_marketing_live_execution.php 123 25
  php cli/run_marketing_live_execution.php --workspace-id=123 --limit=25 --source=cron
  php cli/run_marketing_live_execution.php all 25 --readiness --json

Options:
  --workspace-id=<id>   Workspace to run. Omit or use "all" to scan workspaces with live execution work.
  --limit=<n>           Max confirmed live items / handoffs per workspace. Default: 25, max: 250.
  --source=<source>     cli, cron, api, manual, or test. Default: cron.
  --user-id=<id>        Optional workspace user with marketing.manage. Auto-resolves an owner/admin when omitted.
  --readiness           Report live execution schedule readiness without running workers.
  --json                Output machine-readable JSON.
  --help                Show this help.

Safety:
  - This launcher runs the existing guarded orchestrator chain only.
  - Live execution still requires policy, connector readiness, approvals, preflight checks, RUN LIVE confirmation, worker schedules, leases, throttles, and emergency-stop guards.
  - No raw secrets are printed: database passwords, API keys, connector secrets, and token values stay hidden.

TXT;
    fwrite($exitCode === 0 ? STDOUT : STDERR, $message);
    exit($exitCode);
}

function marketingLiveExecutionArguments(array $argv): array
{
    $options = [];
    $positionals = [];
    $booleanOptions = ['help', 'json', 'readiness'];
    $count = count($argv);

    for ($i = 1; $i < $count; $i++) {
        $arg = (string) $argv[$i];
        if (str_starts_with((string) $arg, '--')) {
            $raw = substr($arg, 2);
            if ($raw === '') {
                continue;
            }
            if (str_contains($raw, '=')) {
                [$key, $value] = explode('=', $raw, 2);
                $options[$key] = $value;
                continue;
            }
            if (in_array($raw, $booleanOptions, true)) {
                $options[$raw] = true;
                continue;
            }
            $next = (string) ($argv[$i + 1] ?? '');
            if ($next !== '' && !str_starts_with($next, '--')) {
                $options[$raw] = $next;
                $i++;
                continue;
            }
            $options[$raw] = '';
            continue;
        }
        $positionals[] = (string) $arg;
    }

    return [$options, $positionals];
}

function marketingLiveExecutionDiscoverWorkspaceIds(): array
{
    $queries = [];
    if (Database::tableExists('marketing_execution_queue')) {
        $queries[] = "SELECT workspace_id
                      FROM marketing_execution_queue
                      WHERE execution_mode = 'live'
                         OR live_dispatch_status IN ('confirmed','processing','failed','blocked','queued')";
    }
    if (Database::tableExists('marketing_live_email_handoffs')) {
        $queries[] = "SELECT workspace_id
                      FROM marketing_live_email_handoffs
                      WHERE status IN ('queued','processing','failed','blocked')";
    }
    if (Database::tableExists('marketing_live_channel_handoffs')) {
        $queries[] = "SELECT workspace_id
                      FROM marketing_live_channel_handoffs
                      WHERE status IN ('queued','processing','failed','blocked')";
    }
    if (Database::tableExists('marketing_live_worker_schedules')) {
        $queries[] = "SELECT workspace_id
                      FROM marketing_live_worker_schedules
                      WHERE worker_key IN (
                        'marketing_live_queue_dispatcher',
                        'marketing_live_orchestrator',
                        'marketing_live_email_handoffs',
                        'marketing_live_channel_handoffs',
                        'marketing_live_outcome_sync'
                      )";
    }

    if ($queries === []) {
        return [];
    }

    $rows = Database::query(
        'SELECT DISTINCT workspace_id FROM (' . implode(' UNION ', $queries) . ') workspace_scope
         WHERE workspace_id IS NOT NULL
         ORDER BY workspace_id ASC'
    );

    return array_values(array_filter(array_map(
        static fn(array $row): int => (int) ($row['workspace_id'] ?? 0),
        $rows
    )));
}

function marketingLiveExecutionUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function marketingLiveExecutionCreateLauncherRun(
    int $workspaceId,
    string $invocationUuid,
    string $source,
    string $targetScope,
    int $limit,
    bool $readinessOnly
): ?int {
    if ($workspaceId <= 0 || !Database::tableExists('marketing_live_execution_launcher_runs')) {
        return null;
    }

    Database::execute(
        "INSERT INTO marketing_live_execution_launcher_runs
         (workspace_id, uuid, invocation_uuid, source, status, target_scope, readiness_only, limit_count, started_at)
         VALUES (?, ?, ?, ?, 'running', ?, ?, ?, NOW())",
        [
            $workspaceId,
            marketingLiveExecutionUuid(),
            $invocationUuid,
            $source,
            mb_substr($targetScope !== '' ? $targetScope : 'all', 0, 64),
            $readinessOnly ? 1 : 0,
            $limit,
        ]
    );

    return (int) Database::lastInsertId();
}

function marketingLiveExecutionCompleteLauncherRun(?int $launcherRunId, string $status, array $payload, ?string $errorMessage = null): void
{
    if ($launcherRunId === null || $launcherRunId <= 0 || !Database::tableExists('marketing_live_execution_launcher_runs')) {
        return;
    }

    $run = (array) ($payload['run'] ?? []);
    Database::execute(
        "UPDATE marketing_live_execution_launcher_runs
         SET status = ?,
             orchestration_run_id = ?,
             processed_count = ?,
             succeeded_count = ?,
             blocked_count = ?,
             failed_count = ?,
             result_json = ?,
             error_message = ?,
             completed_at = NOW()
         WHERE id = ?",
        [
            $status,
            !empty($run['id']) ? (int) $run['id'] : null,
            (int) ($run['processed_count'] ?? 0),
            (int) ($run['succeeded_count'] ?? 0),
            (int) ($run['blocked_count'] ?? 0),
            (int) ($run['failed_count'] ?? 0),
            json_encode($payload, JSON_UNESCAPED_SLASHES),
            $errorMessage !== null ? mb_substr($errorMessage, 0, 1000) : null,
            $launcherRunId,
        ]
    );
}

function marketingLiveExecutionScheduleSummary(int $workspaceId, string $workerKey, string $label, string $fallbackCommand): array
{
    $schedule = null;
    if (Database::tableExists('marketing_live_worker_schedules')) {
        $schedule = Database::queryOne(
            "SELECT *
             FROM marketing_live_worker_schedules
             WHERE workspace_id = ?
               AND worker_key = ?
             LIMIT 1",
            [$workspaceId, $workerKey]
        );
    }
    $configured = is_array($schedule) && $schedule !== [];
    $status = (string) ($schedule['status'] ?? 'planned');
    $emergencyPaused = !empty($schedule['emergency_paused']);
    $message = !$configured
        ? "{$label} schedule has not been configured yet."
        : ($emergencyPaused
            ? "{$label} is emergency paused."
            : ($status === 'active' ? "{$label} schedule is active." : "{$label} schedule is not active."));
    $command = (string) ($schedule['command'] ?? $fallbackCommand);
    if ($workerKey === 'marketing_live_orchestrator' && $command === 'php scripts/run_marketing_live_orchestrator.php all 25') {
        $command = $fallbackCommand;
    }

    return [
        'configured' => $configured,
        'status' => $status,
        'emergency_paused' => $emergencyPaused,
        'expected_interval_minutes' => (int) ($schedule['expected_interval_minutes'] ?? 0),
        'last_confirmed_at' => $schedule['last_confirmed_at'] ?? null,
        'command' => $command,
        'message' => $message,
    ];
}

function marketingLiveExecutionReadinessSnapshot(int $workspaceId, ?Marketing $marketing = null): array
{
    $marketing ??= new Marketing();

    return [
        'orchestrator' => marketingLiveExecutionScheduleSummary($workspaceId, 'marketing_live_orchestrator', 'Marketing live orchestrator', 'php cli/run_marketing_live_execution.php all 25 --source=cron'),
        'dispatcher' => marketingLiveExecutionScheduleSummary($workspaceId, 'marketing_live_queue_dispatcher', 'Marketing live queue dispatcher', 'php scripts/run_marketing_live_dispatcher.php --workspace-id=' . $workspaceId . ' --limit=10 --source=cron'),
        'email_worker' => marketingLiveExecutionScheduleSummary($workspaceId, 'marketing_live_email_handoffs', 'Marketing live email worker', 'php cli/process_marketing_live_email_handoffs.php all 25'),
        'channel_worker' => marketingLiveExecutionScheduleSummary($workspaceId, 'marketing_live_channel_handoffs', 'Marketing live channel worker', 'php cli/process_marketing_live_channel_handoffs.php all 25'),
        'outcome_sync' => marketingLiveExecutionScheduleSummary($workspaceId, 'marketing_live_outcome_sync', 'Marketing live outcome sync worker', 'php scripts/sync_marketing_live_outcomes.php all 250'),
        'webhook_attempts' => $marketing->getLiveWebhookAttemptHealth(false),
    ];
}

function marketingLiveExecutionReadinessSummary(array $snapshot): array
{
    $labels = [
        'orchestrator' => 'Orchestrator',
        'dispatcher' => 'Dispatcher',
        'email_worker' => 'Email worker',
        'channel_worker' => 'Channel worker',
        'outcome_sync' => 'Outcome sync',
        'webhook_attempts' => 'Outbound webhooks',
    ];
    $workerKeys = ['orchestrator', 'dispatcher', 'email_worker', 'channel_worker', 'outcome_sync'];
    $componentStatuses = [];
    $blockers = [];
    $warnings = [];
    $recommendedActions = [];

    foreach ($workerKeys as $key) {
        $component = (array) ($snapshot[$key] ?? []);
        $status = (string) ($component['status'] ?? 'unknown');
        $componentStatuses[$key] = $status;
        if (!empty($component['emergency_paused'])) {
            $blockers[] = $labels[$key] . ' is emergency paused.';
            continue;
        }
        if (in_array($status, ['disabled', 'paused'], true)) {
            $blockers[] = $labels[$key] . ' schedule is ' . str_replace('_', ' ', $status) . '.';
            continue;
        }
        if ($status !== 'active') {
            $warnings[] = $labels[$key] . ' schedule is ' . str_replace('_', ' ', $status) . '.';
        }
        if (!empty($component['message']) && $status !== 'active') {
            $recommendedActions[] = (string) $component['message'];
        }
    }

    $webhook = (array) ($snapshot['webhook_attempts'] ?? []);
    $webhookStatus = (string) ($webhook['status'] ?? 'unknown');
    $componentStatuses['webhook_attempts'] = $webhookStatus;
    if ($webhookStatus === 'blocked') {
        $blockers[] = (string) ($webhook['message'] ?? 'Outbound webhook health is blocked.');
    } elseif (in_array($webhookStatus, ['stale', 'attention'], true)) {
        $warnings[] = (string) ($webhook['message'] ?? 'Outbound webhook health needs attention.');
    }
    foreach ((array) ($webhook['recommended_actions'] ?? []) as $action) {
        if (trim((string) $action) !== '') {
            $recommendedActions[] = (string) $action;
        }
    }

    $status = 'ready';
    if ($blockers !== []) {
        $status = 'blocked';
    } elseif (in_array('stale', $componentStatuses, true)) {
        $status = 'stale';
    } elseif ($warnings !== []) {
        $status = 'attention';
    }

    return [
        'status' => $status,
        'ready_for_live_execution' => $status === 'ready',
        'component_statuses' => $componentStatuses,
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => array_values(array_unique($warnings)),
        'recommended_actions' => array_values(array_unique($recommendedActions)),
        'secret_safe' => true,
        'external_api_called_by_readiness' => false,
        'third_party_delivery_by_readiness' => false,
        'checked_at' => date('c'),
    ];
}

[$options, $positionals] = marketingLiveExecutionArguments($argv);

if (isset($options['help'])) {
    marketingLiveExecutionUsage(0);
}

$positionalTarget = strtolower(trim((string) ($positionals[0] ?? 'all')));
$positionalLimit = (int) ($positionals[1] ?? 25);
$target = strtolower(trim((string) ($options['workspace-id'] ?? $positionalTarget)));
$limit = max(1, min(250, (int) ($options['limit'] ?? $positionalLimit ?: 25)));
$source = trim((string) ($options['source'] ?? 'cron')) ?: 'cron';
$source = in_array($source, ['cli', 'cron', 'api', 'manual', 'test'], true) ? $source : 'cron';
$preferredUserId = (int) ($options['user-id'] ?? 0);
$readinessOnly = isset($options['readiness']);
$json = isset($options['json']);
$invocationUuid = marketingLiveExecutionUuid();

Database::init(require __DIR__ . '/../config/database.php');

if ($target !== '' && $target !== 'all') {
    $workspaceId = (int) $target;
    if ($workspaceId <= 0) {
        marketingLiveExecutionUsage(1);
    }
    $workspaceIds = [$workspaceId];
} else {
    $workspaceIds = marketingLiveExecutionDiscoverWorkspaceIds();
}

if ($workspaceIds === []) {
    $payload = [
        'runs' => [],
        'message' => 'No Marketing live execution workspaces were found.',
        'secret_safe' => true,
        'external_api_called_by_launcher' => false,
    ];
    echo $json
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $payload['message']);
    exit(0);
}

$runs = [];
$exitCode = 0;
foreach ($workspaceIds as $workspaceId) {
    $launcherRunId = null;
    try {
        $actor = marketingLiveCliActivateManageContext($workspaceId, $preferredUserId);
        $actorUserId = (int) ($actor['user_id'] ?? 0);
        $marketing = new Marketing();
        $launcherRunId = marketingLiveExecutionCreateLauncherRun(
            $workspaceId,
            $invocationUuid,
            $source,
            $target !== '' ? $target : 'all',
            $limit,
            $readinessOnly
        );
        if ($readinessOnly) {
            $snapshot = marketingLiveExecutionReadinessSnapshot($workspaceId, $marketing);
            $readinessSummary = marketingLiveExecutionReadinessSummary($snapshot);
            $payload = [
                'workspace_id' => $workspaceId,
                'actor_user_id' => $actorUserId,
                'status' => $readinessSummary['status'],
                'ready_for_live_execution' => $readinessSummary['ready_for_live_execution'],
                'readiness_summary' => $readinessSummary,
                'readiness' => $snapshot,
            ];
            $runs[] = $payload;
            marketingLiveExecutionCompleteLauncherRun($launcherRunId, 'completed', $payload);
            if (!$json) {
                echo sprintf(
                    "[%s] workspace=%d readiness status=%s orchestrator=%s dispatcher=%s email=%s channel=%s outcome_sync=%s webhooks=%s\n",
                    date('Y-m-d H:i:s'),
                    $workspaceId,
                    $readinessSummary['status'],
                    $snapshot['orchestrator']['status'],
                    $snapshot['dispatcher']['status'],
                    $snapshot['email_worker']['status'],
                    $snapshot['channel_worker']['status'],
                    $snapshot['outcome_sync']['status'],
                    $snapshot['webhook_attempts']['status']
                );
            }
            continue;
        }

        $run = $marketing->runLiveExecutionOrchestrator([
            'source' => $source,
            'limit' => $limit,
            'requested_by' => $actorUserId,
        ]);
        $payload = [
            'workspace_id' => $workspaceId,
            'actor_user_id' => $actorUserId,
            'run' => $run,
        ];
        $runs[] = $payload;
        $runStatus = (string) ($run['status'] ?? 'unknown');
        $launcherStatus = in_array($runStatus, ['completed', 'completed_with_errors', 'blocked', 'failed'], true)
            ? $runStatus
            : 'completed_with_errors';
        marketingLiveExecutionCompleteLauncherRun($launcherRunId, $launcherStatus, $payload, (string) ($run['error_message'] ?? '') ?: null);

        if (!$json) {
            echo sprintf(
                "[%s] workspace=%d run=%d status=%s processed=%d succeeded=%d blocked=%d failed=%d\n",
                date('Y-m-d H:i:s'),
                $workspaceId,
                (int) ($run['id'] ?? 0),
                (string) ($run['status'] ?? 'unknown'),
                (int) ($run['processed_count'] ?? 0),
                (int) ($run['succeeded_count'] ?? 0),
                (int) ($run['blocked_count'] ?? 0),
                (int) ($run['failed_count'] ?? 0)
            );
        }

        if (in_array((string) ($run['status'] ?? ''), ['failed', 'blocked'], true)) {
            $exitCode = 1;
        }
    } catch (Throwable $e) {
        $exitCode = 1;
        $payload = [
            'workspace_id' => $workspaceId,
            'error' => $e->getMessage(),
        ];
        $runs[] = $payload;
        marketingLiveExecutionCompleteLauncherRun($launcherRunId, 'failed', $payload, $e->getMessage());
        fwrite(STDERR, sprintf(
            "[%s] workspace=%d failed: %s\n",
            date('Y-m-d H:i:s'),
            $workspaceId,
            $e->getMessage()
        ));
    } finally {
        WorkspaceContext::clearRuntimeWorkspace();
    }
}

if ($json) {
    echo json_encode([
        'runs' => $runs,
        'secret_safe' => true,
        'external_api_called_by_launcher' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit($exitCode);
