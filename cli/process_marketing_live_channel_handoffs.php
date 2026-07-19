<?php
/**
 * Process Marketing live SMS/WhatsApp channel handoffs.
 *
 * Usage:
 *   php cli/process_marketing_live_channel_handoffs.php all 25
 *   php cli/process_marketing_live_channel_handoffs.php 123 25
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
use CRM\Security;
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
    if (!Database::tableExists('marketing_live_channel_handoffs')) {
        echo sprintf("[%s] Marketing live channel handoff table is unavailable.\n", date('Y-m-d H:i:s'));
        exit(0);
    }

    $rows = [];
    if (Database::tableExists('sms_queue') && Database::tableExists('sms_messages')) {
        $rows = array_merge($rows, Database::query(
            "SELECT DISTINCT h.workspace_id
             FROM marketing_live_channel_handoffs h
             JOIN sms_queue sq ON sq.id = h.source_queue_id AND sq.workspace_id = h.workspace_id
             JOIN sms_messages sm ON sm.id = h.source_message_id AND sm.workspace_id = h.workspace_id
             WHERE h.status = 'queued'
               AND h.execution_type = 'sms'
               AND sq.status = 'pending'
               AND (sq.scheduled_at IS NULL OR sq.scheduled_at <= NOW())"
        ));
    }
    if (Database::tableExists('whatsapp_queue') && Database::tableExists('whatsapp_messages')) {
        $rows = array_merge($rows, Database::query(
            "SELECT DISTINCT h.workspace_id
             FROM marketing_live_channel_handoffs h
             JOIN whatsapp_queue wq ON wq.id = h.source_queue_id AND wq.workspace_id = h.workspace_id
             JOIN whatsapp_messages wm ON wm.id = h.source_message_id AND wm.workspace_id = h.workspace_id
             WHERE h.status = 'queued'
               AND h.execution_type = 'whatsapp'
               AND wq.status = 'pending'
               AND (wq.scheduled_at IS NULL OR wq.scheduled_at <= NOW())"
        ));
    }
    $workspaceIds = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['workspace_id'] ?? 0), $rows)));
    $workspaceIds = array_values(array_unique($workspaceIds));
    sort($workspaceIds);
}

if ($workspaceIds === []) {
    echo sprintf("[%s] No Marketing live channel handoffs are ready.\n", date('Y-m-d H:i:s'));
    exit(0);
}

$exitCode = 0;
foreach ($workspaceIds as $workspaceId) {
    try {
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, null, 'owner');
        $run = (new Marketing())->processLiveChannelHandoffWorker([
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
            mb_substr(Security::sanitizeInput($e->getMessage(), 'string'), 0, 500)
        ));
    } finally {
        WorkspaceContext::clearRuntimeWorkspace();
    }
}

exit($exitCode);
