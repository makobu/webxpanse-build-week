<?php

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
use CRM\Services\AutomationJobHealthService;
use CRM\Services\SmartTemplateGenerationService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');

$options = getopt('', ['workspace-id::', 'user-id::', 'force', 'dry-run', 'limit::']);
$workspaceFilter = isset($options['workspace-id']) ? (int) $options['workspace-id'] : 0;
$userFilter = isset($options['user-id']) ? (int) $options['user-id'] : 0;
$force = array_key_exists('force', $options);
$dryRun = array_key_exists('dry-run', $options);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 50;

$job = new AutomationJobHealthService();
$started = microtime(true);
$processed = 0;
$generated = 0;
$skipped = 0;
$errors = [];

try {
    $job->markStarted('smart_template_refresh', [
        'workspace_id' => $workspaceFilter ?: null,
        'user_id' => $userFilter ?: null,
        'dry_run' => $dryRun,
        'force' => $force,
    ]);

    $where = ["wm.membership_status = 'active'", "w.status = 'active'"];
    $params = [];
    if ($workspaceFilter > 0) {
        $where[] = 'wm.workspace_id = ?';
        $params[] = $workspaceFilter;
    }
    if ($userFilter > 0) {
        $where[] = 'wm.user_id = ?';
        $params[] = $userFilter;
    }
    $params[] = $limit;

    $targets = Database::query(
        "SELECT DISTINCT wm.workspace_id, wm.user_id, wm.role_slug
         FROM workspace_memberships wm
         JOIN workspaces w ON w.id = wm.workspace_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY wm.workspace_id ASC, wm.is_owner DESC, wm.user_id ASC
         LIMIT ?",
        $params
    );

    foreach ($targets as $target) {
        $workspaceId = (int) ($target['workspace_id'] ?? 0);
        $userId = (int) ($target['user_id'] ?? 0);
        if ($workspaceId <= 0 || $userId <= 0) {
            continue;
        }

        $processed++;
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, (string) ($target['role_slug'] ?? 'owner'));

        try {
            $service = new SmartTemplateGenerationService();
            $status = $service->getStatus($userId);
            $shouldGenerate = !empty($status['is_ready'])
                && empty($status['has_candidate_set'])
                && ($force || !empty($status['refresh_due']) || empty($status['has_active_set']));

            if (!$shouldGenerate) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $generated++;
                echo "DRY RUN candidate due for workspace {$workspaceId}, user {$userId}\n";
                continue;
            }

            $result = $service->generateCandidateForUser(
                $userId,
                empty($status['has_active_set']) ? 'initial_periodic_generation' : 'periodic_refresh'
            );
            $generated++;
            echo "Created candidate set {$result['smart_template_set_id']} for workspace {$workspaceId}, user {$userId}\n";
        } catch (Throwable $e) {
            $errors[] = "workspace {$workspaceId}, user {$userId}: " . $e->getMessage();
            echo "ERROR workspace {$workspaceId}, user {$userId}: {$e->getMessage()}\n";
        }
    }

    $durationMs = (int) round((microtime(true) - $started) * 1000);
    $metadata = [
        'processed' => $processed,
        'generated' => $generated,
        'skipped' => $skipped,
        'error_count' => count($errors),
        'dry_run' => $dryRun,
    ];

    if ($errors !== []) {
        $job->markFailure('smart_template_refresh', implode(' | ', array_slice($errors, 0, 5)), $durationMs, $metadata);
        exit(1);
    }

    $job->markSuccess('smart_template_refresh', "Processed {$processed}; generated {$generated}; skipped {$skipped}.", $durationMs, $metadata);
    echo "Processed {$processed}; generated {$generated}; skipped {$skipped}.\n";
} catch (Throwable $e) {
    $durationMs = (int) round((microtime(true) - $started) * 1000);
    try {
        $job->markFailure('smart_template_refresh', $e->getMessage(), $durationMs, [
            'processed' => $processed,
            'generated' => $generated,
            'skipped' => $skipped,
        ]);
    } catch (Throwable $ignored) {
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
