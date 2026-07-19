<?php
/**
 * Daily Digest Worker
 *
 * Safe to run from cron every few minutes. It sends each user's digest
 * once per day after the configured send time.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\AsyncWorkspaceRunner;
use CRM\Services\DefaultWorkspaceService;
use CRM\Services\EmailAssistantDigestService;
use CRM\Services\AutomationJobHealthService;

$dbConfig = require __DIR__ . '/../config/database.php';
Database::init($dbConfig);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from command line.\n";
    exit(1);
}

$force = in_array('--force', $argv ?? [], true);
$now = new \DateTimeImmutable('now');
$jobHealth = new AutomationJobHealthService();
$jobStartedAt = microtime(true);
$jobHealth->markStarted('email_assistant_digest', ['force' => $force]);
$result = [
    'ran' => false,
    'reason' => 'not_due',
    'sent' => 0,
    'failed' => 0,
    'skipped' => 0,
    'results' => [],
];

$appendWorkspaceResult = static function (array &$result, array $workspaceResult, ?int $workspaceId = null): void {
    if (!empty($workspaceResult['ran'])) {
        $result['ran'] = true;
        $result['reason'] = 'ok';
    }

    $result['sent'] += (int) ($workspaceResult['sent'] ?? 0);
    $result['failed'] += (int) ($workspaceResult['failed'] ?? 0);
    $result['skipped'] += (int) ($workspaceResult['skipped'] ?? 0);

    foreach ((array) ($workspaceResult['results'] ?? []) as $row) {
        $row = (array) $row;
        if ($workspaceId !== null && empty($row['workspace_id'])) {
            $row = ['workspace_id' => $workspaceId] + $row;
        }
        $result['results'][] = $row;
    }
};

$appendSkippedRun = static function (array &$result, string $reason, ?int $workspaceId = null, string $source = 'digest'): void {
    $result['skipped']++;
    $row = [
        'status' => 'skipped',
        'source' => $source,
        'reason' => $reason,
    ];
    if ($workspaceId !== null) {
        $row['workspace_id'] = $workspaceId;
    }
    $result['results'][] = $row;
};

$hasWorkspaceRows = EmailAssistantDigestService::hasWorkspaceDigestConfigRows();
if ($hasWorkspaceRows) {
    $workspaceIds = EmailAssistantDigestService::getDigestEnabledWorkspaceIds();
    $result['reason'] = empty($workspaceIds) ? 'no_workspace_digest_enabled' : 'workspace_digests_not_due';

    foreach ($workspaceIds as $workspaceId) {
        try {
            $workspaceResult = AsyncWorkspaceRunner::runWithWorkspace(
                $workspaceId,
                static function () use ($force, $now): array {
                    $service = new EmailAssistantDigestService();
                    return $service->runScheduled($force, $now);
                },
                null,
                'Daily digest workspace is missing or inactive.'
            );

            if (!empty($workspaceResult['ran'])) {
                $appendWorkspaceResult($result, $workspaceResult, $workspaceId);
            } else {
                $appendSkippedRun($result, (string) ($workspaceResult['reason'] ?? 'not_due'), $workspaceId, 'workspace_digest');
                $appendWorkspaceResult($result, $workspaceResult, $workspaceId);
            }
        } catch (\Throwable $e) {
            $result['ran'] = true;
            $result['reason'] = 'ok';
            $result['failed']++;
            $result['results'][] = [
                'status' => 'failed',
                'workspace_id' => $workspaceId,
                'email' => '',
                'error' => $e->getMessage(),
            ];
            error_log("Daily digest workspace {$workspaceId} failed: " . $e->getMessage());
        }
    }
}

$shouldRunLegacyDigest = $force || EmailAssistantDigestService::isLegacyDigestConfigEnabled();
if ($shouldRunLegacyDigest) {
    try {
        $defaultWorkspaceId = (new DefaultWorkspaceService())->id();
        if (EmailAssistantDigestService::workspaceHasEmailAssistantConfig($defaultWorkspaceId)) {
            if (!$hasWorkspaceRows) {
                $result['reason'] = 'legacy_default_workspace_has_email_assistant_config';
            }
            $appendSkippedRun($result, 'legacy_default_workspace_has_email_assistant_config', $defaultWorkspaceId, 'legacy_env_digest');
        } else {
            $legacyResult = AsyncWorkspaceRunner::runWithWorkspace(
                $defaultWorkspaceId,
                static function () use ($force, $now): array {
                    $service = new EmailAssistantDigestService();
                    return $service->runScheduled($force, $now);
                },
                null,
                'Daily digest default workspace is missing or inactive.'
            );

            if (empty($legacyResult['ran']) && !$hasWorkspaceRows) {
                $result['reason'] = (string) ($legacyResult['reason'] ?? 'not_due');
                if (!empty($legacyResult['message'])) {
                    $result['message'] = (string) $legacyResult['message'];
                }
            }

            if (empty($legacyResult['ran'])) {
                $appendSkippedRun($result, (string) ($legacyResult['reason'] ?? 'not_due'), $defaultWorkspaceId, 'legacy_env_digest');
            }
            $appendWorkspaceResult($result, $legacyResult, $defaultWorkspaceId);
        }
    } catch (\Throwable $e) {
        $result['ran'] = true;
        $result['reason'] = 'ok';
        $result['failed']++;
        $result['results'][] = [
            'status' => 'failed',
            'source' => 'legacy_env_digest',
            'email' => '',
            'error' => $e->getMessage(),
        ];
        error_log('Daily digest legacy env fallback failed: ' . $e->getMessage());
    }
} elseif (!$hasWorkspaceRows) {
    $result['reason'] = 'digest_disabled';
}

if (empty($result['ran'])) {
    $message = "[" . date('Y-m-d H:i:s') . "] Digest worker skipped: " . (string) ($result['reason'] ?? 'not_due');
    if (!empty($result['message'])) {
        $message .= " - " . (string) $result['message'];
    }
    echo $message . "\n";
    $jobHealth->markSuccess(
        'email_assistant_digest',
        'Skipped: ' . (string) ($result['reason'] ?? 'not_due'),
        (int) round((microtime(true) - $jobStartedAt) * 1000),
        ['sent' => 0, 'failed' => 0, 'skipped' => (int) ($result['skipped'] ?? 0)]
    );
    exit(0);
}

foreach ((array) ($result['results'] ?? []) as $row) {
    $email = (string) ($row['email'] ?? '');
    $status = (string) ($row['status'] ?? 'unknown');
    $target = $email !== '' ? $email : (string) ($row['source'] ?? 'digest run');
    $workspacePrefix = !empty($row['workspace_id']) ? 'Workspace ' . (int) $row['workspace_id'] . ': ' : '';
    if ($status === 'sent') {
        echo "[" . date('Y-m-d H:i:s') . "] {$workspacePrefix}Sent digest to {$target}\n";
    } elseif ($status === 'failed') {
        $error = (string) ($row['error'] ?? 'unknown error');
        error_log("Daily digest error for {$target}: " . $error);
        echo "[" . date('Y-m-d H:i:s') . "] {$workspacePrefix}Failed for {$target}: {$error}\n";
    } elseif ($status === 'skipped') {
        echo "[" . date('Y-m-d H:i:s') . "] {$workspacePrefix}Skipped {$target}: " . (string) ($row['reason'] ?? 'skipped') . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Daily digest completed. Sent: " . (int) ($result['sent'] ?? 0) . ", Failed: " . (int) ($result['failed'] ?? 0) . ", Skipped: " . (int) ($result['skipped'] ?? 0) . "\n";
$durationMs = (int) round((microtime(true) - $jobStartedAt) * 1000);
$jobMetadata = ['sent' => (int) ($result['sent'] ?? 0), 'failed' => (int) ($result['failed'] ?? 0), 'skipped' => (int) ($result['skipped'] ?? 0)];
if ((int) ($result['failed'] ?? 0) > 0) {
    $jobHealth->markFailure('email_assistant_digest', 'Digest completed with delivery failures.', $durationMs, $jobMetadata);
} else {
    $jobHealth->markSuccess('email_assistant_digest', 'Digest worker completed.', $durationMs, $jobMetadata);
}
exit(0);
