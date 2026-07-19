<?php

namespace CRM\Services;

use CRM\Database;

class SystemContextRegistryService
{
    private const DEFAULT_WORKSPACE_REQUIRED_SETTINGS = [
        'workspace_purpose' => 'platform_ops',
        'internal_channel_ready' => true,
        'internal_team_ready' => true,
        'owner_helpline_enabled' => true,
    ];

    private const PLATFORM_OPS_CONTEXT = [
        'mission' => 'Operate Clarity platform success, support, onboarding, billing-risk, and tenant health workflows.',
        'primary_subjects' => [
            'tenant workspaces',
            'workspace owners',
            'trial users',
            'paying customers',
            'onboarding state',
            'billing/provider events',
            'channel health',
            'AI Credit balances',
            'operator audit history',
        ],
        'allowed_work' => [
            'summarize tenant/customer health',
            'draft owner follow-ups',
            'prioritize stuck onboarding workspaces',
            'identify billing or token risk',
            'recommend operational recovery actions',
            'explain platform setup and repair gaps',
        ],
        'disallowed_work' => [
            'treat default workspace as a normal customer CRM',
            'recommend cold outreach to arbitrary leads',
            'invent tenant business strategy without workspace evidence',
            'mirror or nurture default workspace owners as customer contacts',
        ],
        'evidence_sources' => [
            'default_workspace_owner_contacts',
            'contacts.metadata_json',
            'workspaces',
            'workspace_memberships',
            'billing/provider events',
            'onboarding state',
            'channel integrations',
            'operator audit actions',
        ],
        'tone' => 'calm, operational, specific, audit-aware, and customer-success oriented',
    ];

    private const DEFAULT_WORKSPACE_TEMPLATE_BASELINE = [
        'minimum_platform_ops_email_templates' => 8,
        'required_platform_ops_workflow_template_keys' => [
            'platform_ops_stuck_onboarding_review',
            'platform_ops_billing_risk_review',
            'platform_ops_security_review',
        ],
    ];

    /** @var array<int,string> */
    private const REQUIRED_CONTEXT_SECTIONS = [
        'product_key',
        'context_registry_version',
        'default_workspace',
        'default_workspace_templates',
        'automation_domain_defaults',
    ];

    /** @var array<int,string> */
    private const REQUIRED_PLATFORM_OPS_SECTIONS = [
        'mission',
        'primary_subjects',
        'allowed_work',
        'disallowed_work',
        'evidence_sources',
        'tone',
    ];

    /** @var array<int,string> */
    private const REQUIRED_OWNER_POLICY_SCOPES = [
        'qualified_workspace_lead',
        'current_paying_customer',
        'inactive_workspace_owner',
        'unqualified_demo_prospect',
    ];

    /** @var array<int,string> */
    private const DUMMY_CONTEXT_PATTERNS = [
        'example.test',
        'demo.local.invalid',
        'lorem ipsum',
        'acme',
        'test user',
        'demo company',
    ];

    private const BASE_AUTOMATION_DOMAIN_DEFAULTS = [
        'autonomy_mode' => 'suggest_only',
        'demonstration_capture_enabled' => true,
        'policy_learning_enabled' => true,
        'review_ui_enabled' => true,
        'fast_promotion_enabled' => true,
        'auto_downgrade_on_drift' => true,
        'promotion_status' => 'suggest_only',
        'min_precision_to_promote' => 0.9,
        'max_reversal_rate_to_promote' => 0.08,
        'max_edit_rate_to_promote' => 0.12,
        'metadata' => [
            'allowed_actions' => [],
            'max_daily_auto_actions' => 50,
            'max_customer_facing_risk' => 0.95,
            'require_human_checkpoint_actions' => [],
            'block_customer_facing_full_auto' => false,
            'min_sample_size_to_promote' => 10,
            'max_duplicate_rate_to_promote' => 0.05,
            'max_override_rate_to_promote' => 0.12,
            'min_eval_runs_to_promote' => 1,
            'manual_freeze' => false,
            'paused' => false,
            'pause_customer_facing_only' => false,
            'forced_safe_mode' => false,
            'temporary_daily_auto_action_cap' => null,
            'approval_required_for_promotion' => false,
            'latest_rollout_reason' => '',
        ],
    ];

    private const DOMAIN_AUTOMATION_DEFAULTS = [
        'customer_care' => [
            'autonomy_mode' => 'suggest_only',
            'promotion_status' => 'suggest_only',
            'metadata' => [
                'allowed_actions' => [
                    'suggest_check_in',
                    'create_check_in_task',
                    'schedule_check_in',
                    'enroll_follow_up_plan',
                    'record_check_in',
                    'draft_customer_reply',
                ],
                'require_human_checkpoint_actions' => ['draft_customer_reply'],
                'block_customer_facing_full_auto' => true,
                'max_daily_auto_actions' => 25,
                'min_sample_size_to_promote' => 20,
            ],
        ],
        'workflow_execution' => [
            'autonomy_mode' => 'auto_safe',
            'promotion_status' => 'auto_safe',
        ],
    ];

    private const OWNER_CONTACT_POLICIES = [
        'qualified_workspace_lead' => [
            'scope' => 'qualified_workspace_lead',
            'default_workspace_use' => 'lead_to_customer_conversion',
            'stage' => 'qualified',
            'lead_score' => 75,
            'customer_state' => 'qualified_workspace_lead',
            'default_workspace_nurture_qualified' => false,
            'current_paying_customer' => false,
            'marketing_conversion_allowed' => true,
        ],
        'current_paying_customer' => [
            'scope' => 'current_paying_customer',
            'default_workspace_use' => 'customer_success_nurture',
            'stage' => 'won',
            'lead_score' => 90,
            'customer_state' => 'current_paying_customer',
            'default_workspace_nurture_qualified' => true,
            'current_paying_customer' => true,
            'marketing_conversion_allowed' => false,
        ],
        'inactive_workspace_owner' => [
            'scope' => 'inactive_workspace_owner',
            'default_workspace_use' => 'inactive_owner_reference',
            'stage' => 'qualified',
            'lead_score' => 0,
            'customer_state' => 'ineligible',
            'default_workspace_nurture_qualified' => false,
            'current_paying_customer' => false,
            'marketing_conversion_allowed' => false,
        ],
        'unqualified_demo_prospect' => [
            'scope' => 'unqualified_demo_prospect',
            'default_workspace_use' => 'demo_email_follow_up',
            'stage' => 'new',
            'lead_score' => 0,
            'customer_state' => 'unqualified',
            'default_workspace_nurture_qualified' => false,
            'current_paying_customer' => false,
            'marketing_conversion_allowed' => true,
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public function systemContext(): array
    {
        return [
            'product_key' => 'clarity_crm',
            'context_registry_version' => '2026-07-04',
            'default_workspace' => $this->defaultWorkspaceContract(),
            'default_workspace_templates' => $this->defaultWorkspaceTemplateBaseline(),
            'automation_domain_defaults' => [
                'base' => $this->baseAutomationDomainDefaults(),
                'domains' => array_keys(self::DOMAIN_AUTOMATION_DEFAULTS),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultWorkspaceContract(): array
    {
        return [
            'workspace_id' => DefaultWorkspaceService::DEFAULT_ID,
            'slug' => DefaultWorkspaceService::DEFAULT_SLUG,
            'purpose' => 'platform_ops',
            'required_settings' => self::DEFAULT_WORKSPACE_REQUIRED_SETTINGS,
            'operating_mode' => $this->defaultWorkspaceOperatingMode(),
            'contact_scope_policy' => self::OWNER_CONTACT_POLICIES,
            'template_baseline' => self::DEFAULT_WORKSPACE_TEMPLATE_BASELINE,
        ];
    }

    /**
     * @param array<string,mixed>|null $workspace
     * @return array<string,mixed>
     */
    public function workspaceContext(int $workspaceId, ?array $workspace = null): array
    {
        $row = $workspace ?? $this->fetchWorkspace($workspaceId);
        if ($row === null) {
            return [
                'workspace_id' => $workspaceId,
                'exists' => false,
                'is_default_workspace' => false,
                'purpose' => 'unknown',
                'operating_mode' => ['mode' => 'unknown', 'is_default_workspace' => false],
            ];
        }

        $settings = $this->decodeJson($row['settings_json'] ?? null);
        $isDefaultWorkspace = (int) ($row['id'] ?? 0) === DefaultWorkspaceService::DEFAULT_ID
            && (string) ($row['slug'] ?? '') === DefaultWorkspaceService::DEFAULT_SLUG;
        $settingsValidation = $isDefaultWorkspace
            ? $this->validateDefaultWorkspaceSettings($settings)
            : ['ok' => true, 'missing' => [], 'mismatched' => []];

        return [
            'workspace_id' => (int) ($row['id'] ?? $workspaceId),
            'slug' => (string) ($row['slug'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'plan_status' => (string) ($row['plan_status'] ?? ''),
            'exists' => true,
            'is_default_workspace' => $isDefaultWorkspace,
            'purpose' => (string) ($settings['workspace_purpose'] ?? ($isDefaultWorkspace ? 'platform_ops' : 'tenant_workspace')),
            'settings' => $settings,
            'settings_validation' => $settingsValidation,
            'operating_mode' => $isDefaultWorkspace
                ? $this->defaultWorkspaceOperatingMode()
                : ['mode' => 'tenant_workspace', 'is_default_workspace' => false, 'canonical_workspace_id' => null],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultWorkspaceOperatingMode(): array
    {
        return [
            'mode' => 'platform_ops_hq',
            'is_default_workspace' => true,
            'canonical_workspace_id' => DefaultWorkspaceService::DEFAULT_ID,
            'purpose' => 'platform_ops',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function platformOpsContext(int $workspaceId = DefaultWorkspaceService::DEFAULT_ID): array
    {
        return self::PLATFORM_OPS_CONTEXT + [
            'workspace_id' => $workspaceId,
            'owner_contact_counts' => $this->platformOwnerContactCounts($workspaceId),
            'contact_scope_policy' => self::OWNER_CONTACT_POLICIES,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultWorkspaceTemplateBaseline(): array
    {
        return self::DEFAULT_WORKSPACE_TEMPLATE_BASELINE;
    }

    /**
     * @return array<string,mixed>
     */
    public function baseAutomationDomainDefaults(): array
    {
        return self::BASE_AUTOMATION_DOMAIN_DEFAULTS;
    }

    /**
     * @return array<string,mixed>
     */
    public function automationDomainDefaults(string $domainKey): array
    {
        return array_replace_recursive(
            self::BASE_AUTOMATION_DOMAIN_DEFAULTS,
            self::DOMAIN_AUTOMATION_DEFAULTS[$domainKey] ?? []
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function ownerContactPolicy(bool $isCurrentPayingCustomer): array
    {
        return $isCurrentPayingCustomer
            ? self::OWNER_CONTACT_POLICIES['current_paying_customer']
            : self::OWNER_CONTACT_POLICIES['qualified_workspace_lead'];
    }

    /**
     * @return array<string,mixed>
     */
    public function ownerContactPolicyForScope(string $scope): array
    {
        return self::OWNER_CONTACT_POLICIES[$scope] ?? self::OWNER_CONTACT_POLICIES['qualified_workspace_lead'];
    }

    /**
     * @return array<string,mixed>
     */
    public function inactiveOwnerContactPolicy(): array
    {
        return self::OWNER_CONTACT_POLICIES['inactive_workspace_owner'];
    }

    /**
     * @return array<string,mixed>
     */
    public function validateProductionContext(): array
    {
        $system = $this->systemContext();
        $platformOps = $this->platformOpsContext(DefaultWorkspaceService::DEFAULT_ID);
        $contract = $this->defaultWorkspaceContract();
        $templateBaseline = $this->defaultWorkspaceTemplateBaseline();
        $findings = [];
        $summary = [
            'required_sections_checked' => count(self::REQUIRED_CONTEXT_SECTIONS),
            'required_platform_ops_sections_checked' => count(self::REQUIRED_PLATFORM_OPS_SECTIONS),
            'required_owner_policy_scopes_checked' => count(self::REQUIRED_OWNER_POLICY_SCOPES),
            'missing_sections' => 0,
            'missing_platform_ops_sections' => 0,
            'missing_owner_policy_scopes' => 0,
            'missing_template_baseline' => 0,
            'default_workspace_settings_ok' => false,
            'dummy_language_hits' => 0,
            'findings' => 0,
            'critical' => 0,
            'warning' => 0,
            'by_rule' => [],
        ];

        $this->requireKeys($system, self::REQUIRED_CONTEXT_SECTIONS, 'system_context', 'context_section_missing', $findings, $summary['missing_sections']);
        $this->requireKeys($platformOps, self::REQUIRED_PLATFORM_OPS_SECTIONS, 'platform_ops_context', 'platform_ops_context_section_missing', $findings, $summary['missing_platform_ops_sections']);
        $this->validateOwnerPolicyScopes((array) ($contract['contact_scope_policy'] ?? []), $findings, $summary);
        $this->validateTemplateBaseline($templateBaseline, $findings, $summary);
        $this->validateDefaultWorkspaceRuntimeContext($findings, $summary);
        $this->validateConcreteDummyLanguage([$system, $platformOps], $findings, $summary);

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
     * @param array<string,mixed> $settings
     * @return array{ok:bool,missing:array<int,string>,mismatched:array<int,string>,expected:array<string,mixed>}
     */
    public function validateDefaultWorkspaceSettings(array $settings): array
    {
        $missing = [];
        $mismatched = [];
        foreach (self::DEFAULT_WORKSPACE_REQUIRED_SETTINGS as $key => $expected) {
            if (!array_key_exists($key, $settings)) {
                $missing[] = $key;
                continue;
            }
            if (!$this->settingMatches($settings[$key], $expected)) {
                $mismatched[] = $key;
            }
        }

        return [
            'ok' => $missing === [] && $mismatched === [],
            'missing' => $missing,
            'mismatched' => $mismatched,
            'expected' => self::DEFAULT_WORKSPACE_REQUIRED_SETTINGS,
        ];
    }

    /**
     * @param array<string,mixed> $values
     * @param array<int,string> $required
     * @param array<int,array<string,mixed>> $findings
     */
    private function requireKeys(array $values, array $required, string $target, string $rule, array &$findings, int &$missingCount): void
    {
        foreach ($required as $key) {
            $value = $values[$key] ?? null;
            $missing = $value === null
                || (is_string($value) && trim($value) === '')
                || (is_array($value) && $value === []);
            if (!$missing) {
                continue;
            }

            $missingCount++;
            $this->addFinding($findings, 'critical', $rule, 'Required production context section is missing or empty.', [
                'target' => $target . ':' . $key,
                'section' => $key,
                'recommendation' => 'Restore the required context registry section so AI, automation, templates, and System Health use stable production context.',
            ]);
        }
    }

    /**
     * @param array<string,mixed> $policies
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function validateOwnerPolicyScopes(array $policies, array &$findings, array &$summary): void
    {
        foreach (self::REQUIRED_OWNER_POLICY_SCOPES as $scope) {
            if (!isset($policies[$scope]) || !is_array($policies[$scope])) {
                $summary['missing_owner_policy_scopes']++;
                $this->addFinding($findings, 'critical', 'owner_policy_scope_missing', 'A required owner contact policy scope is missing.', [
                    'target' => 'contact_scope_policy:' . $scope,
                    'scope' => $scope,
                    'recommendation' => 'Restore the owner contact policy scope before owner onboarding, AI, or automation relies on default workspace context.',
                ]);
                continue;
            }

            foreach (['scope', 'default_workspace_use', 'customer_state'] as $field) {
                if (trim((string) ($policies[$scope][$field] ?? '')) !== '') {
                    continue;
                }
                $this->addFinding($findings, 'critical', 'owner_policy_field_missing', 'A required owner contact policy field is missing.', [
                    'target' => 'contact_scope_policy:' . $scope . ':' . $field,
                    'scope' => $scope,
                    'field' => $field,
                    'recommendation' => 'Complete the owner contact policy scope before production automation uses it.',
                ]);
            }
        }
    }

    /**
     * @param array<string,mixed> $templateBaseline
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function validateTemplateBaseline(array $templateBaseline, array &$findings, array &$summary): void
    {
        if ((int) ($templateBaseline['minimum_platform_ops_email_templates'] ?? 0) < 1) {
            $summary['missing_template_baseline']++;
            $this->addFinding($findings, 'critical', 'template_baseline_missing_email_minimum', 'Template baseline is missing the Platform Ops email template minimum.', [
                'target' => 'default_workspace_templates:minimum_platform_ops_email_templates',
                'recommendation' => 'Set a production minimum so template validation can detect missing Platform Ops templates.',
            ]);
        }

        if ((array) ($templateBaseline['required_platform_ops_workflow_template_keys'] ?? []) === []) {
            $summary['missing_template_baseline']++;
            $this->addFinding($findings, 'critical', 'template_baseline_missing_workflow_keys', 'Template baseline is missing required Platform Ops workflow template keys.', [
                'target' => 'default_workspace_templates:required_platform_ops_workflow_template_keys',
                'recommendation' => 'Restore required workflow template keys so preflight can detect missing production workflows.',
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function validateDefaultWorkspaceRuntimeContext(array &$findings, array &$summary): void
    {
        $context = $this->workspaceContext(DefaultWorkspaceService::DEFAULT_ID);
        if (empty($context['exists'])) {
            $this->addFinding($findings, 'critical', 'default_workspace_context_missing', 'Default workspace runtime context could not be loaded.', [
                'target' => 'default_workspace:' . DefaultWorkspaceService::DEFAULT_ID,
                'recommendation' => 'Run migrations and repair the canonical default workspace before production upload.',
            ]);
            return;
        }

        $settingsValidation = (array) ($context['settings_validation'] ?? []);
        $summary['default_workspace_settings_ok'] = (bool) ($settingsValidation['ok'] ?? false);
        if (empty($context['is_default_workspace']) || (string) ($context['purpose'] ?? '') !== 'platform_ops') {
            $this->addFinding($findings, 'critical', 'default_workspace_context_not_platform_ops', 'Default workspace runtime context is not Platform Ops.', [
                'target' => 'default_workspace:' . DefaultWorkspaceService::DEFAULT_ID,
                'purpose' => (string) ($context['purpose'] ?? ''),
                'recommendation' => 'Restore default workspace identity and purpose in the context registry/settings.',
            ]);
        }

        if (!$summary['default_workspace_settings_ok']) {
            $this->addFinding($findings, 'critical', 'default_workspace_required_context_settings_missing', 'Default workspace is missing required context settings.', [
                'target' => 'default_workspace:settings',
                'missing' => (array) ($settingsValidation['missing'] ?? []),
                'mismatched' => (array) ($settingsValidation['mismatched'] ?? []),
                'recommendation' => 'Repair workspace settings so context consumers see the production Platform Ops contract.',
            ]);
        }
    }

    /**
     * @param array<int,mixed> $contexts
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function validateConcreteDummyLanguage(array $contexts, array &$findings, array &$summary): void
    {
        $haystack = strtolower(implode("\n", $this->flattenStrings($contexts)));
        $hits = [];
        foreach (self::DUMMY_CONTEXT_PATTERNS as $pattern) {
            if (str_contains($haystack, strtolower($pattern))) {
                $hits[] = $pattern;
            }
        }

        $summary['dummy_language_hits'] = count($hits);
        if ($hits !== []) {
            $this->addFinding($findings, 'warning', 'context_registry_dummy_language', 'Concrete demo/test placeholder language appears in production context registry output.', [
                'target' => 'system_context_registry',
                'patterns' => $hits,
                'recommendation' => 'Replace concrete dummy values with company-neutral production context before live upload.',
            ]);
        }
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function flattenStrings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $nested) {
            array_push($strings, ...$this->flattenStrings($nested));
        }

        return $strings;
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
     * @return array<string,mixed>|null
     */
    private function fetchWorkspace(int $workspaceId): ?array
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspaces')) {
            return null;
        }

        try {
            return Database::queryOne(
                "SELECT id, slug, name, status, plan_status, settings_json
                 FROM workspaces
                 WHERE id = ?
                 LIMIT 1",
                [$workspaceId]
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string,int>
     */
    private function platformOwnerContactCounts(int $workspaceId): array
    {
        $counts = [
            'qualified_workspace_lead' => 0,
            'current_paying_customer' => 0,
            'inactive' => 0,
        ];

        if (!Database::tableExists('default_workspace_owner_contacts')) {
            return $counts;
        }

        try {
            $rows = Database::query(
                "SELECT customer_state, relationship_status, COUNT(*) AS c
                 FROM default_workspace_owner_contacts
                 WHERE default_workspace_id = ?
                 GROUP BY customer_state, relationship_status",
                [$workspaceId]
            );
        } catch (\Throwable $e) {
            return $counts;
        }

        foreach ($rows as $row) {
            $state = (string) ($row['customer_state'] ?? '');
            if ((string) ($row['relationship_status'] ?? '') === 'inactive') {
                $counts['inactive'] += (int) ($row['c'] ?? 0);
            } elseif (array_key_exists($state, $counts)) {
                $counts[$state] += (int) ($row['c'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * @return array<string,mixed>
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

    private function settingMatches(mixed $actual, mixed $expected): bool
    {
        if (is_bool($expected)) {
            return (bool) $actual === $expected;
        }
        return (string) $actual === (string) $expected;
    }
}
