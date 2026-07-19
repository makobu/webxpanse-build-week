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
use CRM\Services\WhatsAppTemplateService;

Database::init(require __DIR__ . '/../config/database.php');

$workspaceId = max(0, (int) ($argv[1] ?? 0));
$sql = "SELECT DISTINCT workspace_id FROM workspace_whatsapp_integrations WHERE connection_status = 'connected'";
$params = [];
if ($workspaceId > 0) {
    $sql .= " AND workspace_id = ?";
    $params[] = $workspaceId;
}

$service = new WhatsAppTemplateService();
$result = ['workspaces' => 0, 'synced' => 0, 'errors' => []];
foreach (Database::query($sql, $params) as $row) {
    $wid = (int) ($row['workspace_id'] ?? 0);
    if ($wid <= 0) {
        continue;
    }
    $result['workspaces']++;
    try {
        $sync = AsyncWorkspaceRunner::runWithWorkspace($wid, fn(): array => $service->syncFromProvider($wid));
        $result['synced'] += (int) ($sync['synced'] ?? 0);
    } catch (Throwable $e) {
        $result['errors'][] = ['workspace_id' => $wid, 'error' => $e->getMessage()];
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
