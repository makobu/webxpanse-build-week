<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\AlertingSystem;

class OrganizationIntelligenceMonitoringService
{
    public function recordSignal(int $workspaceId, string $capability, string $errorCode, ?int $userId = null, bool $blocked = false): void
    {
        (new PluginRuntimeEventService())->record([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'skill_key' => 'hr_analytics_setup',
            'capability_key' => 'organization_intelligence.' . preg_replace('/[^a-z0-9_.-]+/i', '_', $capability),
            'event_type' => $blocked ? 'authorization_blocked' : ($errorCode === '' ? 'capability_succeeded' : 'capability_failed'),
            'status' => $blocked ? 'blocked' : ($errorCode === '' ? 'success' : 'failed'),
            'error_code' => $errorCode !== '' ? $errorCode : null,
            'metadata' => ['schema_version' => 2],
        ]);
    }

    public function evaluateWorkspace(int $workspaceId): array
    {
        $alerts = [];
        $snapshot = (new OrganizationIntelligenceSnapshotService())->diagnostics($workspaceId);
        if (($snapshot['status'] ?? '') === 'warning') {
            $alerts[] = $this->createOnce($workspaceId, 'snapshot_warning', 'medium', 'Organization Intelligence snapshot is over 36 hours old', [
                'age_hours' => $snapshot['age_hours'] ?? null,
            ]);
        } elseif (in_array((string) ($snapshot['status'] ?? ''), ['stale', 'missing'], true)) {
            $alerts[] = $this->createOnce($workspaceId, 'snapshot_stale', 'high', 'Organization Intelligence snapshots are stale', [
                'age_hours' => $snapshot['age_hours'] ?? null,
                'status' => $snapshot['status'] ?? 'missing',
            ]);
        }

        $lowEvidenceDays = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM (
                SELECT snapshot_date
                FROM organization_intelligence_snapshots
                WHERE workspace_id = ? AND total_people_count >= 5
                  AND (eligible_people_count / NULLIF(total_people_count, 0)) < 0.20
                ORDER BY snapshot_date DESC LIMIT 7
             ) low_evidence",
            [$workspaceId]
        )['c'] ?? 0);
        if ($lowEvidenceDays >= 7) {
            $alerts[] = $this->createOnce($workspaceId, 'insufficient_evidence', 'medium', 'Organization Intelligence evidence remains insufficient', [
                'consecutive_snapshot_count' => $lowEvidenceDays,
                'ineligible_ratio_threshold' => 0.80,
            ]);
        }

        $serializerFailures = $this->runtimeCount($workspaceId, 'organization_intelligence.serializer', 'oi_serializer_contract', '-15 minutes');
        if ($serializerFailures >= 3) {
            $alerts[] = $this->createOnce($workspaceId, 'serializer_contract', 'high', 'Organization Intelligence serializer contract is failing', [
                'failure_count_15m' => $serializerFailures,
            ]);
        }
        $scopeRejections = $this->runtimeCount($workspaceId, 'organization_intelligence.scope', 'oi_cross_workspace_rejection', '-15 minutes');
        if ($scopeRejections >= 5) {
            $alerts[] = $this->createOnce($workspaceId, 'scope_rejections', 'high', 'Repeated Organization Intelligence scope rejections detected', [
                'rejection_count_15m' => $scopeRejections,
            ]);
        }

        $aiRows = Database::query(
            "SELECT error_code FROM workspace_plugin_runtime_events
             WHERE workspace_id = ? AND capability_key = 'organization_intelligence.clarity_ai'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
             ORDER BY id DESC LIMIT 20",
            [$workspaceId]
        );
        $fallbacks = count(array_filter($aiRows, static fn(array $row): bool => (string) ($row['error_code'] ?? '') === 'oi_ai_fallback'));
        $consecutive = 0;
        foreach ($aiRows as $row) {
            if ((string) ($row['error_code'] ?? '') !== 'oi_ai_fallback') break;
            $consecutive++;
        }
        if ($consecutive >= 3 || (count($aiRows) >= 4 && ($fallbacks / count($aiRows)) > .25)) {
            $alerts[] = $this->createOnce($workspaceId, 'ai_fallback', 'medium', 'Organization Intelligence Clarity is repeatedly using fallback', [
                'fallback_count' => $fallbacks,
                'request_count' => count($aiRows),
                'consecutive_fallbacks' => $consecutive,
            ]);
        }
        return array_values(array_filter($alerts));
    }

    public function diagnostics(int $workspaceId): array
    {
        $aiRows = Database::query(
            "SELECT error_code FROM workspace_plugin_runtime_events
             WHERE workspace_id = ? AND capability_key = 'organization_intelligence.clarity_ai'
             ORDER BY id DESC LIMIT 20",
            [$workspaceId]
        );
        $fallbacks = count(array_filter($aiRows, static fn(array $row): bool => (string) ($row['error_code'] ?? '') === 'oi_ai_fallback'));
        $mobileSeen = Database::queryOne(
            "SELECT MAX(created_at) AS last_seen_at FROM workspace_plugin_runtime_events
             WHERE workspace_id = ? AND capability_key = 'organization_intelligence.mobile_contract'",
            [$workspaceId]
        );
        return [
            'ai_fallback_count' => $fallbacks,
            'ai_request_count' => count($aiRows),
            'ai_fallback_rate' => $aiRows !== [] ? round($fallbacks / count($aiRows), 4) : 0.0,
            'mobile_schema_versions_observed' => !empty($mobileSeen['last_seen_at']) ? [2] : [],
            'mobile_v2_last_seen_at' => $mobileSeen['last_seen_at'] ?? null,
            'serializer_failures_15m' => $this->runtimeCount($workspaceId, 'organization_intelligence.serializer', 'oi_serializer_contract', '-15 minutes'),
            'scope_rejections_15m' => $this->runtimeCount($workspaceId, 'organization_intelligence.scope', 'oi_cross_workspace_rejection', '-15 minutes'),
        ];
    }

    private function runtimeCount(int $workspaceId, string $capability, string $errorCode, string $since): int
    {
        $sinceAt = date('Y-m-d H:i:s', strtotime($since));
        return (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM workspace_plugin_runtime_events WHERE workspace_id = ? AND capability_key = ? AND error_code = ? AND created_at >= ?',
            [$workspaceId, $capability, $errorCode, $sinceAt]
        )['c'] ?? 0);
    }

    private function createOnce(int $workspaceId, string $signal, string $severity, string $message, array $metadata): ?int
    {
        $title = 'OI workspace ' . $workspaceId . ': ' . $signal;
        $existing = Database::queryOne("SELECT id FROM alerts WHERE title = ? AND status = 'active' LIMIT 1", [$title]);
        if ($existing) {
            return null;
        }
        return (new AlertingSystem())->createAlert('organization_intelligence', $severity, $title, $message, [
            'workspace_id' => $workspaceId,
            'signal' => $signal,
            'calculation_version' => OrganizationIntelligenceSnapshotService::CALCULATION_VERSION,
        ] + $metadata);
    }
}
