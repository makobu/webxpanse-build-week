<?php

namespace CRM\Services;

use CRM\Database;
use PDO;

class DemoQuarantineVerificationService
{
    private const PROTECTED_DEMO_SLUG = 'protected-demo';
    private const PROTECTED_DEMO_UUID = '00000000-0000-4000-8000-000000000461';

    /** @var array<int,string> */
    private const EXPECTED_PROTECTED_DEMO_FLAGS = [
        'protected_demo_workspace',
        'demo_workspace',
        'billing_disabled',
        'invites_disabled',
        'exports_disabled',
        'real_integrations_disabled',
    ];

    /** @var array<int,string> */
    private const DEFAULT_WORKSPACE_DEMO_FLAGS = [
        'protected_demo_workspace',
        'demo_workspace',
        'presentation_workspace',
    ];

    /** @var array<int,string> */
    private const DEMO_MARKER_PATTERNS = [
        'metrodrive_demo_seed_v1',
        'protected_demo_experience',
        'protected_demo_showcase',
        'codex-verification-presentation-workspace',
        'codex verification',
        'presentation_workspace',
        'demo.local.invalid',
        'metrodrive-demo.example',
        'demo company',
        'test user',
        'lorem ipsum',
        'playwright',
    ];

    /** @var array<int,array{table:string,label:string,marker_columns:array<int,string>}> */
    private const SCOPED_TABLES = [
        ['table' => 'contacts', 'label' => 'Contacts', 'marker_columns' => ['email', 'first_name', 'last_name', 'company', 'lead_source', 'notes', 'metadata_json']],
        ['table' => 'communications', 'label' => 'Communications', 'marker_columns' => ['from_email', 'to_email', 'subject', 'message', 'content', 'body', 'source_surface', 'thread_key', 'metadata_json']],
        ['table' => 'conversation_threads', 'label' => 'Conversation Threads', 'marker_columns' => ['subject', 'thread_key', 'source_surface', 'metadata_json']],
        ['table' => 'notifications', 'label' => 'Notifications', 'marker_columns' => ['title', 'message', 'source_surface', 'metadata_json']],
        ['table' => 'emails', 'label' => 'Emails', 'marker_columns' => ['from_email', 'to_email', 'subject', 'body_html', 'body_text', 'metadata_json']],
        ['table' => 'email_queue', 'label' => 'Email Queue', 'marker_columns' => ['from_email', 'to_email', 'subject', 'body', 'payload_json', 'metadata_json']],
        ['table' => 'whatsapp_messages', 'label' => 'WhatsApp Messages', 'marker_columns' => ['from_number', 'to_number', 'message', 'body', 'metadata_json']],
        ['table' => 'whatsapp_queue', 'label' => 'WhatsApp Queue', 'marker_columns' => ['recipient_phone', 'message', 'payload_json', 'metadata_json']],
        ['table' => 'activities', 'label' => 'Activities', 'marker_columns' => ['title', 'description', 'notes', 'source_surface', 'metadata_json']],
        ['table' => 'tasks', 'label' => 'Tasks', 'marker_columns' => ['title', 'description', 'source_surface', 'metadata_json']],
        ['table' => 'deals', 'label' => 'Deals', 'marker_columns' => ['title', 'description', 'notes', 'custom_fields', 'metadata_json']],
    ];

    /** @var array<int,array{table:string,label:string,marker_columns:array<int,string>}> */
    private const MARKER_ONLY_TABLES = [
        ['table' => 'invoices', 'label' => 'Invoices', 'marker_columns' => ['invoice_number', 'notes', 'source_snapshot_json', 'metadata_json']],
        ['table' => 'reports', 'label' => 'Reports', 'marker_columns' => ['name', 'description', 'query_config', 'metadata_json']],
        ['table' => 'scheduled_reports', 'label' => 'Scheduled Reports', 'marker_columns' => ['schedule_name', 'schedule_config', 'metadata_json']],
    ];

    /** @var array<int,string> */
    private const PRESENTATION_TABLES = [
        'presentation_sessions',
        'presentation_access_tokens',
        'presentation_recipients',
        'presentation_delivery_audit',
        'presentation_seed_runs',
        'presentation_seed_entities',
    ];

    private SystemContextRegistryService $contextRegistry;

    public function __construct(
        private ?PDO $pdo = null,
        ?SystemContextRegistryService $contextRegistry = null
    ) {
        $this->contextRegistry = $contextRegistry ?? new SystemContextRegistryService();
    }

    /**
     * @return array<string,mixed>
     */
    public function verify(): array
    {
        $contract = $this->contextRegistry->defaultWorkspaceContract();
        $defaultWorkspaceId = (int) ($contract['workspace_id'] ?? DefaultWorkspaceService::DEFAULT_ID);
        $findings = [];
        $summary = [
            'default_workspace_id' => $defaultWorkspaceId,
            'default_workspace_slug' => '',
            'protected_demo_workspace_id' => null,
            'protected_demo_workspace_present' => false,
            'scoped_tables_checked' => 0,
            'scoped_tables_supported' => 0,
            'marker_tables_checked' => 0,
            'marker_tables_supported' => 0,
            'default_demo_scoped_rows' => 0,
            'protected_demo_scoped_rows' => 0,
            'non_demo_workspace_scoped_rows' => 0,
            'default_demo_marker_rows' => 0,
            'default_presentation_rows' => 0,
            'default_demo_session_rows' => 0,
            'default_demo_entity_rows' => 0,
            'default_demo_guest_memberships' => 0,
            'findings' => 0,
            'critical' => 0,
            'warning' => 0,
            'by_rule' => [],
            'table_counts' => [],
        ];

        $this->checkDefaultWorkspaceSettings($defaultWorkspaceId, $findings, $summary);
        $protectedWorkspace = $this->checkProtectedDemoWorkspace($findings, $summary);
        $protectedWorkspaceId = (int) ($protectedWorkspace['id'] ?? 0);

        $this->checkScopedTables($defaultWorkspaceId, $protectedWorkspaceId, $findings, $summary);
        $this->checkMarkerOnlyTables($defaultWorkspaceId, $findings, $summary);
        $this->checkPresentationTables($defaultWorkspaceId, $findings, $summary);
        $this->checkDemoSessionTables($defaultWorkspaceId, $protectedWorkspaceId, $findings, $summary);
        $this->checkDemoGuestAccess($defaultWorkspaceId, $findings, $summary);

        foreach ($findings as $finding) {
            $severity = (string) ($finding['severity'] ?? 'warning');
            if (!isset($summary[$severity])) {
                $severity = 'warning';
            }
            $summary[$severity]++;
            $summary['findings']++;
            $rule = (string) ($finding['rule'] ?? 'unknown');
            $summary['by_rule'][$rule] = (int) ($summary['by_rule'][$rule] ?? 0) + 1;
        }

        return [
            'status' => $summary['critical'] > 0 ? 'critical' : ($summary['warning'] > 0 ? 'warning' : 'ok'),
            'summary' => $summary,
            'findings' => $findings,
            'checked_at' => date('c'),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkDefaultWorkspaceSettings(int $defaultWorkspaceId, array &$findings, array &$summary): void
    {
        if (!$this->tableExists('workspaces')) {
            $this->addFinding($findings, 'critical', 'workspaces_table_missing', 'The workspaces table is missing, so demo quarantine cannot be verified.', [
                'target' => 'workspaces',
                'recommendation' => 'Run migrations before verifying production workspace boundaries.',
            ]);
            return;
        }

        $workspace = $this->queryOne('SELECT id, slug, name, settings_json FROM workspaces WHERE id = ? LIMIT 1', [$defaultWorkspaceId]);
        if ($workspace === null) {
            $this->addFinding($findings, 'critical', 'default_workspace_missing', 'The default production workspace is missing.', [
                'workspace_id' => $defaultWorkspaceId,
                'target' => 'workspaces',
                'recommendation' => 'Repair the canonical default workspace before live upload.',
            ]);
            return;
        }

        $summary['default_workspace_slug'] = (string) ($workspace['slug'] ?? '');
        $settings = $this->decodeJson((string) ($workspace['settings_json'] ?? ''));
        $demoFlags = [];
        foreach (self::DEFAULT_WORKSPACE_DEMO_FLAGS as $flag) {
            if (!empty($settings[$flag])) {
                $demoFlags[] = $flag;
            }
        }

        if ($demoFlags !== []) {
            $this->addFinding($findings, 'critical', 'default_workspace_demo_mode_flagged', 'The default production workspace is marked with demo or presentation flags.', [
                'workspace_id' => $defaultWorkspaceId,
                'flags' => $demoFlags,
                'target' => 'workspaces:' . $defaultWorkspaceId,
                'recommendation' => 'Remove demo/presentation flags from the default workspace and keep demo modes isolated in protected demo or presentation workspaces.',
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     * @return array<string,mixed>|null
     */
    private function checkProtectedDemoWorkspace(array &$findings, array &$summary): ?array
    {
        if (!$this->tableExists('workspaces')) {
            return null;
        }

        $rows = $this->query(
            'SELECT id, uuid, slug, status, plan_status, settings_json
             FROM workspaces
             WHERE slug = ? OR uuid = ?
             ORDER BY CASE WHEN slug = ? THEN 0 ELSE 1 END, id ASC',
            [self::PROTECTED_DEMO_SLUG, self::PROTECTED_DEMO_UUID, self::PROTECTED_DEMO_SLUG]
        );

        if ($rows === []) {
            $this->addFinding($findings, 'critical', 'protected_demo_workspace_missing', 'The protected demo workspace is missing.', [
                'slug' => self::PROTECTED_DEMO_SLUG,
                'uuid' => self::PROTECTED_DEMO_UUID,
                'target' => 'workspaces:' . self::PROTECTED_DEMO_SLUG,
                'recommendation' => 'Run the protected demo workspace repair migration before enabling demo access.',
            ]);
            return null;
        }

        $workspace = $rows[0];
        $workspaceId = (int) ($workspace['id'] ?? 0);
        $summary['protected_demo_workspace_id'] = $workspaceId;
        $summary['protected_demo_workspace_present'] = true;

        if (count($rows) > 1) {
            $this->addFinding($findings, 'warning', 'protected_demo_workspace_duplicate_identity', 'More than one workspace matches the protected demo slug or UUID.', [
                'matching_workspace_ids' => array_values(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows)),
                'target' => 'workspaces:' . self::PROTECTED_DEMO_SLUG,
                'recommendation' => 'Consolidate duplicate protected-demo identities so sessions and seed data resolve to one workspace.',
            ]);
        }

        if ((string) ($workspace['status'] ?? '') !== 'active') {
            $this->addFinding($findings, 'warning', 'protected_demo_workspace_not_active', 'The protected demo workspace is not active.', [
                'workspace_id' => $workspaceId,
                'status' => (string) ($workspace['status'] ?? ''),
                'target' => 'workspaces:' . $workspaceId,
                'recommendation' => 'Reactivate the protected demo workspace or disable demo entry points before live upload.',
            ]);
        }

        $settings = $this->decodeJson((string) ($workspace['settings_json'] ?? ''));
        $missingFlags = [];
        foreach (self::EXPECTED_PROTECTED_DEMO_FLAGS as $flag) {
            if (empty($settings[$flag])) {
                $missingFlags[] = $flag;
            }
        }

        if ($missingFlags !== []) {
            $this->addFinding($findings, 'critical', 'protected_demo_workspace_guard_flags_missing', 'The protected demo workspace is missing one or more isolation guard flags.', [
                'workspace_id' => $workspaceId,
                'missing_flags' => $missingFlags,
                'expected_flags' => self::EXPECTED_PROTECTED_DEMO_FLAGS,
                'target' => 'workspaces:' . $workspaceId,
                'recommendation' => 'Restore protected demo settings for disabled billing, invites, exports, and real integrations.',
            ]);
        }

        if ($this->tableExists('workspace_slugs') && $this->columnExists('workspace_slugs', 'workspace_id') && $this->columnExists('workspace_slugs', 'slug')) {
            $alias = $this->queryOne(
                'SELECT workspace_id, is_primary FROM workspace_slugs WHERE slug = ? LIMIT 1',
                [self::PROTECTED_DEMO_SLUG]
            );
            if ($alias === null || (int) ($alias['workspace_id'] ?? 0) !== $workspaceId) {
                $this->addFinding($findings, 'warning', 'protected_demo_workspace_slug_alias_missing', 'The protected-demo slug alias does not point to the protected demo workspace.', [
                    'workspace_id' => $workspaceId,
                    'target' => 'workspace_slugs:' . self::PROTECTED_DEMO_SLUG,
                    'recommendation' => 'Repair the workspace_slugs alias so protected demo routing resolves consistently.',
                ]);
            }
        }

        return $workspace;
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkScopedTables(int $defaultWorkspaceId, int $protectedWorkspaceId, array &$findings, array &$summary): void
    {
        foreach (self::SCOPED_TABLES as $definition) {
            $table = $definition['table'];
            $summary['scoped_tables_checked']++;
            if (!$this->tableExists($table) || !$this->columnExists($table, 'workspace_id')) {
                continue;
            }

            $scopeCondition = $this->demoScopeCondition($table);
            $markerCondition = $this->markerCondition($table, $definition['marker_columns']);
            $tableCounts = [
                'default_demo_scope' => 0,
                'protected_demo_scope' => 0,
                'non_demo_workspace_scope' => 0,
                'default_demo_markers' => 0,
            ];

            if ($scopeCondition !== null) {
                $summary['scoped_tables_supported']++;
                $defaultCount = $this->fetchCount(
                    'SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table) . ' WHERE workspace_id = ? AND (' . $scopeCondition . ')',
                    [$defaultWorkspaceId]
                );
                $tableCounts['default_demo_scope'] = $defaultCount;
                $summary['default_demo_scoped_rows'] += $defaultCount;
                if ($defaultCount > 0) {
                    $this->addFinding($findings, 'critical', 'default_workspace_demo_scoped_rows', 'Demo-scoped rows exist in the default production workspace.', [
                        'table' => $table,
                        'workspace_id' => $defaultWorkspaceId,
                        'count' => $defaultCount,
                        'target' => $table . ':workspace:' . $defaultWorkspaceId,
                        'recommendation' => 'Move or remove demo-scoped rows from workspace 1 after review; they should stay in protected demo or presentation workspaces.',
                    ]);
                }

                if ($protectedWorkspaceId > 0) {
                    $protectedCount = $this->fetchCount(
                        'SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table) . ' WHERE workspace_id = ? AND (' . $scopeCondition . ')',
                        [$protectedWorkspaceId]
                    );
                    $tableCounts['protected_demo_scope'] = $protectedCount;
                    $summary['protected_demo_scoped_rows'] += $protectedCount;
                }

                $nonDemoCount = $this->countScopedRowsInNonDemoWorkspaces($table, $defaultWorkspaceId, $protectedWorkspaceId, $scopeCondition);
                $tableCounts['non_demo_workspace_scope'] = $nonDemoCount;
                $summary['non_demo_workspace_scoped_rows'] += $nonDemoCount;
                if ($nonDemoCount > 0) {
                    $this->addFinding($findings, 'warning', 'tenant_workspace_demo_scoped_rows', 'Demo-scoped rows exist in workspaces that are not marked demo or presentation.', [
                        'table' => $table,
                        'count' => $nonDemoCount,
                        'target' => $table,
                        'recommendation' => 'Review these tenant-workspace rows and either quarantine them in demo workspaces or remove stale demo scope markers.',
                    ]);
                }
            }

            if ($markerCondition !== null) {
                [$markerSql, $params] = $markerCondition;
                $params[] = $defaultWorkspaceId;
                $allowlist = $this->defaultWorkspaceMarkerAllowlistSql($table);
                $markerCount = $this->fetchCount(
                    'SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table) . ' WHERE (' . $markerSql . ') AND workspace_id = ?' . $allowlist,
                    $params
                );
                $tableCounts['default_demo_markers'] = $markerCount;
                $summary['default_demo_marker_rows'] += $markerCount;
                if ($markerCount > 0) {
                    $this->addFinding($findings, 'critical', 'default_workspace_demo_marker_rows', 'Rows with concrete demo seed markers exist in the default production workspace.', [
                        'table' => $table,
                        'workspace_id' => $defaultWorkspaceId,
                        'count' => $markerCount,
                        'target' => $table . ':workspace:' . $defaultWorkspaceId,
                        'recommendation' => 'Review and quarantine or remove seeded/demo records from the default workspace before live upload.',
                    ]);
                }
            }

            if ($tableCounts !== [
                'default_demo_scope' => 0,
                'protected_demo_scope' => 0,
                'non_demo_workspace_scope' => 0,
                'default_demo_markers' => 0,
            ]) {
                $summary['table_counts'][$table] = $tableCounts;
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkMarkerOnlyTables(int $defaultWorkspaceId, array &$findings, array &$summary): void
    {
        foreach (self::MARKER_ONLY_TABLES as $definition) {
            $table = $definition['table'];
            $summary['marker_tables_checked']++;
            if (!$this->tableExists($table) || !$this->columnExists($table, 'workspace_id')) {
                continue;
            }

            $markerCondition = $this->markerCondition($table, $definition['marker_columns']);
            if ($markerCondition === null) {
                continue;
            }

            $summary['marker_tables_supported']++;
            [$markerSql, $params] = $markerCondition;
            $params[] = $defaultWorkspaceId;
            $markerCount = $this->fetchCount(
                'SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table) . ' WHERE (' . $markerSql . ') AND workspace_id = ?',
                $params
            );
            $summary['default_demo_marker_rows'] += $markerCount;

            if ($markerCount > 0) {
                $this->addFinding($findings, 'critical', 'default_workspace_demo_marker_rows', 'Rows with concrete demo seed markers exist in the default production workspace.', [
                    'table' => $table,
                    'workspace_id' => $defaultWorkspaceId,
                    'count' => $markerCount,
                    'target' => $table . ':workspace:' . $defaultWorkspaceId,
                    'recommendation' => 'Review and quarantine or remove seeded/demo records from the default workspace before live upload.',
                ]);
            }

            if ($markerCount > 0) {
                $summary['table_counts'][$table] = ['default_demo_markers' => $markerCount];
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkPresentationTables(int $defaultWorkspaceId, array &$findings, array &$summary): void
    {
        foreach (self::PRESENTATION_TABLES as $table) {
            if (!$this->tableExists($table) || !$this->columnExists($table, 'workspace_id')) {
                continue;
            }

            $count = $this->fetchCount(
                'SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table) . ' WHERE workspace_id = ?',
                [$defaultWorkspaceId]
            );
            $summary['default_presentation_rows'] += $count;
            if ($count > 0) {
                $this->addFinding($findings, 'critical', 'default_workspace_presentation_rows', 'Presentation workspace rows exist in the default production workspace.', [
                    'table' => $table,
                    'workspace_id' => $defaultWorkspaceId,
                    'count' => $count,
                    'target' => $table . ':workspace:' . $defaultWorkspaceId,
                    'recommendation' => 'Move or archive presentation records outside the default workspace before live upload.',
                ]);
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkDemoSessionTables(int $defaultWorkspaceId, int $protectedWorkspaceId, array &$findings, array &$summary): void
    {
        if ($this->tableExists('demo_visitor_sessions') && $this->columnExists('demo_visitor_sessions', 'workspace_id')) {
            $defaultSessions = $this->fetchCount(
                'SELECT COUNT(*) FROM demo_visitor_sessions WHERE workspace_id = ?',
                [$defaultWorkspaceId]
            );
            $summary['default_demo_session_rows'] = $defaultSessions;
            if ($defaultSessions > 0) {
                $this->addFinding($findings, 'critical', 'default_workspace_demo_sessions', 'Demo visitor sessions are attached to the default production workspace.', [
                    'workspace_id' => $defaultWorkspaceId,
                    'count' => $defaultSessions,
                    'target' => 'demo_visitor_sessions:workspace:' . $defaultWorkspaceId,
                    'recommendation' => 'Demo visitor sessions should resolve to the protected demo workspace; repair session routing before live upload.',
                ]);
            }

            if ($protectedWorkspaceId > 0 && $this->tableExists('workspaces')) {
                $params = [$defaultWorkspaceId, $protectedWorkspaceId];
                $nonDemoSessions = $this->fetchCount(
                    "SELECT COUNT(*)
                     FROM demo_visitor_sessions s
                     LEFT JOIN workspaces w ON w.id = s.workspace_id
                     WHERE s.workspace_id NOT IN (?, ?)
                       AND LOWER(COALESCE(w.settings_json, '')) NOT LIKE '%demo_workspace%'
                       AND LOWER(COALESCE(w.settings_json, '')) NOT LIKE '%presentation_workspace%'",
                    $params
                );
                if ($nonDemoSessions > 0) {
                    $this->addFinding($findings, 'warning', 'demo_sessions_in_non_demo_workspaces', 'Demo visitor sessions exist in workspaces not marked as demo or presentation workspaces.', [
                        'count' => $nonDemoSessions,
                        'target' => 'demo_visitor_sessions',
                        'recommendation' => 'Review legacy demo session routing and quarantine sessions into protected demo workspaces.',
                    ]);
                }
            }
        }

        if ($this->tableExists('demo_session_entities') && $this->columnExists('demo_session_entities', 'workspace_id')) {
            $defaultEntities = $this->fetchCount(
                'SELECT COUNT(*) FROM demo_session_entities WHERE workspace_id = ?',
                [$defaultWorkspaceId]
            );
            $summary['default_demo_entity_rows'] = $defaultEntities;
            if ($defaultEntities > 0) {
                $this->addFinding($findings, 'critical', 'default_workspace_demo_session_entities', 'Demo session entity mappings point at the default production workspace.', [
                    'workspace_id' => $defaultWorkspaceId,
                    'count' => $defaultEntities,
                    'target' => 'demo_session_entities:workspace:' . $defaultWorkspaceId,
                    'recommendation' => 'Repair demo entity registration so private demo overlays stay inside protected demo workspaces.',
                ]);
            }

            if ($this->tableExists('demo_visitor_sessions') && $this->columnExists('demo_session_entities', 'demo_session_id')) {
                $mismatches = $this->fetchCount(
                    'SELECT COUNT(*)
                     FROM demo_session_entities e
                     JOIN demo_visitor_sessions s ON s.id = e.demo_session_id
                     WHERE e.workspace_id <> s.workspace_id'
                );
                if ($mismatches > 0) {
                    $this->addFinding($findings, 'warning', 'demo_session_entity_workspace_mismatch', 'Demo session entity mappings do not match their session workspace.', [
                        'count' => $mismatches,
                        'target' => 'demo_session_entities',
                        'recommendation' => 'Review mismatched demo entity registrations before enabling protected demo traffic.',
                    ]);
                }
            }
        }

        if ($this->tableExists('demo_realtime_events') && $this->columnExists('demo_realtime_events', 'workspace_id')) {
            $defaultEvents = $this->fetchCount(
                'SELECT COUNT(*) FROM demo_realtime_events WHERE workspace_id = ?',
                [$defaultWorkspaceId]
            );
            if ($defaultEvents > 0) {
                $this->addFinding($findings, 'critical', 'default_workspace_demo_realtime_events', 'Demo realtime events are attached to the default production workspace.', [
                    'workspace_id' => $defaultWorkspaceId,
                    'count' => $defaultEvents,
                    'target' => 'demo_realtime_events:workspace:' . $defaultWorkspaceId,
                    'recommendation' => 'Keep demo realtime events in protected demo sessions only.',
                ]);
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkDemoGuestAccess(int $defaultWorkspaceId, array &$findings, array &$summary): void
    {
        if (!$this->tableExists('users') || !$this->columnExists('users', 'is_demo_guest')) {
            return;
        }

        if ($this->tableExists('workspace_memberships')
            && $this->columnExists('workspace_memberships', 'workspace_id')
            && $this->columnExists('workspace_memberships', 'user_id')) {
            $conditions = ['wm.workspace_id = ?', 'u.is_demo_guest = 1'];
            if ($this->columnExists('workspace_memberships', 'membership_status')) {
                $conditions[] = "wm.membership_status = 'active'";
            }
            $count = $this->fetchCount(
                'SELECT COUNT(*)
                 FROM workspace_memberships wm
                 JOIN users u ON u.id = wm.user_id
                 WHERE ' . implode(' AND ', $conditions),
                [$defaultWorkspaceId]
            );
            $summary['default_demo_guest_memberships'] += $count;
            if ($count > 0) {
                $this->addFinding($findings, 'critical', 'default_workspace_demo_guest_memberships', 'Demo guest users have active default workspace memberships.', [
                    'workspace_id' => $defaultWorkspaceId,
                    'count' => $count,
                    'target' => 'workspace_memberships:workspace:' . $defaultWorkspaceId,
                    'recommendation' => 'Remove demo guest access from the production default workspace and keep guest membership scoped to the protected demo workspace.',
                ]);
            }
        }

        if ($this->tableExists('user_roles')
            && $this->columnExists('user_roles', 'workspace_id')
            && $this->columnExists('user_roles', 'user_id')) {
            $conditions = ['ur.workspace_id = ?', 'u.is_demo_guest = 1'];
            if ($this->columnExists('user_roles', 'is_active')) {
                $conditions[] = 'ur.is_active = 1';
            }
            $count = $this->fetchCount(
                'SELECT COUNT(*)
                 FROM user_roles ur
                 JOIN users u ON u.id = ur.user_id
                 WHERE ' . implode(' AND ', $conditions),
                [$defaultWorkspaceId]
            );
            $summary['default_demo_guest_memberships'] += $count;
            if ($count > 0) {
                $this->addFinding($findings, 'critical', 'default_workspace_demo_guest_roles', 'Demo guest users have default workspace role assignments.', [
                    'workspace_id' => $defaultWorkspaceId,
                    'count' => $count,
                    'target' => 'user_roles:workspace:' . $defaultWorkspaceId,
                    'recommendation' => 'Remove default workspace roles from demo guests before live upload.',
                ]);
            }
        }
    }

    private function demoScopeCondition(string $table): ?string
    {
        $conditions = [];
        if ($this->columnExists($table, 'demo_visibility')) {
            $conditions[] = "COALESCE(CAST(demo_visibility AS CHAR), '') <> ''";
        }
        if ($this->columnExists($table, 'demo_session_id')) {
            $conditions[] = 'demo_session_id IS NOT NULL';
        }

        return $conditions !== [] ? implode(' OR ', $conditions) : null;
    }

    /**
     * @param array<int,string> $columns
     * @return array{0:string,1:array<int,mixed>}|null
     */
    private function markerCondition(string $table, array $columns): ?array
    {
        $conditions = [];
        $params = [];
        foreach ($columns as $column) {
            if (!$this->columnExists($table, $column)) {
                continue;
            }
            foreach (self::DEMO_MARKER_PATTERNS as $pattern) {
                $conditions[] = 'LOWER(COALESCE(CAST(' . $this->quoteIdentifier($column) . " AS CHAR), '')) LIKE ?";
                $params[] = '%' . strtolower($pattern) . '%';
            }
        }

        if ($conditions === []) {
            return null;
        }

        return [implode(' OR ', $conditions), $params];
    }

    private function countScopedRowsInNonDemoWorkspaces(string $table, int $defaultWorkspaceId, int $protectedWorkspaceId, string $scopeCondition): int
    {
        if (!$this->tableExists('workspaces')) {
            return 0;
        }

        $params = [$defaultWorkspaceId];
        $excluded = ['?'];
        if ($protectedWorkspaceId > 0) {
            $params[] = $protectedWorkspaceId;
            $excluded[] = '?';
        }

        return $this->fetchCount(
            'SELECT COUNT(*)
             FROM ' . $this->quoteIdentifier($table) . ' t
             LEFT JOIN workspaces w ON w.id = t.workspace_id
             WHERE t.workspace_id NOT IN (' . implode(', ', $excluded) . ')
               AND (' . str_replace(['demo_visibility', 'demo_session_id'], ['t.demo_visibility', 't.demo_session_id'], $scopeCondition) . ")
               AND LOWER(COALESCE(w.settings_json, '')) NOT LIKE '%demo_workspace%'
               AND LOWER(COALESCE(w.settings_json, '')) NOT LIKE '%presentation_workspace%'",
            $params
        );
    }

    private function defaultWorkspaceMarkerAllowlistSql(string $table): string
    {
        if ($table !== 'contacts' || !$this->columnExists('contacts', 'metadata_json')) {
            return '';
        }

        return " AND LOWER(COALESCE(CAST(metadata_json AS CHAR), '')) NOT LIKE '%default_workspace_demo_visitor%'";
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $context
     */
    private function addFinding(array &$findings, string $severity, string $rule, string $message, array $context): void
    {
        $findings[] = [
            'severity' => $severity,
            'rule' => $rule,
            'message' => $message,
        ] + $context;
    }

    /**
     * @param array<int,mixed> $params
     */
    private function fetchCount(string $sql, array $params = []): int
    {
        $row = $this->queryOne($sql, $params);
        if ($row === null) {
            return 0;
        }
        $values = array_values($row);
        return (int) ($values[0] ?? 0);
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function query(string $sql, array $params = []): array
    {
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return Database::query($sql, $params);
    }

    /**
     * @param array<int,mixed> $params
     * @return array<string,mixed>|null
     */
    private function queryOne(string $sql, array $params = []): ?array
    {
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }
        return Database::queryOne($sql, $params);
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->query('SHOW TABLES LIKE ' . $this->pdo->quote($table));
            return (bool) ($stmt && $stmt->fetch(PDO::FETCH_NUM));
        }
        return Database::tableExists($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table) . ' WHERE Field = ' . $this->pdo->quote($column));
            return (bool) ($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
        }
        return Database::columnExists($table, $column);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
