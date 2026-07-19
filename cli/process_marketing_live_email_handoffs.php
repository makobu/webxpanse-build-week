<?php
/**
 * Process Marketing live email handoffs.
 *
 * Usage:
 *   php cli/process_marketing_live_email_handoffs.php all 25
 *   php cli/process_marketing_live_email_handoffs.php 123 25
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');

$target = strtolower(trim((string) ($argv[1] ?? 'all')));
$limit = max(1, min(200, (int) ($argv[2] ?? 25)));
$workspaceIds = [];

if ($target !== '' && $target !== 'all') {
    $workspaceId = (int) $target;
    if ($workspaceId <= 0) {
        fwrite(STDERR, "Invalid workspace id. Use 'all' or a numeric workspace id.\n");
        exit(2);
    }
    $workspaceIds = [$workspaceId];
} else {
    $rows = Database::query(
        "SELECT DISTINCT leh.workspace_id
         FROM marketing_live_email_handoffs leh
         JOIN emails e ON e.id = leh.email_id AND e.workspace_id = leh.workspace_id
         JOIN email_queue eq ON eq.id = leh.email_queue_id AND eq.workspace_id = leh.workspace_id
         WHERE leh.status = 'queued'
           AND e.status = 'pending'
           AND eq.status = 'pending'
           AND (eq.scheduled_at IS NULL OR eq.scheduled_at <= NOW())
         ORDER BY leh.workspace_id ASC"
    );
    $workspaceIds = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['workspace_id'] ?? 0), $rows)));
}

if ($workspaceIds === []) {
    echo sprintf("[%s] No Marketing live email handoffs are ready.\n", date('Y-m-d H:i:s'));
    exit(0);
}

$exitCode = 0;
foreach ($workspaceIds as $workspaceId) {
    try {
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, null, 'owner');
        $run = (new Marketing())->processLiveEmailHandoffWorker([
            'source' => 'cli',
            'limit' => $limit,
        ]);
        echo sprintf(
            "[%s] workspace=%d run=%d status=%s processed=%d sent=%d blocked=%d failed=%d skipped=%d\n",
            date('Y-m-d H:i:s'),
            $workspaceId,
            (int) ($run['id'] ?? 0),
            (string) ($run['status'] ?? 'unknown'),
            (int) ($run['processed_count'] ?? 0),
            (int) ($run['sent_count'] ?? 0),
            (int) ($run['blocked_count'] ?? 0),
            (int) ($run['failed_count'] ?? 0),
            (int) ($run['skipped_count'] ?? 0)
        );
        if (in_array((string) ($run['status'] ?? ''), ['failed', 'blocked'], true)) {
            $exitCode = 1;
        }
    } catch (Throwable $e) {
        $exitCode = 1;
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

exit($exitCode);
