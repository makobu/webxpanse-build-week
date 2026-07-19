<?php
/**
 * Sync Marketing live execution outcomes from downstream evidence.
 *
 * Usage:
 *   php scripts/sync_marketing_live_outcomes.php all 250
 *   php scripts/sync_marketing_live_outcomes.php 123 250
 *   php scripts/sync_marketing_live_outcomes.php --workspace-id=123 --limit=250 --source=cron
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/marketing_live_cli_context.php';

use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\WorkspaceContext;

function marketingLiveOutcomeSyncUsage(int $exitCode = 0): void
{
    $message = <<<TXT
Marketing Live Outcome Sync

Usage:
  php scripts/sync_marketing_live_outcomes.php all 250
  php scripts/sync_marketing_live_outcomes.php 123 250
  php scripts/sync_marketing_live_outcomes.php --workspace-id=123 --limit=250 --source=cron

Options:
  --workspace-id=<id>   Workspace to sync. Omit or use "all" to sync workspaces with live evidence.
  --limit=<n>           Max live queue items to reconcile per workspace. Default: 250, max: 1000.
  --source=<source>     cli, cron, api, manual, or test. Default: cli.
  --user-id=<id>        Optional workspace user with marketing.manage. Auto-resolves an owner/admin when omitted.
  --json               Output run details as JSON.
  --help               Show this help.

Safety:
  - CLI/cron/API sources require the outcome sync schedule to be active in Marketing Execution.
  - Reconciles existing CRM evidence only; it does not publish, send, or call external APIs.
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
    marketingLiveOutcomeSyncUsage(0);
}

Database::init(require __DIR__ . '/../config/database.php');

$positionalTarget = strtolower(trim((string) ($argv[1] ?? 'all')));
$positionalLimit = (int) ($argv[2] ?? 250);
$target = strtolower(trim((string) ($options['workspace-id'] ?? $positionalTarget)));
$limit = max(1, min(1000, (int) ($options['limit'] ?? $positionalLimit ?: 250)));
$source = (string) ($options['source'] ?? 'cli');
$workspaceIds = [];

if ($target !== '' && $target !== 'all') {
    $workspaceId = (int) $target;
    if ($workspaceId <= 0) {
        marketingLiveOutcomeSyncUsage(1);
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
            FROM marketing_live_worker_schedules
            WHERE worker_key = 'marketing_live_outcome_sync'
         ) workspace_scope
         ORDER BY workspace_id ASC"
    );
    $workspaceIds = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['workspace_id'] ?? 0), $rows)));
}

if ($workspaceIds === []) {
    echo sprintf("[%s] No Marketing live outcome workspaces were found.\n", date('Y-m-d H:i:s'));
    exit(0);
}

$runs = [];
$exitCode = 0;
foreach ($workspaceIds as $workspaceId) {
    try {
        $actor = marketingLiveCliActivateManageContext($workspaceId, (int) ($options['user-id'] ?? 0));
        $run = (new Marketing())->runLiveOutcomeSyncWorker([
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
                "[%s] workspace=%d run=%d status=%s processed=%d delivered=%d failed=%d blocked=%d unknown=%d\n",
                date('Y-m-d H:i:s'),
                $workspaceId,
                (int) ($run['id'] ?? 0),
                (string) ($run['status'] ?? 'unknown'),
                (int) ($run['processed_count'] ?? 0),
                (int) ($run['delivered_count'] ?? 0),
                (int) ($run['failed_count'] ?? 0),
                (int) ($run['blocked_count'] ?? 0),
                (int) ($run['unknown_count'] ?? 0)
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
        'external_api_called' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit($exitCode);
