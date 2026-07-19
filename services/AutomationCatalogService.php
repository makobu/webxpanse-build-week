<?php

namespace CRM\Services;

use CRM\Database;

class AutomationCatalogService
{
    /** @var array<int,string> */
    private const FIRST_TWENTY_KEYS = [
        'missing_production_settings_detector',
        'broken_template_detector',
        'demo_test_data_detector',
        'failed_migration_detector',
        'failed_email_delivery_monitor',
        'failed_whatsapp_delivery_monitor',
        'integration_credential_missing_detector',
        'expiring_integration_credential_warning',
        'queue_backlog_monitor',
        'failed_background_job_monitor',
        'billing_package_mismatch_detector',
        'permission_drift_detector',
        'superadmin_access_repair_suggestion',
        'workspace_owner_missing_detector',
        'duplicate_default_templates_detector',
        'empty_onboarding_context_detector',
        'ai_runtime_overuse_detector',
        'disabled_critical_module_detector',
        'broken_marketplace_setup_detector',
        'daily_superadmin_health_digest',
    ];

    /** @var array<int,string> */
    private const RUN_STATUSES = ['observed', 'suggested', 'prepared', 'approval_required', 'applied', 'skipped', 'failed'];

    /** @var array<int,string> */
    private const SEVERITIES = ['info', 'warning', 'critical'];

    /** @var array<int,string> */
    private const REVIEW_STATUSES = ['none', 'open', 'approved', 'rejected', 'resolved'];

    public function schemaReady(): bool
    {
        return Database::tableExists('automation_catalog_definitions')
            && Database::tableExists('automation_catalog_controls')
            && Database::tableExists('automation_catalog_runs');
    }

    /**
     * @return array<int,string>
     */
    public function firstTwentyKeys(): array
    {
        return self::FIRST_TWENTY_KEYS;
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        if (!$this->schemaReady()) {
            return [
                'schema_ready' => false,
                'total' => 0,
                'first_twenty_seeded' => 0,
                'missing_first_twenty' => self::FIRST_TWENTY_KEYS,
            ];
        }

        $definitions = $this->listDefinitions();
        $keys = array_map(static fn(array $definition): string => (string) ($definition['automation_key'] ?? ''), $definitions);

        return [
            'schema_ready' => true,
            'total' => count($definitions),
            'first_twenty_seeded' => count(array_intersect(self::FIRST_TWENTY_KEYS, $keys)),
            'missing_first_twenty' => array_values(array_diff(self::FIRST_TWENTY_KEYS, $keys)),
            'by_category' => $this->countBy($definitions, 'category'),
            'by_risk' => $this->countBy($definitions, 'risk_level'),
            'by_mode' => $this->countBy($definitions, 'effective_mode'),
            'by_implementation_status' => $this->countBy($definitions, 'implementation_status'),
            'paused' => count(array_filter($definitions, static fn(array $definition): bool => !empty($definition['is_paused']))),
            'kill_switch_engaged' => count(array_filter($definitions, static fn(array $definition): bool => !empty($definition['kill_switch_engaged']))),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listDefinitions(?string $category = null): array
    {
        if (!$this->schemaReady()) {
            return [];
        }

        $params = [];
        $where = '';
        if ($category !== null && trim($category) !== '') {
            $where = 'WHERE d.category = ?';
            $params[] = trim($category);
        }

        $rows = Database::query(
            "SELECT d.*,
                    c.scope_key,
                    c.mode_override,
                    c.is_paused,
                    c.pause_reason,
                    c.kill_switch_engaged,
                    c.kill_switch_reason
             FROM automation_catalog_definitions d
             LEFT JOIN automation_catalog_controls c
               ON c.automation_id = d.id
              AND c.scope_key = 'global'
             {$where}
             ORDER BY FIELD(d.category, 'production_readiness', 'data_quality', 'reliability', 'security', 'communication', 'integrations', 'billing', 'templates', 'workspace_health', 'onboarding', 'ai_governance', 'settings', 'marketplace', 'reporting'),
                      d.risk_level DESC,
                      d.name ASC",
            $params
        );

        return array_map(fn(array $row): array => $this->hydrateDefinition($row), $rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findDefinition(string $automationKey): ?array
    {
        if (!$this->schemaReady() || trim($automationKey) === '') {
            return null;
        }

        $row = Database::queryOne(
            "SELECT d.*,
                    c.scope_key,
                    c.mode_override,
                    c.is_paused,
                    c.pause_reason,
                    c.kill_switch_engaged,
                    c.kill_switch_reason
             FROM automation_catalog_definitions d
             LEFT JOIN automation_catalog_controls c
               ON c.automation_id = d.id
              AND c.scope_key = 'global'
             WHERE d.automation_key = ?
             LIMIT 1",
            [trim($automationKey)]
        );

        return $row ? $this->hydrateDefinition($row) : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function recordRun(string $automationKey, array $payload): int
    {
        $definition = $this->findDefinition($automationKey);
        if ($definition === null) {
            throw new \RuntimeException('Automation catalog definition was not found: ' . $automationKey);
        }

        $runStatus = $this->oneOf((string) ($payload['run_status'] ?? 'observed'), self::RUN_STATUSES, 'observed');
        $severity = $this->oneOf((string) ($payload['severity'] ?? 'info'), self::SEVERITIES, 'info');
        $reviewStatus = $this->oneOf(
            (string) ($payload['review_status'] ?? $this->defaultReviewStatus($runStatus, $severity)),
            self::REVIEW_STATUSES,
            'none'
        );
        $automationId = (int) $definition['id'];
        $workspaceId = $this->nullableInt($payload['workspace_id'] ?? null);
        $actorUserId = $this->nullableInt($payload['actor_user_id'] ?? null);
        $evidenceJson = $this->encodeJson($payload['evidence'] ?? []);
        $recommendationJson = $this->encodeJson($payload['recommendation'] ?? []);
        $actionTakenJson = $this->encodeJson($payload['action_taken'] ?? []);
        $source = substr((string) ($payload['source'] ?? 'automation_catalog'), 0, 120);

        if ($this->dedupeReady() && ($payload['dedupe'] ?? true) !== false) {
            $fingerprint = $this->buildRunFingerprint($automationKey, $workspaceId, $runStatus, $severity, $payload);
            if (in_array($reviewStatus, ['none', 'open'], true)) {
                $duplicateRunId = $this->findDuplicateRunId($automationId, $workspaceId, $reviewStatus, $fingerprint);
                if ($duplicateRunId !== null) {
                    $this->refreshDuplicateRun(
                        $duplicateRunId,
                        $actorUserId,
                        $runStatus,
                        $severity,
                        $evidenceJson,
                        $recommendationJson,
                        $source
                    );
                    return $duplicateRunId;
                }
            }

            Database::execute(
                "INSERT INTO automation_catalog_runs
                    (automation_id, workspace_id, actor_user_id, run_status, severity,
                     evidence_json, recommendation_json, action_taken_json, review_status, source,
                     run_fingerprint, duplicate_count, first_seen_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())",
                [
                    $automationId,
                    $workspaceId,
                    $actorUserId,
                    $runStatus,
                    $severity,
                    $evidenceJson,
                    $recommendationJson,
                    $actionTakenJson,
                    $reviewStatus,
                    $source,
                    $fingerprint,
                ]
            );

            return (int) Database::lastInsertId();
        }

        Database::execute(
            "INSERT INTO automation_catalog_runs
                (automation_id, workspace_id, actor_user_id, run_status, severity,
                 evidence_json, recommendation_json, action_taken_json, review_status, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $automationId,
                $workspaceId,
                $actorUserId,
                $runStatus,
                $severity,
                $evidenceJson,
                $recommendationJson,
                $actionTakenJson,
                $reviewStatus,
                $source,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recentRuns(int $limit = 25): array
    {
        if (!$this->schemaReady()) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $orderBy = $this->dedupeReady()
            ? 'COALESCE(r.last_seen_at, r.created_at) DESC, r.id DESC'
            : 'r.created_at DESC, r.id DESC';
        $rows = Database::query(
            "SELECT r.*, d.automation_key, d.name, d.category, d.risk_level
             FROM automation_catalog_runs r
             JOIN automation_catalog_definitions d ON d.id = r.automation_id
             ORDER BY {$orderBy}
             LIMIT {$limit}"
        );

        return array_map(static function (array $row): array {
            foreach (['evidence_json' => 'evidence', 'recommendation_json' => 'recommendation', 'action_taken_json' => 'action_taken'] as $jsonKey => $targetKey) {
                $decoded = json_decode((string) ($row[$jsonKey] ?? ''), true);
                $row[$targetKey] = is_array($decoded) ? $decoded : [];
                unset($row[$jsonKey]);
            }
            return $row;
        }, $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateDefinition(array $row): array
    {
        $defaultMode = (string) ($row['default_mode'] ?? 'observe');
        $modeOverride = (string) ($row['mode_override'] ?? '');
        return [
            'id' => (int) ($row['id'] ?? 0),
            'automation_key' => (string) ($row['automation_key'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'purpose' => (string) ($row['purpose'] ?? ''),
            'risk_level' => (string) ($row['risk_level'] ?? ''),
            'trigger_key' => (string) ($row['trigger_key'] ?? ''),
            'frequency' => (string) ($row['frequency'] ?? ''),
            'required_permissions' => $this->decodeJson($row['required_permissions_json'] ?? null),
            'default_mode' => $defaultMode,
            'effective_mode' => $modeOverride !== '' ? $modeOverride : $defaultMode,
            'evidence' => $this->decodeJson($row['evidence_json'] ?? null),
            'action' => $this->decodeJson($row['action_json'] ?? null),
            'rollback' => $this->decodeJson($row['rollback_json'] ?? null),
            'owner_scope' => (string) ($row['owner_scope'] ?? ''),
            'implementation_status' => (string) ($row['implementation_status'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
            'scope_key' => (string) ($row['scope_key'] ?? 'global'),
            'is_paused' => !empty($row['is_paused']),
            'pause_reason' => (string) ($row['pause_reason'] ?? ''),
            'kill_switch_engaged' => !empty($row['kill_switch_engaged']),
            'kill_switch_reason' => (string) ($row['kill_switch_reason'] ?? ''),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countBy(array $rows, string $key): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = (string) ($row[$key] ?? '');
            if ($value === '') {
                $value = 'unknown';
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * @param array<int,string> $allowed
     */
    private function oneOf(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function defaultReviewStatus(string $runStatus, string $severity): string
    {
        if (in_array($runStatus, ['suggested', 'prepared', 'approval_required', 'failed'], true)) {
            return 'open';
        }
        return $severity === 'critical' ? 'open' : 'none';
    }

    private function dedupeReady(): bool
    {
        return Database::columnExists('automation_catalog_runs', 'run_fingerprint')
            && Database::columnExists('automation_catalog_runs', 'duplicate_count')
            && Database::columnExists('automation_catalog_runs', 'first_seen_at')
            && Database::columnExists('automation_catalog_runs', 'last_seen_at');
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }
        return (int) $value;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function buildRunFingerprint(string $automationKey, ?int $workspaceId, string $runStatus, string $severity, array $payload): string
    {
        $basis = [
            'automation_key' => $automationKey,
            'workspace_id' => $workspaceId,
            'run_status' => $runStatus,
            'severity' => $severity,
            'evidence' => $payload['evidence'] ?? [],
            'recommendation' => $payload['recommendation'] ?? [],
        ];

        if (array_key_exists('dedupe_key', $payload)) {
            $basis['dedupe_key'] = (string) $payload['dedupe_key'];
        }

        return hash('sha256', json_encode($this->canonicalize($basis), JSON_UNESCAPED_SLASHES) ?: serialize($basis));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = $this->canonicalize($item);
        }

        $keys = array_keys($canonical);
        $isList = $keys === [] || $keys === range(0, count($canonical) - 1);
        if (!$isList) {
            ksort($canonical);
        }

        return $canonical;
    }

    private function findDuplicateRunId(int $automationId, ?int $workspaceId, string $reviewStatus, string $fingerprint): ?int
    {
        $workspaceSql = $workspaceId === null ? 'r.workspace_id IS NULL' : 'r.workspace_id = ?';
        $params = [$automationId];
        if ($workspaceId !== null) {
            $params[] = $workspaceId;
        }
        $params[] = $reviewStatus;
        $params[] = $fingerprint;

        $row = Database::queryOne(
            "SELECT r.id
             FROM automation_catalog_runs r
             WHERE r.automation_id = ?
               AND {$workspaceSql}
               AND r.review_status = ?
               AND r.run_fingerprint = ?
             ORDER BY COALESCE(r.last_seen_at, r.created_at) DESC, r.id DESC
             LIMIT 1",
            $params
        );

        return $row !== null ? (int) ($row['id'] ?? 0) : null;
    }

    private function refreshDuplicateRun(
        int $runId,
        ?int $actorUserId,
        string $runStatus,
        string $severity,
        ?string $evidenceJson,
        ?string $recommendationJson,
        string $source
    ): void {
        Database::execute(
            "UPDATE automation_catalog_runs
             SET actor_user_id = ?,
                 run_status = ?,
                 severity = ?,
                 evidence_json = ?,
                 recommendation_json = ?,
                 source = ?,
                 duplicate_count = duplicate_count + 1,
                 last_seen_at = NOW()
             WHERE id = ?",
            [
                $actorUserId,
                $runStatus,
                $severity,
                $evidenceJson,
                $recommendationJson,
                $source,
                $runId,
            ]
        );
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: null;
    }

    /**
     * @return array<int|string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
