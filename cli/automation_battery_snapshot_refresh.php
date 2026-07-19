<?php
/**
 * Refresh stale automation battery snapshots.
 *
 * Cron example: run every 10 minutes with php /path/to/cli/automation_battery_snapshot_refresh.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/constants.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

use CRM\Database;
use CRM\Services\AutomationBatteryService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');

$options = getopt('', ['limit::', 'stale-minutes::']);
$limit = max(1, min(500, (int) ($options['limit'] ?? 50)));
$staleMinutes = max(1, min(1440, (int) ($options['stale-minutes'] ?? 10)));
$cutoff = date('Y-m-d H:i:s', time() - ($staleMinutes * 60));

if (!Database::tableExists('automation_battery_snapshots')) {
    echo json_encode([
        'success' => false,
        'error' => 'automation_battery_snapshots table is missing. Run database/migrations/migrate.php.',
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$rows = Database::query(
    "SELECT wm.workspace_id, wm.user_id
     FROM workspace_memberships wm
     JOIN users u ON u.id = wm.user_id
     LEFT JOIN automation_battery_snapshots abs
            ON abs.workspace_id = wm.workspace_id
           AND abs.subject_user_id = wm.user_id
     WHERE wm.membership_status = 'active'
       AND (
            abs.id IS NULL
            OR abs.expires_at <= NOW()
            OR abs.calculated_at <= ?
       )
     GROUP BY wm.workspace_id, wm.user_id
     ORDER BY COALESCE(abs.expires_at, '1970-01-01 00:00:00') ASC, wm.workspace_id ASC, wm.user_id ASC
     LIMIT {$limit}",
    [$cutoff]
);

$summary = [
    'success' => true,
    'checked' => count($rows),
    'refreshed' => 0,
    'failed' => 0,
    'stale_minutes' => $staleMinutes,
    'results' => [],
];

foreach ($rows as $row) {
    $workspaceId = (int) ($row['workspace_id'] ?? 0);
    $userId = (int) ($row['user_id'] ?? 0);
    if ($workspaceId <= 0 || $userId <= 0) {
        continue;
    }

    try {
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId);
        $status = (new AutomationBatteryService())->refreshStatus($userId, $userId, 'cron');
        $summary['refreshed']++;
        $summary['results'][] = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'score' => (int) ($status['score'] ?? 0),
            'status_label' => (string) ($status['status_label'] ?? ''),
            'updated_label' => (string) ($status['updated_label'] ?? ''),
        ];
    } catch (\Throwable $e) {
        $summary['failed']++;
        $summary['results'][] = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ];
    } finally {
        WorkspaceContext::clearRuntimeWorkspace();
    }
}

echo json_encode($summary, JSON_PRETTY_PRINT) . PHP_EOL;
