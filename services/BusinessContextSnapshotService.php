<?php

namespace CRM\Services;

use CRM\Database;

final class BusinessContextSnapshotService
{
    private AIOperatingContextService $operatingContext;

    public function __construct(?AIOperatingContextService $operatingContext = null)
    {
        $this->operatingContext = $operatingContext ?? new AIOperatingContextService();
    }

    /**
     * Build one immutable context view for the whole command-center request.
     * Downstream services should receive this snapshot instead of rebuilding
     * company, strategy, finance, Journey, and Founder Loop context separately.
     *
     * @return array<string,mixed>
     */
    public function build(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('A workspace and user are required for a business context snapshot.');
        }

        $operating = $this->operatingContext->buildForSurface($userId, 'founder_command_center', [
            'current_page' => 'dashboard.php',
            'skip_marketplace' => true,
        ]);
        $contextWorkspaceId = (int) ($operating['identity']['workspace_id'] ?? 0);
        if ($contextWorkspaceId !== $workspaceId) {
            throw new \RuntimeException('The operating context does not match the requested workspace.');
        }

        return $this->buildFromOperatingContext(
            $workspaceId,
            $userId,
            $operating,
            $this->sourceVersions($workspaceId, $userId)
        );
    }

    /**
     * Pure assembly path used by tests and by callers that already own a context.
     *
     * @param array<string,mixed> $operating
     * @param array<string,array<string,mixed>> $sourceVersions
     * @return array<string,mixed>
     */
    public function buildFromOperatingContext(
        int $workspaceId,
        int $userId,
        array $operating,
        array $sourceVersions = []
    ): array {
        $company = (array) ($operating['company_context'] ?? []);
        $strategy = (array) ($operating['user_strategy_context'] ?? []);
        $journey = (array) ($operating['startup_journey_context'] ?? []);
        $finance = (array) ($operating['finance_context'] ?? []);
        $loop = (array) ($operating['founder_operating_loop_context'] ?? []);
        $maturity = (array) ($operating['operating_maturity'] ?? []);
        $conflicts = array_values(array_filter((array) ($operating['assumption_conflicts'] ?? []), 'is_array'));

        $dimensions = [
            'company' => [
                'label' => 'Business identity',
                'ready' => $this->hasAnyText($company, ['name', 'description', 'mission', 'industry']),
                'weight' => 25,
                'source_key' => 'company_profile',
            ],
            'strategy' => [
                'label' => 'Customer and offer',
                'ready' => !empty($strategy['has_explicit_profile']) || $this->hasAnyText($strategy, [
                    'target_market_focus',
                    'ideal_customer_profile',
                    'offer_angle',
                    'segment_focus',
                    'strategy_hypothesis',
                ]),
                'weight' => 25,
                'source_key' => 'strategy_snapshot',
            ],
            'journey' => [
                'label' => 'Clarity Journey',
                'ready' => $this->journeyHasContext($journey),
                'weight' => 25,
                'source_key' => 'startup_journey',
            ],
            'operations' => [
                'label' => 'Operating evidence',
                'ready' => $this->hasOperatingEvidence($operating, $loop),
                'weight' => 25,
                'source_key' => 'operating_evidence',
            ],
        ];

        $strength = 0;
        $missing = [];
        foreach ($dimensions as $key => &$dimension) {
            $dimension['status'] = !empty($dimension['ready']) ? 'ready' : 'missing';
            if (!empty($dimension['ready'])) {
                $strength += (int) $dimension['weight'];
            } else {
                $missing[] = [
                    'key' => $key,
                    'label' => (string) $dimension['label'],
                    'source_key' => (string) $dimension['source_key'],
                ];
            }
        }
        unset($dimension);

        $missing = $this->dedupeMissing($missing);

        $staleSources = array_values(array_filter($sourceVersions, static function (array $source): bool {
            return (string) ($source['freshness_status'] ?? '') === 'stale';
        }));
        $missingSources = array_values(array_filter($sourceVersions, static function (array $source): bool {
            return (string) ($source['freshness_status'] ?? '') === 'missing';
        }));
        $highConflicts = array_values(array_filter($conflicts, static fn(array $conflict): bool => (string) ($conflict['severity'] ?? '') === 'high'));

        $healthStatus = 'ready';
        if ($strength < 40) {
            $healthStatus = 'blocked';
        } elseif ($highConflicts !== [] || $this->hasCriticalStaleSource($sourceVersions)) {
            $healthStatus = 'needs_confirmation';
        } elseif ($strength < 75 || $missing !== []) {
            $healthStatus = 'building';
        }

        $summary = [
            'company_name' => trim((string) ($company['name'] ?? '')),
            'customer_focus' => $this->firstText([
                $strategy['ideal_customer_profile'] ?? '',
                $strategy['target_market_focus'] ?? '',
                $strategy['segment_focus'] ?? '',
                $strategy['summary']['primary_segment'] ?? '',
            ]),
            'offer' => $this->firstText([
                $strategy['offer_angle'] ?? '',
                $strategy['summary']['offer_angle'] ?? '',
                $company['description'] ?? '',
            ]),
            'operating_stage' => (string) ($maturity['stage'] ?? ''),
            'operating_stage_label' => (string) ($maturity['label'] ?? ''),
            'currency_code' => (string) (
                $finance['currency_code']
                ?? $finance['summary']['currency_code']
                ?? $strategy['budget_context']['currency_code']
                ?? 'USD'
            ),
        ];

        $fingerprintBasis = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'sources' => array_map(static fn(array $source): array => [
                'source' => (string) ($source['source'] ?? ''),
                'scope' => (string) ($source['scope'] ?? ''),
                'record_id' => $source['record_id'] ?? null,
                'source_record' => (string) ($source['source_record'] ?? ''),
                'observed_at' => $source['observed_at'] ?? null,
                'freshness_status' => (string) ($source['freshness_status'] ?? 'unknown'),
            ], $sourceVersions),
            'summary' => $summary,
            'maturity' => $summary['operating_stage'],
            'conflicts' => array_map(static fn(array $conflict): array => [
                'type' => (string) ($conflict['type'] ?? ''),
                'severity' => (string) ($conflict['severity'] ?? ''),
                'evidence' => (string) ($conflict['operating_evidence'] ?? ''),
            ], $conflicts),
        ];

        return [
            'snapshot_id' => hash('sha256', json_encode($fingerprintBasis, JSON_UNESCAPED_SLASHES) ?: serialize($fingerprintBasis)),
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'generated_at' => gmdate('c'),
            'health' => [
                'status' => $healthStatus,
                'strength' => max(0, min(100, $strength)),
                'missing_count' => count($missing),
                'conflict_count' => count($conflicts),
                'stale_source_count' => count($staleSources),
                'missing_source_count' => count($missingSources),
            ],
            'summary' => $summary,
            'dimensions' => $dimensions,
            'missing_context' => $missing,
            'conflicts' => array_slice($conflicts, 0, 8),
            'source_versions' => $sourceVersions,
            'operating_context' => $operating,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function sourceVersions(int $workspaceId, int $userId): array
    {
        $definitions = [
            'company_profile' => ['table' => 'company_profile', 'scope' => 'workspace', 'stale_after' => 15552000],
            'strategy_snapshot' => ['table' => 'user_strategy_snapshots', 'scope' => 'personal', 'stale_after' => 3888000],
            'startup_journey' => ['table' => 'startup_journeys', 'scope' => 'personal', 'stale_after' => 2592000],
            'workspace_onboarding' => ['table' => 'workspace_onboarding_state', 'scope' => 'workspace', 'stale_after' => 7776000],
            'finance_evidence' => ['table' => 'finance_expenses', 'scope' => 'workspace', 'stale_after' => 604800],
            'founder_review' => ['table' => 'founder_weekly_reviews', 'scope' => 'personal', 'stale_after' => 1209600],
        ];

        $versions = [];
        foreach ($definitions as $key => $definition) {
            $versions[$key] = $this->sourceVersion(
                (string) $definition['table'],
                (string) $definition['scope'],
                (int) $definition['stale_after'],
                $workspaceId,
                $userId
            );
        }

        $versions['operating_evidence'] = $this->operatingEvidenceVersion($workspaceId);

        return $versions;
    }

    /** @return array<string,mixed> */
    private function operatingEvidenceVersion(int $workspaceId): array
    {
        $records = [];
        foreach (['contacts', 'deals', 'tasks', 'conversation_threads', 'targets'] as $table) {
            try {
                if (!Database::tableExists($table) || !Database::columnExists($table, 'workspace_id')) {
                    continue;
                }
                $dateColumn = Database::columnExists($table, 'updated_at')
                    ? 'updated_at'
                    : (Database::columnExists($table, 'created_at') ? 'created_at' : '');
                if ($dateColumn === '') {
                    continue;
                }
                $hasId = Database::columnExists($table, 'id');
                $idSelect = $hasId ? 'id' : 'NULL AS id';
                $orderBy = $dateColumn . ' DESC' . ($hasId ? ', id DESC' : '');
                $row = Database::queryOne(
                    "SELECT {$idSelect}, {$dateColumn} AS observed_at
                     FROM `{$table}`
                     WHERE workspace_id = ?
                     ORDER BY {$orderBy}
                     LIMIT 1",
                    [$workspaceId]
                );
                $timestamp = !empty($row['observed_at']) ? strtotime((string) $row['observed_at']) : false;
                if ($timestamp === false) {
                    continue;
                }
                $records[] = [
                    'table' => $table,
                    'record_id' => isset($row['id']) ? (int) $row['id'] : null,
                    'timestamp' => $timestamp,
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }

        if ($records === []) {
            return [
                'source' => 'live_crm',
                'scope' => 'workspace',
                'record_id' => null,
                'source_record' => '',
                'observed_at' => null,
                'age_seconds' => null,
                'freshness_status' => 'missing',
                'confidence' => 0.0,
            ];
        }

        usort($records, static function (array $left, array $right): int {
            $timeCompare = ((int) $right['timestamp']) <=> ((int) $left['timestamp']);
            return $timeCompare !== 0
                ? $timeCompare
                : strcmp((string) $left['table'], (string) $right['table']);
        });
        $latest = $records[0];
        $sourceRecord = implode('|', array_map(static fn(array $record): string => implode(':', [
            (string) $record['table'],
            (string) ($record['record_id'] ?? ''),
            (string) $record['timestamp'],
        ]), $records));

        $ageSeconds = max(0, time() - (int) $latest['timestamp']);
        $freshness = $ageSeconds > 2592000 ? 'stale' : ($ageSeconds > 1209600 ? 'aging' : 'fresh');
        return [
            'source' => 'live_crm',
            'scope' => 'workspace',
            'record_id' => $latest['record_id'],
            'source_record' => $sourceRecord,
            'observed_at' => date(DATE_ATOM, (int) $latest['timestamp']),
            'age_seconds' => $ageSeconds,
            'freshness_status' => $freshness,
            'confidence' => $freshness === 'stale' ? 0.65 : ($freshness === 'aging' ? 0.82 : 1.0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceVersion(
        string $table,
        string $scope,
        int $staleAfter,
        int $workspaceId,
        int $userId
    ): array {
        $empty = [
            'source' => $table,
            'scope' => $scope,
            'record_id' => null,
            'observed_at' => null,
            'age_seconds' => null,
            'freshness_status' => 'missing',
            'confidence' => 0.0,
        ];

        try {
            if (!Database::tableExists($table)) {
                return $empty;
            }

            $where = [];
            $params = [];
            if (Database::columnExists($table, 'workspace_id')) {
                $where[] = 'workspace_id = ?';
                $params[] = $workspaceId;
            }
            if ($scope === 'personal' && Database::columnExists($table, 'user_id')) {
                $where[] = 'user_id = ?';
                $params[] = $userId;
            }

            $dateColumn = Database::columnExists($table, 'updated_at')
                ? 'updated_at'
                : (Database::columnExists($table, 'created_at') ? 'created_at' : '');
            if ($dateColumn === '') {
                return $empty;
            }

            $hasId = Database::columnExists($table, 'id');
            $idSelect = $hasId ? 'id' : 'NULL AS id';
            $sql = "SELECT {$idSelect}, {$dateColumn} AS observed_at FROM `{$table}`";
            if ($where !== []) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY ' . $dateColumn . ' DESC' . ($hasId ? ', id DESC' : '') . ' LIMIT 1';
            $row = Database::queryOne($sql, $params);
            if (!$row || empty($row['observed_at'])) {
                return $empty;
            }

            $observedTimestamp = strtotime((string) $row['observed_at']);
            $ageSeconds = $observedTimestamp !== false ? max(0, time() - $observedTimestamp) : null;
            $freshness = $ageSeconds === null
                ? 'unknown'
                : ($ageSeconds > $staleAfter ? 'stale' : ($ageSeconds > (int) floor($staleAfter * 0.65) ? 'aging' : 'fresh'));

            return [
                'source' => $table,
                'scope' => $scope,
                'record_id' => isset($row['id']) ? (int) $row['id'] : null,
                'observed_at' => date(DATE_ATOM, $observedTimestamp ?: time()),
                'age_seconds' => $ageSeconds,
                'freshness_status' => $freshness,
                'confidence' => $freshness === 'stale' ? 0.65 : ($freshness === 'aging' ? 0.82 : 1.0),
            ];
        } catch (\Throwable $e) {
            return $empty + ['error' => 'source_version_unavailable'];
        }
    }

    private function journeyHasContext(array $journey): bool
    {
        if ($journey === [] || isset($journey['error'])) {
            return false;
        }
        if (!empty($journey['readiness']['ready']) || !empty($journey['completed_at'])) {
            return true;
        }
        foreach ((array) ($journey['stages'] ?? []) as $stage) {
            if (is_array($stage) && !empty(array_filter((array) ($stage['responses'] ?? []), static fn($value): bool => trim((string) $value) !== ''))) {
                return true;
            }
        }
        return false;
    }

    private function hasOperatingEvidence(array $operating, array $loop): bool
    {
        foreach ([
            (int) ($operating['pipeline_state']['open_deals'] ?? 0),
            (int) ($operating['task_state']['open_tasks'] ?? 0),
            (int) ($operating['target_state']['active_targets'] ?? 0),
            (int) ($operating['inbox_state']['open_threads'] ?? 0),
            (int) ($loop['first_customer_signal']['leads_created'] ?? 0),
            (int) ($loop['first_customer_signal']['paid_customer_count'] ?? 0),
        ] as $count) {
            if ($count > 0) {
                return true;
            }
        }
        return !empty($loop['active_week']);
    }

    private function hasAnyText(array $source, array $keys): bool
    {
        foreach ($keys as $key) {
            if (trim((string) ($source[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<mixed> $values
     */
    private function firstText(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    /**
     * @param list<array<string,string>> $items
     * @return list<array<string,string>>
     */
    private function dedupeMissing(array $items): array
    {
        $deduped = [];
        foreach ($items as $item) {
            $key = strtolower(trim((string) ($item['key'] ?? $item['label'] ?? '')));
            if ($key === '' || isset($deduped[$key])) {
                continue;
            }
            $deduped[$key] = $item;
        }
        return array_values($deduped);
    }

    /**
     * @param array<string,array<string,mixed>> $sourceVersions
     */
    private function hasCriticalStaleSource(array $sourceVersions): bool
    {
        foreach (['strategy_snapshot', 'startup_journey'] as $key) {
            if ((string) ($sourceVersions[$key]['freshness_status'] ?? '') === 'stale') {
                return true;
            }
        }
        return false;
    }
}
