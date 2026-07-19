<?php
/**
 * Run the Marketing live execution orchestrator.
 *
 * Usage:
 *   php scripts/run_marketing_live_orchestrator.php all 25
 *   php scripts/run_marketing_live_orchestrator.php 123 25
 *   php scripts/run_marketing_live_orchestrator.php --workspace-id=123 --limit=25 --source=cron
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/marketing_live_cli_context.php';

use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\WorkspaceContext;

function marketingLiveOrchestratorUsage(int $exitCode = 0): void
{
    $message = <<<TXT
Marketing Live Orchestrator

Usage:
  php scripts/run_marketing_live_orchestrator.php all 25
  php scripts/run_marketing_live_orchestrator.php 123 25
  php scripts/run_marketing_live_orchestrator.php --workspace-id=123 --limit=25 --source=cron

Options:
  --workspace-id=<id>   Workspace to orchestrate. Omit or use "all" to scan workspaces.
  --limit=<n>           Max confirmed live items / email handoffs per workspace. Default: 25, max: 250.
  --source=<source>     cli, cron, api, manual, or test. Default: cli.
  --user-id=<id>        Optional workspace user with marketing.manage. Auto-resolves an owner/admin when omitted.
  --json               Output run details as JSON.
  --help               Show this help.

Safety:
  - CLI/cron/API sources require the live orchestrator schedule to be active in Marketing Execution.
  - The orchestrator coordinates existing guarded workers, including email and SMS/WhatsApp channel handoffs; it does not bypass policy, approvals, preflight, leases, throttle caps, or RUN LIVE confirmations.
  - No raw secrets are printed.

TXT;
    fwrite($exitCode === 0 ? STDOUT : STDERR, $message);
    exit($exitCode);
}

$options = getopt('', [
    'workspace-id::',
    'limit::',
    'source::',
    'user-id::',
    'json',
    'help',
]);

if (isset($options['help'])) {
    marketingLiveOrchestratorUsage(0);
}

Database::init(require __DIR__ . '/../config/database.php');

$positionalTarget = strtolower(trim((string) ($argv[1] ?? 'all')));
$positionalLimit = (int) ($argv[2] ?? 25);
$target = strtolower(trim((string) ($options['workspace-id'] ?? $positionalTarget)));
$limit = max(1, min(250, (int) ($options['limit'] ?? $positionalLimit ?: 25)));
$source = (string) ($options['source'] ?? 'cli');
$workspaceIds = [];

if ($target !== '' && $target !== 'all') {
    $workspaceId = (int) $target;
    if ($workspaceId <= 0) {
        marketingLiveOrchestratorUsage(1);
    }
    $workspaceIds = [$workspaceId];
} else {
    $rows = Database::query(
        "SELECT DISTINCT workspace_id
         FROM (
            SELECT workspace_id
            FROM marketing_execution_queue
            WHERE execution_mode = 'live'
            UNION
            SELECT workspace_id
            FROM marketing_live_email_handoffs
            WHERE status IN ('queued','processing','failed','blocked')
            UNION
            SELECT workspace_id
            FROM marketing_live_channel_handoffs
            WHERE status IN ('queued','processing','failed','blocked')
            UNION
            SELECT workspace_id
            FROM marketing_live_worker_schedules
            WHERE worker_key = 'marketing_live_orchestrator'
         ) workspace_scope
         ORDER BY workspace_id ASC"
    );
    $workspaceIds = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['workspace_id'] ?? 0), $rows)));
}

if ($workspaceIds === []) {
    echo sprintf("[%s] No Marketing live orchestration workspaces were found.\n", date('Y-m-d H:i:s'));
    exit(0);
}

$runs = [];
$exitCode = 0;
foreach ($workspaceIds as $workspaceId) {
    try {
        $actor = marketingLiveCliActivateManageContext($workspaceId, (int) ($options['user-id'] ?? 0));
        $run = (new Marketing())->runLiveExecutionOrchestrator([
            'source' => $source,
            'limit' => $limit,
            'requested_by' => (int) ($actor['user_id'] ?? 0),
        ]);
        $runs[] = [
            'workspace_id' => $workspaceId,
            'actor_user_id' => (int) ($actor['user_id'] ?? 0),
            'run' => $run,
        ];

        if (!isset($options['json'])) {
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
        $runs[] = [
            'workspace_id' => $workspaceId,
            'error' => $e->getMessage(),
        ];
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

if (isset($options['json'])) {
    echo json_encode([
        'runs' => $runs,
        'secret_safe' => true,
        'external_api_called_by_orchestrator' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit($exitCode);
