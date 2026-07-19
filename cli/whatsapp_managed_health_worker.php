<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\AsyncWorkspaceRunner;
use CRM\Services\ManagedWhatsAppProvisioningService;

Database::init(require __DIR__ . '/../config/database.php');

$workspaceId = max(0, (int) ($argv[1] ?? 0));
$sql = "SELECT workspace_id FROM workspace_whatsapp_integrations WHERE connection_mode = 'platform_managed'";
$params = [];
if ($workspaceId > 0) {
    $sql .= " AND workspace_id = ?";
    $params[] = $workspaceId;
}
$sql .= " ORDER BY managed_last_health_at IS NULL DESC, managed_last_health_at ASC, workspace_id ASC LIMIT 200";

$service = new ManagedWhatsAppProvisioningService();
$result = ['workspaces' => 0, 'healthy' => 0, 'needs_attention' => 0, 'errors' => []];
foreach (Database::query($sql, $params) as $row) {
    $wid = (int) ($row['workspace_id'] ?? 0);
    if ($wid <= 0) {
        continue;
    }
    $result['workspaces']++;
    try {
        $health = AsyncWorkspaceRunner::runWithWorkspace($wid, fn(): array => $service->healthCheck($wid));
        if (($health['status'] ?? '') === 'healthy') {
            $result['healthy']++;
        } else {
            $result['needs_attention']++;
        }
    } catch (Throwable $e) {
        $result['errors'][] = ['workspace_id' => $wid, 'error' => $e->getMessage()];
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
