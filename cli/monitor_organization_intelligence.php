<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';

loadEnvFile(__DIR__ . '/../.env');
require_once __DIR__ . '/../config/constants.php';
CRM\Database::init(require __DIR__ . '/../config/database.php');

$options = getopt('', ['workspace::']);
$requestedWorkspaceId = (int) ($options['workspace'] ?? 0);
$workspaceIds = $requestedWorkspaceId > 0
    ? [$requestedWorkspaceId]
    : array_map('intval', array_column(
        CRM\Database::query("SELECT id FROM workspaces WHERE status = 'active' ORDER BY id"),
        'id'
    ));
$service = new CRM\Services\OrganizationIntelligenceMonitoringService();
$results = [];
foreach ($workspaceIds as $workspaceId) {
    try {
        $results[] = ['workspace_id' => $workspaceId, 'alerts_created' => $service->evaluateWorkspace($workspaceId)];
    } catch (Throwable $e) {
        $results[] = ['workspace_id' => $workspaceId, 'error' => get_class($e)];
    }
}
echo json_encode(['success' => !array_filter($results, static fn(array $row): bool => isset($row['error'])), 'results' => $results], JSON_PRETTY_PRINT), PHP_EOL;
