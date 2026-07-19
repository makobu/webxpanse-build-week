<?php

namespace CRM\Services;

use CRM\Database;

class OrganizationIntelligenceSnapshotService
{
    public const SCHEMA_VERSION = 2;
    public const CALCULATION_VERSION = 'oi-score-v2';

    public function capture(int $workspaceId, ?array $dashboard = null, string $source = 'manual', ?string $snapshotDate = null): array
    {
        $runtime = WorkspaceContext::runtimeSnapshot();
        try {
            WorkspaceContext::activateRuntimeWorkspace($workspaceId, null, 'system');
            $profile = (new OrganizationIntelligenceProfileService())->refreshInference($workspaceId);
            $dashboard = $dashboard ?? (new OrganizationIntelligenceEngineService())->buildDashboard(['timeframe' => 'month'], false, [
                'capability' => 'snapshot',
                'swot' => false,
                'manager_tips' => false,
                'strategic_pointers' => false,
            ]);
            $summary = (array) ($dashboard['summary'] ?? []);
            $employees = array_values((array) ($dashboard['employees'] ?? []));
            $eligiblePeople = array_values(array_filter($employees, static fn(array $employee): bool => (string) ($employee['score_status'] ?? '') === 'eligible'));
            $health = (array) ($dashboard['organization_health'] ?? []);
            $readiness = (array) ($dashboard['structural_readiness'] ?? []);
            $window = (array) (($dashboard['operating_trends']['window'] ?? []));
            $payloads = [
                'workspace' => $summary,
                'people' => array_map(static fn(array $employee): array => [
                    'id' => (int) ($employee['id'] ?? 0),
                    'score' => $employee['score'] ?? null,
                    'score_status' => (string) ($employee['score_status'] ?? 'insufficient_evidence'),
                    'evidence_coverage' => (float) ($employee['evidence_coverage'] ?? 0),
                    'open_tasks' => (int) ($employee['open_tasks'] ?? 0),
                    'overdue_open_tasks' => (int) ($employee['overdue_open_tasks'] ?? 0),
                    'department' => (string) ($employee['department'] ?? ''),
                    'department_source' => (string) ($employee['department_source'] ?? 'inferred_lane'),
                ], $employees),
                'functions' => (array) ($dashboard['function_coverage'] ?? []),
                'departments' => (array) ($dashboard['department_summaries'] ?? []),
                'components' => (array) ($health['components'] ?? []),
            ];
            $checksum = hash('sha256', json_encode([$profile, $payloads], JSON_UNESCAPED_SLASHES));
            $snapshotDate = $this->normalizeSnapshotDate($snapshotDate);
            $generatedAt = date('Y-m-d H:i:s');
            Database::execute(
                "INSERT INTO organization_intelligence_snapshots
                    (workspace_id, snapshot_date, schema_version, calculation_version, confirmed_model, inferred_model,
                     evidence_level, eligible_people_count, total_people_count, organization_health_score,
                     structural_readiness_score, measurement_window_start, measurement_window_end, workspace_metrics_json,
                     people_metrics_json, function_metrics_json, department_metrics_json, component_metrics_json,
                     source_checksum, generation_source, generated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE schema_version=VALUES(schema_version), calculation_version=VALUES(calculation_version),
                    confirmed_model=VALUES(confirmed_model), inferred_model=VALUES(inferred_model), evidence_level=VALUES(evidence_level),
                    eligible_people_count=VALUES(eligible_people_count), total_people_count=VALUES(total_people_count),
                    organization_health_score=VALUES(organization_health_score), structural_readiness_score=VALUES(structural_readiness_score),
                    measurement_window_start=VALUES(measurement_window_start), measurement_window_end=VALUES(measurement_window_end),
                    workspace_metrics_json=VALUES(workspace_metrics_json), people_metrics_json=VALUES(people_metrics_json),
                    function_metrics_json=VALUES(function_metrics_json), department_metrics_json=VALUES(department_metrics_json),
                    component_metrics_json=VALUES(component_metrics_json), source_checksum=VALUES(source_checksum),
                    generation_source=VALUES(generation_source), generated_at=VALUES(generated_at)",
                [
                    $workspaceId,
                    $snapshotDate,
                    self::SCHEMA_VERSION,
                    (string) ($dashboard['calculation_version'] ?? self::CALCULATION_VERSION),
                    $profile['confirmed_model'],
                    $profile['inferred_model'],
                    (string) (($dashboard['evidence_confidence']['level'] ?? 'low')),
                    count($eligiblePeople),
                    count($employees),
                    is_numeric($health['score'] ?? null) ? (float) $health['score'] : null,
                    is_numeric($readiness['score'] ?? null) ? (float) $readiness['score'] : null,
                    $window['start'] ?? null,
                    $window['end'] ?? null,
                    json_encode($payloads['workspace'], JSON_UNESCAPED_SLASHES),
                    json_encode($payloads['people'], JSON_UNESCAPED_SLASHES),
                    json_encode($payloads['functions'], JSON_UNESCAPED_SLASHES),
                    json_encode($payloads['departments'], JSON_UNESCAPED_SLASHES),
                    json_encode($payloads['components'], JSON_UNESCAPED_SLASHES),
                    $checksum,
                    substr(preg_replace('/[^a-z0-9_-]+/i', '_', $source) ?: 'manual', 0, 30),
                    $generatedAt,
                ]
            );
            $this->purgeExpired($workspaceId);
            return $this->latest($workspaceId) ?? [];
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($runtime);
        }
    }

    public function series(int $workspaceId, int $days = 30): array
    {
        $days = max(7, min(365, $days));
        $cutoff = date('Y-m-d', strtotime('-' . $days . ' days'));
        $rows = Database::query(
            "SELECT id, snapshot_date, calculation_version, organization_health_score, structural_readiness_score,
                    evidence_level, eligible_people_count, total_people_count, generated_at
             FROM organization_intelligence_snapshots
             WHERE workspace_id = ? AND snapshot_date >= ?
             ORDER BY snapshot_date ASC",
            [$workspaceId, $cutoff]
        );
        $versions = array_values(array_unique(array_map(static fn(array $row): string => (string) $row['calculation_version'], $rows)));
        $latestVersion = $rows !== [] ? (string) $rows[array_key_last($rows)]['calculation_version'] : self::CALCULATION_VERSION;
        $allPoints = array_map(static fn(array $row): array => [
            'snapshot_id' => (int) $row['id'],
            'date' => (string) $row['snapshot_date'],
            'label' => date('M j', strtotime((string) $row['snapshot_date'])),
            'value' => $row['organization_health_score'] !== null ? (float) $row['organization_health_score'] : null,
            'structural_readiness' => $row['structural_readiness_score'] !== null ? (float) $row['structural_readiness_score'] : null,
            'eligible_people_count' => (int) $row['eligible_people_count'],
            'total_people_count' => (int) $row['total_people_count'],
            'calculation_version' => (string) $row['calculation_version'],
            'has_data' => $row['organization_health_score'] !== null,
        ], $rows);
        $points = array_values(array_filter(
            $allPoints,
            static fn(array $point): bool => (string) ($point['calculation_version'] ?? '') === $latestVersion
        ));
        $methodologyMarkers = [];
        $previousVersion = null;
        foreach ($allPoints as $point) {
            $version = (string) ($point['calculation_version'] ?? '');
            if ($previousVersion !== null && $previousVersion !== $version) {
                $methodologyMarkers[] = [
                    'date' => (string) ($point['date'] ?? ''),
                    'from_version' => $previousVersion,
                    'to_version' => $version,
                ];
            }
            $previousVersion = $version;
        }
        $count = count($points);
        return [
            'points' => $points,
            'point_count' => $count,
            'status' => $count <= 1 ? 'baseline' : ($count < 7 ? 'limited' : 'ready'),
            'headline' => $count <= 1 ? 'Baseline captured' : ($count < 7 ? 'Limited comparison while history accumulates' : 'Measured daily trend'),
            'methodology_changed' => count($versions) > 1,
            'methodology_markers' => $methodologyMarkers,
            'versions' => $versions,
            'compatible_calculation_version' => $latestVersion,
            'latest' => $points !== [] ? $points[array_key_last($points)] : null,
        ];
    }

    public function latest(int $workspaceId): ?array
    {
        return Database::queryOne(
            'SELECT * FROM organization_intelligence_snapshots WHERE workspace_id = ? ORDER BY snapshot_date DESC, id DESC LIMIT 1',
            [$workspaceId]
        );
    }

    public function diagnostics(int $workspaceId): array
    {
        $latest = $this->latest($workspaceId);
        $ageHours = $latest ? max(0, (time() - strtotime((string) $latest['generated_at'])) / 3600) : null;
        $jobHealth = (new AutomationJobHealthService())->getJob('organization_intelligence_snapshots');
        return [
            'latest_snapshot_at' => $latest['generated_at'] ?? null,
            'latest_snapshot_id' => !empty($latest['id']) ? (int) $latest['id'] : null,
            'age_hours' => $ageHours !== null ? round($ageHours, 1) : null,
            'status' => $ageHours === null ? 'missing' : ($ageHours > 48 ? 'stale' : ($ageHours > 36 ? 'warning' : 'healthy')),
            'generation_source' => $latest['generation_source'] ?? null,
            'source_checksum' => $latest['source_checksum'] ?? null,
            'calculation_version' => $latest['calculation_version'] ?? null,
            'eligible_people_count' => isset($latest['eligible_people_count']) ? (int) $latest['eligible_people_count'] : null,
            'total_people_count' => isset($latest['total_people_count']) ? (int) $latest['total_people_count'] : null,
            'scheduler' => $jobHealth ? [
                'status' => (string) ($jobHealth['status'] ?? ''),
                'derived_status' => (string) ($jobHealth['derived_status'] ?? ''),
                'last_run_at' => $jobHealth['last_run_at'] ?? null,
                'last_success_at' => $jobHealth['last_success_at'] ?? null,
                'last_failure_at' => $jobHealth['last_failure_at'] ?? null,
                'last_duration_ms' => isset($jobHealth['last_duration_ms']) ? (int) $jobHealth['last_duration_ms'] : null,
                'metadata' => (array) ($jobHealth['metadata'] ?? []),
            ] : null,
        ];
    }

    public function purgeExpired(int $workspaceId): int
    {
        $cutoff = date('Y-m-d', strtotime('-24 months'));
        return Database::execute(
            'DELETE FROM organization_intelligence_snapshots WHERE workspace_id = ? AND snapshot_date < ?',
            [$workspaceId, $cutoff]
        );
    }

    private function normalizeSnapshotDate(?string $snapshotDate): string
    {
        $snapshotDate = trim((string) $snapshotDate);
        if ($snapshotDate === '') {
            return date('Y-m-d');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $snapshotDate);
        if (!$parsed || $parsed->format('Y-m-d') !== $snapshotDate) {
            throw new \InvalidArgumentException('Snapshot date must use YYYY-MM-DD in the application timezone.');
        }
        return $snapshotDate;
    }
}
