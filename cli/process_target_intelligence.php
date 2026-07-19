<?php
/**
 * Recompute target intelligence snapshots.
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
use CRM\Services\TargetIntelligenceScanQueueService;

Database::init(require __DIR__ . '/../config/database.php');

$service = new TargetIntelligenceScanQueueService();

try {
    $workspaces = Database::query('SELECT id FROM workspaces ORDER BY id ASC');
    foreach ($workspaces as $workspace) {
        $service->enqueueReconciliation((int) $workspace['id']);
    }
    $count = 0;
    do {
        $result = $service->processNext();
        $count += (int) ($result['processed'] ?? 0);
        $more = !empty($result['job_id']);
    } while ($more);
    echo "Target intelligence synced for {$count} targets across " . count($workspaces) . " workspaces." . PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Target intelligence sync failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
