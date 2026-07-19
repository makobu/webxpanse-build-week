<?php

/**
 * Synchronize performance evidence for published Social Media jobs.
 *
 * Usage:
 *   php cli/process_social_media_metrics.php all 50
 *   php cli/process_social_media_metrics.php 42 50
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
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
use CRM\Services\SocialMediaService;
use CRM\Services\WorkspaceSkillCatalogService;

Database::init(require __DIR__ . '/../config/database.php');

$scope = strtolower(trim((string) ($argv[1] ?? 'all')));
$limit = max(1, min(200, (int) ($argv[2] ?? 50)));
$workspaceRows = $scope !== 'all' && ctype_digit($scope)
    ? [['workspace_id' => (int) $scope]]
    : Database::query(
        "SELECT DISTINCT workspace_id
         FROM workspace_skill_installs
         WHERE skill_key = ? AND status = 'installed'
         ORDER BY workspace_id ASC",
        [WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA]
    );

$total = ['workspaces' => 0, 'processed' => 0, 'synced' => 0, 'failed' => 0];
$errors = [];
foreach ($workspaceRows as $row) {
    $workspaceId = (int) ($row['workspace_id'] ?? 0);
    if ($workspaceId <= 0) {
        continue;
    }
    try {
        $result = (new SocialMediaService($workspaceId))->syncMetrics($limit);
        $total['workspaces']++;
        foreach (['processed', 'synced', 'failed'] as $key) {
            $total[$key] += (int) ($result[$key] ?? 0);
        }
    } catch (Throwable $e) {
        $errors[] = 'Workspace ' . $workspaceId . ': ' . $e->getMessage();
    }
}

echo sprintf(
    "[%s] Social metrics: workspaces=%d processed=%d synced=%d failed=%d errors=%d\n",
    date('Y-m-d H:i:s'),
    $total['workspaces'],
    $total['processed'],
    $total['synced'],
    $total['failed'],
    count($errors)
);
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
