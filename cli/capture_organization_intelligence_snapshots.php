<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

CRM\Database::init(require __DIR__ . '/../config/database.php');

$target = strtolower(trim((string) ($argv[1] ?? 'all')));
if (str_starts_with($target, '--workspace=')) {
    $target = substr($target, strlen('--workspace='));
} elseif (in_array($target, ['--all', '-a'], true)) {
    $target = 'all';
}
$workspaceIds = $target === 'all'
    ? array_map('intval', array_column(CRM\Database::query("SELECT id FROM workspaces WHERE status = 'active' ORDER BY id"), 'id'))
    : [(int) $target];
$service = new CRM\Services\OrganizationIntelligenceSnapshotService();
$jobHealth = new CRM\Services\AutomationJobHealthService();
$startedAt = microtime(true);
$jobHealth->markStarted('organization_intelligence_snapshots', [
    'requested_workspace_count' => count($workspaceIds),
    'application_timezone' => date_default_timezone_get(),
]);
$results = [];
foreach ($workspaceIds as $workspaceId) {
    if ($workspaceId <= 0) {
        continue;
    }
    try {
        $snapshot = $service->capture($workspaceId, null, 'scheduler', date('Y-m-d'));
        $results[] = ['workspace_id' => $workspaceId, 'status' => 'captured', 'snapshot_id' => (int) ($snapshot['id'] ?? 0)];
    } catch (Throwable $e) {
        $results[] = ['workspace_id' => $workspaceId, 'status' => 'failed', 'error' => $e->getMessage()];
    }
}
$failures = array_values(array_filter($results, static fn(array $row): bool => $row['status'] === 'failed'));
$durationMs = (int) round((microtime(true) - $startedAt) * 1000);
$metadata = [
    'workspace_count' => count($workspaceIds),
    'captured_count' => count($results) - count($failures),
    'failed_count' => count($failures),
    'snapshot_date' => date('Y-m-d'),
    'application_timezone' => date_default_timezone_get(),
];
if ($failures !== []) {
    $jobHealth->markFailure('organization_intelligence_snapshots', 'One or more workspace snapshots failed.', $durationMs, $metadata);
} else {
    $jobHealth->markSuccess('organization_intelligence_snapshots', 'Daily Organization Intelligence snapshots captured.', $durationMs, $metadata);
}
echo json_encode(['success' => $failures === [], 'results' => $results, 'metadata' => $metadata], JSON_PRETTY_PRINT), PHP_EOL;
exit($failures === [] ? 0 : 1);
