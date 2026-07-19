<?php

require_once __DIR__ . '/../vendor/autoload.php';
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
require_once __DIR__ . '/../config/constants.php';
\CRM\Database::init(require __DIR__ . '/../config/database.php');

$once = in_array('--once', $argv, true);
$workspaceId = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--workspace=')) $workspaceId = max(1, (int) substr($argument, 12));
}

do {
    $worked = false;
    try {
        $recovered = (new \CRM\Services\VoiceCallService())->recoverStalledOutboundDispatches($workspaceId);
        $worked = $worked || $recovered > 0;
    } catch (Throwable $e) {
        error_log('Voice dispatch recovery worker: ' . $e->getMessage());
    }
    try {
        $dispatched = (new \CRM\Services\VoiceCallService())->dispatchNextOutbound($workspaceId);
        $worked = $worked || $dispatched !== null;
    } catch (Throwable $e) {
        error_log('Voice dispatch worker: ' . $e->getMessage());
    }
    try {
        $processed = (new \CRM\Services\VoiceIntelligenceService())->processNext($workspaceId);
        $worked = $worked || $processed !== null;
    } catch (Throwable $e) {
        error_log('Voice intelligence worker: ' . $e->getMessage());
    }
    try {
        $retention = (new \CRM\Services\VoiceRetentionService())->cleanup($workspaceId, 100);
        $worked = $worked || array_sum($retention) > 0;
    } catch (Throwable $e) {
        error_log('Voice retention worker: ' . $e->getMessage());
    }
    $workspaceRows = $workspaceId ? [['workspace_id' => $workspaceId]] : \CRM\Database::query('SELECT workspace_id FROM workspace_voice_configs WHERE enabled = 1');
    foreach ($workspaceRows as $row) {
        $wid = (int) $row['workspace_id'];
        $previousHeartbeat = \CRM\Database::queryOne(
            "SELECT metadata_json FROM voice_worker_heartbeats WHERE workspace_id = ? AND worker_key = 'voice_worker' LIMIT 1",
            [$wid]
        ) ?: [];
        $heartbeatMetadata = json_decode((string) ($previousHeartbeat['metadata_json'] ?? '{}'), true);
        if (!is_array($heartbeatMetadata)) $heartbeatMetadata = [];
        $lastIndexRefresh = strtotime((string) ($heartbeatMetadata['contact_index_refreshed_at'] ?? '')) ?: 0;
        if ($lastIndexRefresh < time() - 300) {
            try {
                $heartbeatMetadata['contact_index_count'] = (new \CRM\Services\VoiceContactPhoneIndexService())->refreshWorkspace($wid);
                $heartbeatMetadata['contact_index_refreshed_at'] = date(DATE_ATOM);
            } catch (Throwable $e) {
                $heartbeatMetadata['contact_index_error'] = substr($e->getMessage(), 0, 300);
                error_log('Voice contact phone index worker: ' . $e->getMessage());
            }
        }
        $pending = (int) (\CRM\Database::queryOne("SELECT COUNT(*) AS c FROM voice_transcription_jobs WHERE workspace_id = ? AND status IN ('pending','processing','failed')", [$wid])['c'] ?? 0);
        $failed = (int) (\CRM\Database::queryOne("SELECT COUNT(*) AS c FROM voice_transcription_jobs WHERE workspace_id = ? AND status = 'dead_letter'", [$wid])['c'] ?? 0);
        \CRM\Database::execute(
            "INSERT INTO voice_worker_heartbeats (workspace_id, worker_key, status, pending_count, failed_count, heartbeat_at, metadata_json) VALUES (?, 'voice_worker', ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), pending_count = VALUES(pending_count), failed_count = VALUES(failed_count), heartbeat_at = NOW(), metadata_json = VALUES(metadata_json), updated_at = NOW()",
            [$wid, $failed > 0 ? 'degraded' : 'healthy', $pending, $failed, json_encode($heartbeatMetadata, JSON_UNESCAPED_SLASHES)]
        );
    }
    if (!$once) sleep($worked ? 1 : 3);
} while (!$once);
