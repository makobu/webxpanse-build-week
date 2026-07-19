<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\CompanyProfile;
use CRM\Modules\DealAutomationConfig;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\UserStrategyProfile;

class DefaultWorkspaceOperationalizationService
{
    private const SOURCE = 'default_workspace_platform_ops';
    private const AI_SOURCE = 'default_workspace_platform_ops_ai';
    private const SEED_VERSION = '1.0.0';
    private const AI_SEED_VERSION = '1.0.0';
    private const REQUIRED_STEPS = ['start', 'channels', 'voice', 'offer', 'vision', 'money', 'autopilot', 'launch'];
    private OperatorAuditService $audit;
    private DefaultWorkspaceService $defaultWorkspace;

    public function __construct(?OperatorAuditService $audit = null, ?DefaultWorkspaceService $defaultWorkspace = null)
    {
        $this->audit = $audit ?: new OperatorAuditService();
        $this->defaultWorkspace = $defaultWorkspace ?: new DefaultWorkspaceService();
    }

    public function resolveDefaultWorkspace(?int $workspaceId = null): array
    {
        $workspace = $workspaceId !== null
            ? Database::queryOne("SELECT * FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId])
            : $this->defaultWorkspace->resolve();
        if (!$workspace) {
            throw new \RuntimeException('Default workspace was not found.');
        }
        $this->defaultWorkspace->assertDefaultWorkspace((int) ($workspace['id'] ?? 0));
        return $workspace;
    }

    public function status(int $actorUserId): array
    {
        $workspace = $this->resolveDefaultWorkspace();
        $settings = $this->decodeJson($workspace['settings_json'] ?? null);
        $ownerUserId = $this->resolveOwnerUserId((int) $workspace['id'], $actorUserId);
        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace((int) $workspace['id'], $ownerUserId, 'superadmin');
        try {
            $impact = (new WorkspaceOnboardingService())->getDashboardSetupImpact((int) $workspace['id'], $ownerUserId);
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }

        return [
            'workspace' => $workspace,
            'settings' => $settings,
            'operationalized' => ($settings['workspace_purpose'] ?? '') === 'platform_ops',
            'operationalized_at' => $settings['operationalized_at'] ?? null,
            'operational_score' => (int) ($impact['score'] ?? 0),
            'headline' => (string) ($impact['headline'] ?? ''),
            'identity_health' => $this->defaultWorkspace->health(),
            'diagnostics' => $this->diagnosticsForWorkspace((int) $workspace['id'], $ownerUserId, $settings, (int) ($impact['score'] ?? 0)),
        ];
    }

    public function diagnose(int $actorUserId): array
    {
        $actor = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$actorUserId]) ?: [];
        if (!Authorization::isSuperAdmin($actor)) {
            throw new \RuntimeException('Only Super Admin can diagnose the default Platform Ops workspace.');
        }

        $workspace = $this->resolveDefaultWorkspace();
        $settings = $this->decodeJson($workspace['settings_json'] ?? null);
        $ownerUserId = $this->resolveOwnerUserId((int) $workspace['id'], $actorUserId);
        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace((int) $workspace['id'], $ownerUserId, 'superadmin');
        try {
            $impact = (new WorkspaceOnboardingService())->getDashboardSetupImpact((int) $workspace['id'], $ownerUserId);
            return $this->diagnosticsForWorkspace((int) $workspace['id'], $ownerUserId, $settings, (int) ($impact['score'] ?? 0));
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }
    }

    public function operationalize(int $actorUserId, ?int $workspaceId = null): array
    {
        $actor = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$actorUserId]) ?: [];
        if (!Authorization::isSuperAdmin($actor)) {
            throw new \RuntimeException('Only Super Admin can operationalize the default workspace.');
        }

        try {
            $workspace = $this->resolveDefaultWorkspace($workspaceId);
        } catch (\Throwable $e) {
            $this->audit->log('default_workspace_operationalization_failed', $actorUserId, $workspaceId, $e->getMessage(), [
                'source' => self::SOURCE,
                'requested_workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        $workspaceId = (int) $workspace['id'];
        $ownerUserId = $this->resolveOwnerUserId($workspaceId, $actorUserId);
        $this->audit->log('default_workspace_operationalization_started', $actorUserId, $workspaceId, 'Run/repair Platform Ops default workspace setup.', [
            'source' => self::SOURCE,
        ], $ownerUserId);
        $this->ensureMembership($workspaceId, $ownerUserId);

        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $ownerUserId, 'superadmin');
        $pdo = Database::getInstance();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            Database::beginTransaction();
        }

        try {
            $counts = [
                'capabilities' => $this->registerCapabilities(),
                'email_templates' => $this->seedEmailTemplates($ownerUserId),
                'workflow_templates' => $this->seedWorkflowTemplates($ownerUserId),
                'tasks' => $this->seedTasks($workspaceId, $ownerUserId),
                'ai_prompts' => $this->seedDefaultWorkspaceAiPrompts($workspaceId),
            ];
            $this->seedCompanyProfile();
            $this->seedStrategyProfile($ownerUserId);
            $this->seedInvoiceSettings($ownerUserId);
            $this->seedAutomationSettings();
            $this->markWorkspaceSettings($workspaceId);
            $this->completeOnboardingState($workspaceId, $ownerUserId);

            if ($startedTransaction) {
                Database::commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                Database::rollBack();
            }
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
            $this->audit->log('default_workspace_operationalization_failed', $actorUserId, $workspaceId, $e->getMessage(), [
                'source' => self::SOURCE,
                'error' => $e->getMessage(),
            ], $ownerUserId);
            throw $e;
        }

        try {
            (new WorkspaceOperatingBriefService())->generate($workspaceId, $ownerUserId);
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }

        $status = $this->status($ownerUserId);
        $flatCounts = [
            'products_upserted' => 0,
            'capabilities_registered' => (int) ($counts['capabilities']['registered'] ?? 0),
            'email_templates_upserted' => $this->upsertedCount($counts['email_templates']),
            'workflow_templates_upserted' => $this->upsertedCount($counts['workflow_templates']),
            'tasks_upserted' => $this->upsertedCount($counts['tasks']),
            'ai_prompts_upserted' => $this->upsertedCount($counts['ai_prompts']),
        ];
        $result = array_merge($status, $flatCounts, [
            'artifact_counts' => $counts,
            'owner_user_id' => $ownerUserId,
            'profile_fields_updated' => 15,
            'onboarding_artifacts_updated' => 4,
        ]);
        $this->audit->log('default_workspace_operationalization_completed', $actorUserId, $workspaceId, 'Default workspace operationalized as Platform Ops HQ.', [
            'source' => self::SOURCE,
            'operational_score' => (int) ($result['operational_score'] ?? 0),
            'artifact_counts' => $counts,
        ], $ownerUserId);

        return $result;
    }

    private function seedCompanyProfile(): void
    {
        (new CompanyProfile())->update([
            'company_name' => 'Clarity Platform Operations HQ',
            'company_legal_name' => 'Clarity Platform Operations HQ',
            'company_tax_id' => 'PLATFORM-OPS',
            'company_tagline' => 'Run the platform with billing, onboarding, safety, and support in one operating workspace.',
            'company_description' => 'Internal Super Admin workspace for nurturing existing customer and trial workspace owners through onboarding, billing, setup, support, channel readiness, token operations, security settings, and operator audits.',
            'company_mission' => 'Keep every customer and trial workspace healthy, billable, supported, secure, ready for customer-facing work, and easy for workspace owners to get help from the platform team.',
            'company_values' => "Operational clarity\nAuditability\nTenant safety\nFast recovery\nMeasured automation",
            'owner_company_context' => 'This workspace receives existing customers and trial users only. Those workspace owners appear here as CRM contacts and automatically qualify for nurturing: support, welcome emails, nudges, billing follow-up, and reported-problem management. It is not used for marketing to convert new customers, cold outreach, or tenant promotion.',
            'company_website' => rtrim((string) ($_ENV['APP_URL'] ?? 'http://localhost'), '/') . publicUrl('workspaces.php'),
            'company_email' => 'platform-ops@example.com',
            'company_phone' => '+254700000000',
            'company_address' => 'Platform Operations',
            'company_location' => 'Nairobi, Kenya',
            'company_timezone' => 'Africa/Nairobi',
            'company_industry' => 'CRM SaaS platform operations',
            'company_size' => 'Platform admin team',
            'icp_job_titles' => 'Workspace Owner, Super Admin, Platform Operator, Support Lead, Billing Operator',
            'icp_industries' => 'Tenant workspace owners, internal SaaS operations, tenant success, billing operations',
            'icp_pain_points' => 'Stuck onboarding, failed payments, low AI Credit balances, channel setup issues, tenant recovery, security reviews',
            'icp_channels' => 'Default workspace contacts, Workspace directory, Settings, dashboard, in-app notifications, email, WhatsApp drafts',
        ]);
    }

    private function registerCapabilities(): array
    {
        $catalog = new DefaultWorkspaceCapabilityCatalogService();
        return [
            'registered' => count($catalog->all()),
            'warnings' => $catalog->validationErrors(),
        ];
    }

    private function seedStrategyProfile(int $ownerUserId): void
    {
        (new UserStrategyProfile())->save($ownerUserId, [
            'target_market_focus' => 'Existing customer and trial workspace owner nurturing, support, and tenant lifecycle management',
            'ideal_customer_profile' => 'Existing customer and trial workspace owners who need setup help, billing clarity, support follow-up, and reliable recovery from the Super Admin platform team.',
            'offer_angle' => 'A single nurture workspace that turns eligible customer and trial owners into owner contacts, repeatable services, tasks, templates, and operating briefs.',
            'segment_focus' => 'Tenant health, billing risk, setup completion, channel readiness, and governance reviews',
            'sales_motion' => 'Internal nurturing rhythm for customers and trials, not new-customer marketing or outbound sales',
            'deal_movement_strategy' => 'Move operational items from detection to review, owner contact, recovery, and audit closure.',
            'outreach_posture' => 'Helpful, specific, nurturing, and recovery-focused. Do not use this workspace for promotional cold outreach or marketing to convert new customers.',
            'positioning_notes' => 'Clarity should treat this workspace as the platform management HQ for existing customer and trial owner nurturing. Contacts are eligible workspace owners, so drafts should use owner workspace status, onboarding gaps, billing risk, AI Credit balance, channel health, and recent admin context.',
            'draft_tone_preset' => 'consultative',
            'draft_voice_notes' => 'Calm, precise, owner-friendly, and action-oriented. Explain the issue, the next step, and the business impact without sounding promotional.',
            'draft_cta_style' => 'clear',
            'draft_formality_level' => 'balanced',
            'draft_reading_level' => 'professional',
            'lean_problem' => 'Platform work gets scattered across tenant setup, billing, tokens, channel health, support, and security.',
            'lean_customer_segments' => 'Workspace owners, Super Admin, platform support, billing operations, tenant success, technical operator.',
            'lean_unique_value_proposition' => 'A default workspace that runs customer and trial nurturing with owner contacts, operational context, templates, tasks, and audit-aware guidance.',
            'lean_solution' => 'Centralize eligible owner contacts, workspace directory, onboarding recovery, billing guide, nudge drafts, support follow-up, security review, and platform follow-up tasks.',
            'lean_channels' => 'Default workspace contacts, Dashboard, Settings, Workspace Directory, Clarity Super Admin Ops, in-app notifications, email, WhatsApp draft paths.',
            'lean_revenue_streams' => 'Tenant subscriptions, token top-ups, paid support, setup assistance, platform managed services.',
            'lean_cost_structure' => 'AI Credits, messaging providers, payment provider fees, support time, hosting, compliance reviews.',
            'lean_key_metrics' => 'Operational score, stuck onboarding count, failed billing events, low token workspaces, unresolved operator actions.',
            'lean_unfair_advantage' => 'Direct access to platform-wide tenant, billing, audit, and setup context.',
        ]);
    }

    private function seedInvoiceSettings(int $ownerUserId): void
    {
        (new InvoiceSettings())->save([
            'enabled' => true,
            'default_currency' => 'KES',
            'default_tax_mode' => 'exclusive',
            'default_tax_rate' => 0,
            'default_payment_terms_days' => 7,
            'default_validity_days' => 14,
            'invoice_prefix' => 'OPS-INV-',
            'quote_prefix' => 'OPS-QT-',
            'bank_name' => 'Platform Operations Bank',
            'bank_account_name' => 'Clarity Platform Operations',
            'bank_account_number' => '0000000000',
            'bank_branch' => 'Nairobi',
            'bank_swift' => 'PLATFORM',
            'bank_instructions' => 'Use the payment reference shown on the invoice. Confirm payment in the workspace billing panel after settlement.',
            'default_notes' => 'Internal platform operations document. Review tenant billing or support context before sending externally.',
            'default_terms' => 'Payment or token allocation terms should match the tenant billing record and operator audit trail.',
            'footer_text' => 'Generated from the Platform Ops default workspace.',
            'default_template_key' => 'classic',
            'preview_document_type' => 'invoice',
            'proposal_intro_text' => 'Prepared for platform operations review.',
            'acceptance_instructions' => 'Confirm the operational action in the workspace admin panel and retain the audit trail.',
        ], $ownerUserId);
    }

    private function seedAutomationSettings(): void
    {
        (new DealAutomationConfig())->save([
            'enabled' => true,
            'mode' => 'auto_safe',
            'min_confidence' => 0.86,
            'lookback_days' => 30,
            'cooldown_hours' => 12,
            'require_approval_terminal' => true,
            'min_terminal_confidence' => 0.94,
            'inactivity_days_for_loss' => 21,
            'allow_multi_stage_jump' => false,
            'dry_run' => false,
            'reopen_lost_on_reengagement' => true,
            'auto_create_from_inbound' => true,
            'auto_create_require_non_negative' => true,
            'auto_create_require_meaningful_reply' => true,
            'auto_create_min_message_chars' => 20,
            'auto_create_dedupe_hours' => 24,
            'auto_create_channels' => ['email' => true, 'whatsapp' => true, 'sms' => false],
            'inbox_triage_enabled' => true,
            'inbox_triage_auto_apply' => false,
            'inbox_triage_channels' => ['email' => true, 'whatsapp' => true],
            'inbox_triage_min_confidence' => 0.82,
            'outcome_layer_enabled' => true,
            'outcome_layer_rollout_percent' => 100,
        ]);
    }

    private function markWorkspaceSettings(int $workspaceId): void
    {
        $workspace = $this->resolveDefaultWorkspace();
        $settings = $this->decodeJson($workspace['settings_json'] ?? null);
        $settings['workspace_purpose'] = 'platform_ops';
        $settings['internal_channel_ready'] = true;
        $settings['internal_team_ready'] = true;
        $settings['owner_helpline_enabled'] = true;
        $settings['default_workspace_contact_scope'] = 'existing_customer_or_trial';
        $settings['default_workspace_nurture_auto_qualified'] = true;
        $settings['new_customer_marketing_allowed'] = false;
        $settings['cold_outreach_allowed'] = false;
        $settings['operationalized_at'] = gmdate('c');
        $settings['operationalized_source'] = self::SOURCE;
        $settings['seed_source'] = self::SOURCE;
        $settings['seed_key'] = 'workspace_settings';
        $settings['seed_version'] = self::SEED_VERSION;
        $settings['last_seeded_at'] = gmdate('c');

        Database::execute(
            "UPDATE workspaces
             SET name = ?,
                 status = 'active',
                 plan_status = 'active',
                 settings_json = ?,
                 updated_at = NOW()
             WHERE id = ?",
            ['Clarity Platform Operations HQ', json_encode($settings, JSON_UNESCAPED_SLASHES), $workspaceId]
        );
    }

    private function completeOnboardingState(int $workspaceId, int $ownerUserId): void
    {
        (new WorkspaceOnboardingService())->createInProgress($workspaceId);
        $launchSummary = [
            'company' => [
                'name' => 'Clarity Platform Operations HQ',
                'industry' => 'CRM SaaS platform operations',
                'location' => 'Nairobi, Kenya',
                'description' => 'Internal Super Admin workspace for nurturing existing customer and trial workspace owners.',
            ],
            'offer' => [
                'name' => 'Workspace Provisioning and Recovery',
                'pricing' => 'Internal platform operations',
                'ideal_customer' => 'Existing customer and trial workspace owners',
                'offer_angle' => 'Run tenant health, billing, onboarding, security, and support from one workspace. This workspace receives existing customers and trial users only; they automatically qualify for nurturing. It is explicitly not for marketing to convert new customers, cold outreach, or tenant promotion.',
            ],
            'voice' => [
                'tone' => 'consultative',
                'formality' => 'balanced',
                'cta' => 'clear',
                'notes' => 'Specific, audit-aware, and action-oriented.',
            ],
            'automation' => [
                'technical_level' => 'run_quietly',
                'mail_ready' => true,
                'whatsapp_ready' => false,
                'internal_channel_ready' => true,
            ],
            'money' => [
                'currency' => 'KES',
                'payment_terms_days' => 7,
            ],
            'platform_ops' => true,
            'owner_helpline_enabled' => true,
            'contact_scope' => 'existing_customer_or_trial',
            'nurture_auto_qualified' => true,
            'not_for_new_customer_marketing' => true,
            'not_for_cold_outreach' => true,
            'operating_boundary' => 'Use this workspace for nurturing existing customer and trial workspace owners only. Create a separate tenant workspace for new-customer marketing, promotion, sales campaigns, or cold outreach.',
            'generated_at' => gmdate('c'),
        ];
        $optionalSetup = [
            'lean_canvas' => ['enabled' => true, 'filled_blocks' => 9, 'total_blocks' => 9],
            'invoice_setup' => ['enabled' => true, 'currency' => 'KES', 'has_payment_instructions' => true],
            'email_assistant' => ['enabled' => false],
            'whatsapp_assistant' => ['enabled' => false],
            'platform_ops' => ['internal_channel_ready' => true, 'internal_team_ready' => true, 'owner_helpline_enabled' => true],
        ];
        $starterKit = [
            'source' => self::SOURCE,
            'tasks' => array_column($this->taskDefinitions(), 'key'),
            'templates' => array_column($this->emailTemplateDefinitions(), 'key'),
            'capabilities' => (new DefaultWorkspaceCapabilityCatalogService())->names(),
            'generated_at' => gmdate('c'),
        ];
        $tone = [
            'draft_tone_preset' => 'consultative',
            'draft_voice_notes' => 'Calm, precise, operator-friendly, and action-oriented.',
            'draft_cta_style' => 'clear',
            'draft_formality_level' => 'balanced',
            'draft_reading_level' => 'professional',
            'relationship_style' => 'trusted_advisor',
            'words_to_avoid' => 'growth hacks, spam, blast, fake urgency',
            'escalation_preference' => 'Escalate billing disputes, security changes, deletions, angry owners, and provider failures to Super Admin.',
        ];

        Database::execute(
            "UPDATE workspace_onboarding_state
             SET status = 'completed',
                 current_step = ?,
                 readiness_score = 100,
                 communication_channel = 'email',
                 automation_launch_mode = 'full_auto',
                 technical_level = 'run_quietly',
                 relationship_style = 'trusted_advisor',
                 tone_json = ?,
                 optional_setup_json = ?,
                 required_steps_json = ?,
                 completed_steps_json = ?,
                 skipped_optional_json = JSON_ARRAY(),
                 recommended_workflows_json = ?,
                 launch_summary_json = ?,
                 starter_kit_json = ?,
                 ai_autoresponder_mode = 'draft_only',
                 ai_best_practices_enabled = 1,
                 commercial_layer_enabled = 1,
                 deal_automation_enabled = 1,
                 completed_at = COALESCE(completed_at, NOW()),
                 updated_at = NOW()
             WHERE workspace_id = ?",
            [
                WorkspaceOnboardingService::STEP_COUNT,
                json_encode($tone, JSON_UNESCAPED_SLASHES),
                json_encode($optionalSetup, JSON_UNESCAPED_SLASHES),
                json_encode(self::REQUIRED_STEPS),
                json_encode(self::REQUIRED_STEPS),
                json_encode(['source' => self::SOURCE, 'recommendations' => $this->workflowRecommendations()], JSON_UNESCAPED_SLASHES),
                json_encode($launchSummary, JSON_UNESCAPED_SLASHES),
                json_encode($starterKit, JSON_UNESCAPED_SLASHES),
                $workspaceId,
            ]
        );
    }

    private function seedEmailTemplates(int $ownerUserId): array
    {
        $counts = $this->emptyCounts();
        $workspaceId = $this->defaultWorkspace->id();
        foreach ($this->emailTemplateDefinitions() as $definition) {
            $slug = 'platform-ops-' . $definition['key'];
            $seedSelect = Database::columnExists('email_templates', 'seed_metadata_json') ? ', seed_metadata_json' : '';
            $existing = Database::queryOne("SELECT id{$seedSelect} FROM email_templates WHERE slug = ? LIMIT 1", [$slug]);
            $params = [
                $workspaceId,
                $definition['name'],
                $definition['subject'],
                $definition['body_html'],
                $definition['body_text'],
                'platform_ops',
                json_encode($definition['variables'], JSON_UNESCAPED_SLASHES),
                1,
                0,
                $definition['description'],
                json_encode(array_values(array_unique(array_merge(['platform_ops'], (array) ($definition['tags'] ?? []), [$definition['key']]))), JSON_UNESCAPED_SLASHES),
                'SaaS operations',
                'platform_ops',
                0,
                'Clarity Platform Ops',
                '1.0',
                1,
                $definition['key'],
                json_encode($this->platformEmailMatchMetadata((string) $definition['key']), JSON_UNESCAPED_SLASHES),
                $ownerUserId,
            ];
            if ($existing) {
                if ($this->isCustomizedSeed($existing)) {
                    $counts['skipped_customized']++;
                    $counts['warnings'][] = 'Skipped customized email template: ' . $slug;
                    continue;
                }
                $params[] = (int) $existing['id'];
                Database::execute(
                    "UPDATE email_templates
                     SET workspace_id = ?, name = ?, subject = ?, body_html = ?, body_text = ?, category = ?, variables = ?,
                         is_active = ?, is_library = ?, description = ?, tags = ?, industry = ?, purpose = ?,
                         is_featured = ?, author = ?, version = ?, is_ai_generated = ?, template_key = ?, match_metadata_json = ?,
                         created_by = ?, updated_at = NOW()
                     WHERE id = ?",
                    $params
                );
                $this->writeSeedMetadata('email_templates', (int) $existing['id'], (string) $definition['key']);
                $counts[empty($existing['seed_metadata_json']) ? 'repaired' : 'updated']++;
            } else {
                array_splice($params, 2, 0, [$slug]);
                Database::execute(
                    "INSERT INTO email_templates
                     (workspace_id, name, slug, subject, body_html, body_text, category, variables, is_active, is_library,
                      description, tags, industry, purpose, is_featured, author, version, is_ai_generated, template_key, match_metadata_json, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    $params
                );
                $this->writeSeedMetadata('email_templates', (int) Database::lastInsertId(), (string) $definition['key']);
                $counts['created']++;
            }
        }
        return $counts;
    }

    private function seedWorkflowTemplates(int $ownerUserId): array
    {
        $counts = $this->emptyCounts();
        foreach ($this->workflowTemplateDefinitions() as $definition) {
            $seedSelect = Database::columnExists('workflow_templates', 'seed_metadata_json') ? ', seed_metadata_json' : '';
            $existing = Database::queryOne("SELECT id{$seedSelect} FROM workflow_templates WHERE template_key = ? LIMIT 1", [$definition['key']]);
            $params = [
                $definition['name'],
                $definition['description'],
                'platform_ops',
                json_encode($definition['trigger_config'], JSON_UNESCAPED_SLASHES),
                json_encode($definition['conditions'], JSON_UNESCAPED_SLASHES),
                json_encode($definition['actions'], JSON_UNESCAPED_SLASHES),
                json_encode($definition['variables'], JSON_UNESCAPED_SLASHES),
                0,
                $ownerUserId,
                1,
                0,
                $definition['key'],
                json_encode($this->platformWorkflowRecipeMetadata((string) $definition['key']), JSON_UNESCAPED_SLASHES),
            ];
            if ($existing) {
                if ($this->isCustomizedSeed($existing)) {
                    $counts['skipped_customized']++;
                    $counts['warnings'][] = 'Skipped customized workflow template: ' . (string) $definition['key'];
                    continue;
                }
                $params[] = (int) $existing['id'];
                Database::execute(
                    "UPDATE workflow_templates
                     SET name = ?, description = ?, category = ?, trigger_config = ?, conditions = ?, actions = ?,
                         variables = ?, is_public = ?, created_by = ?, is_active = ?, is_ai_generated = ?, template_key = ?,
                         recipe_metadata_json = ?
                     WHERE id = ?",
                    $params
                );
                $this->writeSeedMetadata('workflow_templates', (int) $existing['id'], (string) $definition['key']);
                $counts[empty($existing['seed_metadata_json']) ? 'repaired' : 'updated']++;
            } else {
                Database::execute(
                    "INSERT INTO workflow_templates
                     (name, description, category, trigger_config, conditions, actions, variables, is_public, created_by, is_active, is_ai_generated, template_key, recipe_metadata_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    $params
                );
                $this->writeSeedMetadata('workflow_templates', (int) Database::lastInsertId(), (string) $definition['key']);
                $counts['created']++;
            }
        }
        return $counts;
    }

    private function seedTasks(int $workspaceId, int $ownerUserId): array
    {
        $counts = $this->emptyCounts();
        foreach ($this->taskDefinitions() as $definition) {
            $metadata = [
                'source' => self::SOURCE,
                'seed_source' => self::SOURCE,
                'seed_key' => $definition['key'],
                'seed_version' => self::SEED_VERSION,
                'last_seeded_at' => gmdate('c'),
                'checklist_key' => $definition['key'],
                'platform_ops_area' => $definition['area'],
                'url' => $definition['url'],
                'auto_complete_allowed' => false,
            ];
            $existing = $this->findTaskByKey($workspaceId, (string) $definition['key']);
            if ($existing) {
                $existingMetadata = $this->decodeJson($existing['metadata_json'] ?? null);
                if (!empty($existingMetadata['customized_at'])) {
                    $counts['skipped_customized']++;
                    $counts['warnings'][] = 'Skipped customized task: ' . (string) $definition['key'];
                    continue;
                }
                Database::execute(
                    "UPDATE tasks
                     SET title = ?, description = ?, assigned_to = ?, created_by = ?, status = 'completed', priority = ?, metadata_json = ?, updated_at = NOW()
                    WHERE id = ?",
                    [$definition['title'], $definition['description'], $ownerUserId, $ownerUserId, $definition['priority'], json_encode($metadata, JSON_UNESCAPED_SLASHES), (int) $existing['id']]
                );
                $counts[empty($existingMetadata['seed_source']) ? 'repaired' : 'updated']++;
            } else {
                Database::execute(
                    "INSERT INTO tasks (workspace_id, title, description, assigned_to, created_by, status, priority, metadata_json, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 'completed', ?, ?, NOW(), NOW())",
                    [$workspaceId, $definition['title'], $definition['description'], $ownerUserId, $ownerUserId, $definition['priority'], json_encode($metadata, JSON_UNESCAPED_SLASHES)]
                );
                $counts['created']++;
            }
        }
        return $counts;
    }

    private function seedDefaultWorkspaceAiPrompts(int $workspaceId): array
    {
        $counts = $this->emptyCounts();
        if (!Database::tableExists('ai_prompt_registry')) {
            $counts['warnings'][] = 'AI prompt registry table is missing.';
            $counts['missing_after_repair'] = count($this->defaultWorkspaceAiPromptDefinitions());
            return $counts;
        }

        foreach ($this->defaultWorkspaceAiPromptDefinitions() as $definition) {
            $active = $this->findActiveDefaultWorkspaceAiPrompt($workspaceId, $definition);
            if ($active) {
                if (!$this->isDefaultWorkspaceAiPromptSeed($active)) {
                    $counts['skipped_customized']++;
                    $counts['warnings'][] = 'Skipped customized AI prompt: ' . $definition['surface'] . '/' . $definition['prompt_key'];
                    continue;
                }

                if ($this->aiPromptNeedsRepair($active, $definition)) {
                    $this->updateDefaultWorkspaceAiPrompt((int) $active['id'], $definition);
                    $counts['updated']++;
                } else {
                    $counts['existing']++;
                }
                continue;
            }

            $seeded = $this->findSeededDefaultWorkspaceAiPrompt($workspaceId, $definition);
            if ($seeded) {
                $this->updateDefaultWorkspaceAiPrompt((int) $seeded['id'], $definition, true);
                $counts['repaired']++;
                continue;
            }

            $this->insertDefaultWorkspaceAiPrompt($workspaceId, $definition);
            $counts['created']++;
        }

        $counts['missing_after_repair'] = count($this->missingDefaultWorkspaceAiPrompts($workspaceId));
        return $counts;
    }

    private function ensureMembership(int $workspaceId, int $userId): void
    {
        $existing = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        );
        if ($existing) {
            Database::execute(
                "UPDATE workspace_memberships
                 SET role_slug = 'superadmin', membership_status = 'active', is_owner = 1, joined_at = COALESCE(joined_at, NOW()), updated_at = NOW()
                 WHERE id = ?",
                [(int) $existing['id']]
            );
            return;
        }
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, created_at, updated_at)
             VALUES (?, ?, 'superadmin', 'active', 1, NOW(), NOW(), NOW())",
            [$workspaceId, $userId]
        );
    }

    private function resolveOwnerUserId(int $workspaceId, int $fallbackUserId): int
    {
        $owner = Database::queryOne(
            "SELECT user_id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'
             ORDER BY is_owner DESC, FIELD(role_slug, 'superadmin', 'owner', 'admin', 'accountant', 'expert', 'viewer'), id ASC
             LIMIT 1",
            [$workspaceId]
        );
        return (int) (($owner['user_id'] ?? 0) ?: $fallbackUserId);
    }

    private function findTaskByKey(int $workspaceId, string $key): ?array
    {
        $rows = Database::query(
            "SELECT id, metadata_json
             FROM tasks
             WHERE workspace_id = ?
               AND metadata_json IS NOT NULL
               AND metadata_json LIKE ?",
            [$workspaceId, '%' . self::SOURCE . '%']
        );
        foreach ($rows as $row) {
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            if (($metadata['source'] ?? '') === self::SOURCE && ($metadata['checklist_key'] ?? '') === $key) {
                return $row;
            }
        }
        return null;
    }

    private function isDefaultWorkspace(array $workspace): bool
    {
        return $this->defaultWorkspace->isDefaultWorkspace(null, $workspace);
    }

    private function decodeJson($json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? $decoded : [];
    }

    private function diagnosticsForWorkspace(int $workspaceId, int $ownerUserId, array $settings, int $operationalScore): array
    {
        $components = [];

        $workspaceSettingsMissing = [];
        if (($settings['workspace_purpose'] ?? '') !== 'platform_ops') {
            $workspaceSettingsMissing[] = 'workspace_purpose';
        }
        if (empty($settings['internal_channel_ready'])) {
            $workspaceSettingsMissing[] = 'internal_channel_ready';
        }
        if (empty($settings['internal_team_ready'])) {
            $workspaceSettingsMissing[] = 'internal_team_ready';
        }
        $components['workspace_settings'] = $this->diagnosticItem('Workspace settings', $workspaceSettingsMissing);
        $helplineMissing = [];
        if (empty($settings['owner_helpline_enabled'])) {
            $helplineMissing[] = 'owner_helpline_enabled';
        }
        foreach (['owner_welcome_setup', 'owner_problem_followup', 'owner_support_resolution'] as $key) {
            $slug = 'platform-ops-' . $key;
            $exists = Database::queryOne("SELECT id FROM email_templates WHERE slug = ? LIMIT 1", [$slug]);
            if (!$exists) {
                $helplineMissing[] = $slug;
            }
        }
        $components['owner_helpline'] = $this->diagnosticItem('Owner helpline', $helplineMissing);

        $profile = Database::queryOne(
            "SELECT company_name, company_description, company_industry
             FROM company_profile
             WHERE workspace_id = ? AND is_active = 1
             LIMIT 1",
            [$workspaceId]
        ) ?: [];
        $profileMissing = [];
        foreach (['company_name', 'company_description', 'company_industry'] as $field) {
            if (trim((string) ($profile[$field] ?? '')) === '') {
                $profileMissing[] = $field;
            }
        }
        $components['profile'] = $this->diagnosticItem('Company profile', $profileMissing);

        $components['capabilities'] = $this->diagnosticItem(
            'Platform Ops internal capabilities',
            (new DefaultWorkspaceCapabilityCatalogService())->validationErrors()
        );
        $components['templates'] = $this->diagnosticItem(
            'Email templates',
            $this->missingEmailTemplates()
        );
        $components['workflow_templates'] = $this->diagnosticItem(
            'Workflow templates',
            $this->missingWorkflowTemplates()
        );
        $components['tasks'] = $this->diagnosticItem(
            'Platform Ops tasks',
            $this->missingTasks($workspaceId)
        );
        $components['ai_prompts'] = $this->defaultWorkspaceAiPromptDiagnostics($workspaceId);

        $invoice = (new InvoiceSettings())->get();
        $invoiceMissing = [];
        if (empty($invoice['enabled'])) {
            $invoiceMissing[] = 'invoice_enabled';
        }
        if (trim((string) ($invoice['default_currency'] ?? '')) === '') {
            $invoiceMissing[] = 'default_currency';
        }
        if (trim((string) ($invoice['bank_instructions'] ?? '')) === '') {
            $invoiceMissing[] = 'payment_instructions';
        }
        $components['invoice_settings'] = $this->diagnosticItem('Invoice settings', $invoiceMissing);

        $state = Database::queryOne(
            "SELECT status, readiness_score, launch_summary_json, starter_kit_json, optional_setup_json
             FROM workspace_onboarding_state
             WHERE workspace_id = ?
             LIMIT 1",
            [$workspaceId]
        ) ?: [];
        $launchSummary = $this->decodeJson($state['launch_summary_json'] ?? null);
        $onboardingMissing = [];
        if (($state['status'] ?? '') !== 'completed') {
            $onboardingMissing[] = 'completed_status';
        }
        if ((int) ($state['readiness_score'] ?? 0) < 100) {
            $onboardingMissing[] = 'readiness_score_100';
        }
        if (empty($state['starter_kit_json'])) {
            $onboardingMissing[] = 'starter_kit';
        }
        if (empty($state['optional_setup_json'])) {
            $onboardingMissing[] = 'optional_setup';
        }
        $components['onboarding_state'] = $this->diagnosticItem('Onboarding state', $onboardingMissing);
        $components['operating_brief'] = $this->diagnosticItem(
            'Operating brief',
            empty($launchSummary['operating_brief']) ? ['operating_brief'] : []
        );
        $components['operational_score'] = $this->diagnosticItem(
            'Operational score',
            $operationalScore >= 100 ? [] : ['score_below_100']
        );

        $missing = [];
        $healthy = 0;
        foreach ($components as $key => $component) {
            if (!empty($component['healthy'])) {
                $healthy++;
                continue;
            }
            foreach ((array) ($component['missing'] ?? []) as $piece) {
                $missing[] = $key . ':' . $piece;
            }
        }

        return [
            'healthy_count' => $healthy,
            'total_count' => count($components),
            'components' => $components,
            'missing' => $missing,
            'warnings' => $this->nonDefaultPlatformOpsWarnings(),
        ];
    }

    private function diagnosticItem(string $label, array $missing): array
    {
        return [
            'label' => $label,
            'healthy' => empty($missing),
            'missing' => array_values($missing),
        ];
    }

    private function defaultWorkspaceAiPromptDiagnostics(int $workspaceId): array
    {
        $missing = $this->missingDefaultWorkspaceAiPrompts($workspaceId);
        $active = 0;
        $customized = 0;
        if (Database::tableExists('ai_prompt_registry')) {
            foreach ($this->defaultWorkspaceAiPromptDefinitions() as $definition) {
                $row = $this->findActiveDefaultWorkspaceAiPrompt($workspaceId, $definition);
                if (!$row) {
                    continue;
                }
                $active++;
                if (!$this->isDefaultWorkspaceAiPromptSeed($row)) {
                    $customized++;
                }
            }
        }

        return $this->diagnosticItem('Default workspace AI prompts', $missing) + [
            'active_override_count' => $active,
            'customized_override_count' => $customized,
        ];
    }

    private function emptyCounts(): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'repaired' => 0,
            'existing' => 0,
            'skipped' => 0,
            'skipped_customized' => 0,
            'missing_after_repair' => 0,
            'warnings' => [],
        ];
    }

    /**
     * @param array<string,mixed> $counts
     */
    private function upsertedCount(array $counts): int
    {
        return (int) (
            ($counts['created'] ?? 0)
            + ($counts['updated'] ?? 0)
            + ($counts['repaired'] ?? 0)
            + ($counts['existing'] ?? 0)
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isCustomizedSeed(array $row): bool
    {
        if (empty($row['seed_metadata_json'])) {
            return false;
        }

        $metadata = $this->decodeJson($row['seed_metadata_json']);
        if ($metadata === []) {
            return false;
        }

        return (string) ($metadata['seed_source'] ?? '') !== self::SOURCE
            || !empty($metadata['customized_at']);
    }

    private function writeSeedMetadata(string $table, int $id, string $seedKey): void
    {
        if ($id <= 0 || !preg_match('/^[a-z0-9_]+$/', $table) || !Database::columnExists($table, 'seed_metadata_json')) {
            return;
        }

        Database::execute(
            "UPDATE `{$table}`
             SET seed_metadata_json = ?
             WHERE id = ?",
            [
                json_encode($this->seedMetadata($seedKey), JSON_UNESCAPED_SLASHES),
                $id,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function seedMetadata(string $seedKey): array
    {
        return [
            'seed_source' => self::SOURCE,
            'seed_key' => $seedKey,
            'seed_version' => self::SEED_VERSION,
            'last_seeded_at' => gmdate('c'),
        ];
    }

    private function findActiveDefaultWorkspaceAiPrompt(int $workspaceId, array $definition): ?array
    {
        return Database::queryOne(
            "SELECT *
             FROM ai_prompt_registry
             WHERE workspace_id = ?
               AND surface = ?
               AND prompt_key = ?
               AND status = 'active'
             ORDER BY version DESC, id DESC
             LIMIT 1",
            [$workspaceId, $definition['surface'], $definition['prompt_key']]
        ) ?: null;
    }

    private function findSeededDefaultWorkspaceAiPrompt(int $workspaceId, array $definition): ?array
    {
        $rows = Database::query(
            "SELECT *
             FROM ai_prompt_registry
             WHERE workspace_id = ?
               AND surface = ?
               AND prompt_key = ?
             ORDER BY version DESC, id DESC",
            [$workspaceId, $definition['surface'], $definition['prompt_key']]
        );

        foreach ($rows as $row) {
            if ($this->isDefaultWorkspaceAiPromptSeed($row)) {
                return $row;
            }
        }

        return null;
    }

    private function isDefaultWorkspaceAiPromptSeed(array $row): bool
    {
        $metadata = $this->decodeJson($row['metadata_json'] ?? null);
        return (string) ($metadata['seed_source'] ?? '') === self::AI_SOURCE
            && empty($metadata['customized_at']);
    }

    private function aiPromptNeedsRepair(array $row, array $definition): bool
    {
        $metadata = $this->decodeJson($row['metadata_json'] ?? null);
        return (string) ($row['system_prompt_text'] ?? '') !== (string) $definition['system_prompt_text']
            || (string) ($row['instruction_text'] ?? '') !== (string) $definition['instruction_text']
            || json_encode($this->decodeJson($row['output_contract_json'] ?? null), JSON_UNESCAPED_SLASHES) !== json_encode($definition['output_contract_json'], JSON_UNESCAPED_SLASHES)
            || (string) ($metadata['seed_version'] ?? '') !== self::AI_SEED_VERSION;
    }

    private function updateDefaultWorkspaceAiPrompt(int $id, array $definition, bool $activate = false): void
    {
        $statusSql = $activate ? ", status = 'active'" : '';
        Database::execute(
            "UPDATE ai_prompt_registry
             SET system_prompt_text = ?,
                 instruction_text = ?,
                 output_contract_json = ?,
                 metadata_json = ?{$statusSql}
             WHERE id = ?",
            [
                $definition['system_prompt_text'],
                $definition['instruction_text'],
                json_encode($definition['output_contract_json'], JSON_UNESCAPED_SLASHES),
                json_encode($this->defaultWorkspaceAiPromptMetadata((string) $definition['seed_key']), JSON_UNESCAPED_SLASHES),
                $id,
            ]
        );
    }

    private function insertDefaultWorkspaceAiPrompt(int $workspaceId, array $definition): void
    {
        Database::execute(
            "INSERT INTO ai_prompt_registry
                (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
             VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, NULL)",
            [
                $workspaceId,
                $definition['surface'],
                $definition['prompt_key'],
                $this->nextDefaultWorkspaceAiPromptVersion($workspaceId, (string) $definition['surface'], (string) $definition['prompt_key']),
                $definition['system_prompt_text'],
                $definition['instruction_text'],
                json_encode($definition['output_contract_json'], JSON_UNESCAPED_SLASHES),
                json_encode($this->defaultWorkspaceAiPromptMetadata((string) $definition['seed_key']), JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function nextDefaultWorkspaceAiPromptVersion(int $workspaceId, string $surface, string $promptKey): int
    {
        $row = Database::queryOne(
            "SELECT MAX(version) AS max_version
             FROM ai_prompt_registry
             WHERE workspace_id = ?
               AND surface = ?
               AND prompt_key = ?",
            [$workspaceId, $surface, $promptKey]
        ) ?: [];

        return ((int) ($row['max_version'] ?? 0)) + 1;
    }

    private function defaultWorkspaceAiPromptMetadata(string $seedKey): array
    {
        return [
            'seed_source' => self::AI_SOURCE,
            'seed_key' => $seedKey,
            'seed_version' => self::AI_SEED_VERSION,
            'protected_default_workspace' => true,
            'last_seeded_at' => gmdate('c'),
        ];
    }

    private function missingDefaultWorkspaceAiPrompts(int $workspaceId): array
    {
        if (!Database::tableExists('ai_prompt_registry')) {
            return array_map(
                static fn(array $definition): string => $definition['surface'] . ':' . $definition['prompt_key'],
                $this->defaultWorkspaceAiPromptDefinitions()
            );
        }

        $missing = [];
        foreach ($this->defaultWorkspaceAiPromptDefinitions() as $definition) {
            $active = $this->findActiveDefaultWorkspaceAiPrompt($workspaceId, $definition);
            if (!$active) {
                $missing[] = $definition['surface'] . ':' . $definition['prompt_key'];
            }
        }

        return $missing;
    }

    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    private function missingEmailTemplates(): array
    {
        $slugs = array_map(static fn(array $definition): string => 'platform-ops-' . $definition['key'], $this->emailTemplateDefinitions());
        if (empty($slugs)) {
            return [];
        }
        $rows = Database::query(
            "SELECT slug FROM email_templates WHERE slug IN (" . $this->placeholders($slugs) . ")",
            $slugs
        );
        $found = array_map(static fn(array $row): string => (string) ($row['slug'] ?? ''), $rows);
        return array_values(array_diff($slugs, $found));
    }

    private function missingWorkflowTemplates(): array
    {
        $keys = array_column($this->workflowTemplateDefinitions(), 'key');
        if (empty($keys)) {
            return [];
        }
        $rows = Database::query(
            "SELECT template_key FROM workflow_templates WHERE template_key IN (" . $this->placeholders($keys) . ")",
            $keys
        );
        $found = array_map(static fn(array $row): string => (string) ($row['template_key'] ?? ''), $rows);
        return array_values(array_diff($keys, $found));
    }

    private function missingTasks(int $workspaceId): array
    {
        $missing = [];
        foreach ($this->taskDefinitions() as $definition) {
            if (!$this->findTaskByKey($workspaceId, (string) $definition['key'])) {
                $missing[] = (string) $definition['key'];
            }
        }
        return $missing;
    }

    private function nonDefaultPlatformOpsWarnings(): array
    {
        $warnings = [];
        $rows = Database::query(
            "SELECT id, name, slug, settings_json
             FROM workspaces
             WHERE NOT (id = ? OR slug = ?)",
            [DefaultWorkspaceService::DEFAULT_ID, DefaultWorkspaceService::DEFAULT_SLUG]
        );
        foreach ($rows as $row) {
            $settings = $this->decodeJson($row['settings_json'] ?? null);
            if (($settings['workspace_purpose'] ?? '') === 'platform_ops') {
                $warnings[] = sprintf(
                    'Workspace %s (%s) has platform_ops settings but is not the protected default workspace.',
                    (string) ($row['name'] ?? ('#' . (int) ($row['id'] ?? 0))),
                    (string) ($row['slug'] ?? '')
                );
            }
        }
        return $warnings;
    }

    private function defaultWorkspaceAiPromptDefinitions(): array
    {
        return [
            [
                'surface' => 'coach',
                'prompt_key' => 'coach_recommendations',
                'seed_key' => 'coach_recommendations',
                'system_prompt_text' => 'You are Clarity inside the protected default workspace, which is Platform Ops HQ. Treat this workspace as an internal customer-success and platform-operations command center, not a normal tenant CRM.',
                'instruction_text' => 'Use the context bundle to produce structured recommendations for tenant health, workspace-owner onboarding, billing risk, channel readiness, AI Credit balances, support follow-up, failed provider events, and operational recovery. Prefer workspace-owner and tenant evidence over generic sales or growth advice. Do not recommend cold outreach, ordinary lead generation, or tenant business strategy unless the request is explicitly about platform/customer-success operations. Return structured JSON only.',
                'output_contract_json' => ['type' => 'object', 'sections' => ['why_this_matters', 'priorities', 'quick_wins', 'missing_features', 'foundation_gaps']],
            ],
            [
                'surface' => 'clarity_chat',
                'prompt_key' => 'clarity_question_answer',
                'seed_key' => 'clarity_question_answer',
                'system_prompt_text' => 'You are Clarity in Platform Ops HQ. Answer as a Super Admin operations assistant for tenant workspaces, workspace owners, onboarding, billing, support, channel health, token usage, and audit-aware recovery.',
                'instruction_text' => 'Use the provided context bundle. If the question is about the default workspace, explain it as the internal Platform Ops/customer-success workspace. If the user asks for normal tenant CRM growth advice, redirect to the appropriate tenant workspace or Marketplace/setup unless the request is framed as platform operations. Stay specific, grounded, and concise. Return plain text unless a JSON contract is explicitly requested.',
                'output_contract_json' => ['type' => 'text'],
            ],
            [
                'surface' => 'assistant',
                'prompt_key' => 'assistant_question',
                'seed_key' => 'assistant_question',
                'system_prompt_text' => 'You are Clarity Assistant in Platform Ops HQ. Help Super Admins reason about tenant workspaces and workspace-owner operations using only the structured context.',
                'instruction_text' => 'Answer assistant questions through the lens of platform operations: owner follow-up, onboarding gaps, billing/provider events, channel setup, AI Credit balances, support recovery, and operator audit context. Do not treat mirrored owner contacts as generic leads. If context is weak, say what evidence is missing. Return plain text unless a JSON contract is explicitly requested.',
                'output_contract_json' => ['type' => 'text'],
            ],
            [
                'surface' => 'assistant',
                'prompt_key' => 'assistant_change_explanation',
                'seed_key' => 'assistant_change_explanation',
                'system_prompt_text' => 'You are Clarity Assistant in Platform Ops HQ. Explain operational, billing, setup, support, or quote/document changes with tenant-safety and audit clarity.',
                'instruction_text' => 'Use the context bundle to explain what changed, why it matters operationally, and any tenant/customer-success risks. Prefer precise before/after facts and avoid unsupported assumptions. Return strict JSON.',
                'output_contract_json' => ['type' => 'json', 'required' => ['summary', 'changes', 'risks']],
            ],
            [
                'surface' => 'assistant',
                'prompt_key' => 'assistant_ambiguity_summary',
                'seed_key' => 'assistant_ambiguity_summary',
                'system_prompt_text' => 'You are Clarity Assistant in Platform Ops HQ. Resolve ambiguity by asking for the minimum missing operational detail.',
                'instruction_text' => 'Summarize ambiguity around workspace owner, tenant workspace, billing event, channel setup, onboarding state, support case, AI Credit balance, or operator action. Ask only for the missing detail needed to proceed safely. Return strict JSON with message, top_candidates, and reason.',
                'output_contract_json' => ['type' => 'json', 'required' => ['message', 'top_candidates', 'reason']],
            ],
            [
                'surface' => 'assistant',
                'prompt_key' => 'assistant_customer_reply_goal',
                'seed_key' => 'assistant_customer_reply_goal',
                'system_prompt_text' => 'You are Clarity Email Assistant in Platform Ops HQ. Determine the safest customer-success reply goal for a workspace owner or tenant operations thread.',
                'instruction_text' => 'Use thread and context evidence to decide whether to draft, revise, send, explain blockers, or ask for clarification. Prioritize onboarding recovery, billing clarity, channel readiness, token-risk follow-up, support resolution, and owner trust. Do not treat workspace owners as cold prospects. Return strict JSON.',
                'output_contract_json' => ['type' => 'json', 'required' => ['goal', 'confidence', 'reasoning']],
            ],
            [
                'surface' => 'commercial_assistant',
                'prompt_key' => 'assistant_commercial_reply',
                'seed_key' => 'assistant_commercial_reply',
                'system_prompt_text' => 'You are Clarity Commercial Assistant in Platform Ops HQ. Draft customer-success and billing-operation replies for workspace owners using only supplied deal, invoice, billing, and thread context.',
                'instruction_text' => 'Draft clear owner-facing replies about subscriptions, invoices, failed payments, AI Credit balances, setup services, support resolution, or operational recovery. Do not invent pricing, discounts, commitments, or tenant business advice beyond the context. Return strict JSON with subject, plain_body, and explanation.',
                'output_contract_json' => ['type' => 'json', 'required' => ['subject', 'plain_body', 'explanation']],
            ],
        ];
    }

    private function taskDefinitions(): array
    {
        return [
            ['key' => 'review_stuck_onboarding', 'area' => 'onboarding_recovery', 'title' => 'Review stuck onboarding workspaces', 'description' => 'Open the workspace directory stuck-onboarding filter and recover owners with setup nudges where needed.', 'url' => 'workspaces.php?onboarding=stuck', 'priority' => 'high'],
            ['key' => 'review_workspace_billing', 'area' => 'billing', 'title' => 'Review workspace billing health', 'description' => 'Check trial ends, past-due subscriptions, low tokens, and failed provider events.', 'url' => 'workspaces.php', 'priority' => 'high'],
            ['key' => 'review_token_wallet_risk', 'area' => 'token_wallet', 'title' => 'Review token wallet risk', 'description' => 'Identify low-token workspaces and decide whether to nudge, top up, suspend, or escalate.', 'url' => 'workspaces.php', 'priority' => 'high'],
            ['key' => 'audit_channel_health', 'area' => 'channel_health', 'title' => 'Audit channel health signals', 'description' => 'Review workspaces with incomplete email, assistant Gmail, IMAP, SMTP, or WhatsApp setup.', 'url' => 'workspaces.php', 'priority' => 'medium'],
            ['key' => 'review_operator_actions', 'area' => 'audit', 'title' => 'Review recent operator actions', 'description' => 'Check impersonation, deletion, reset, setup-link, and onboarding recovery audit events.', 'url' => 'workspaces.php', 'priority' => 'medium'],
            ['key' => 'review_workspace_deletions', 'area' => 'deletion_review', 'title' => 'Review workspace deletion controls', 'description' => 'Confirm deletion guardrails, recent delete attempts, and 2FA policy for destructive actions.', 'url' => 'workspaces.php', 'priority' => 'medium'],
            ['key' => 'review_security_settings', 'area' => 'security', 'title' => 'Review Super Admin security settings', 'description' => 'Confirm workspace login/delete 2FA policy and authenticator readiness.', 'url' => 'workspaces.php', 'priority' => 'medium'],
            ['key' => 'refresh_platform_operating_brief', 'area' => 'operating_brief', 'title' => 'Refresh platform operating brief', 'description' => 'Regenerate the default workspace operating brief after major platform or billing changes.', 'url' => 'dashboard.php', 'priority' => 'low'],
        ];
    }

    private function emailTemplateDefinitions(): array
    {
        return [
            [
                'key' => 'owner_welcome_setup',
                'name' => 'Platform Ops - Owner Welcome and Setup',
                'subject' => 'Welcome to Clarity, {owner_name}',
                'description' => 'Warm setup note for a new workspace owner with the right next step.',
                'variables' => ['owner_name', 'workspace_name', 'setup_url', 'support_url'],
                'tags' => ['platform_ops_owner_helpline', 'workspace_owner', 'welcome'],
                'body_text' => "Hi {owner_name},\n\nWelcome to Clarity. {workspace_name} is ready for setup.\n\nStart setup: {setup_url}\n\nIf anything gets blocked, reply here or use the support link: {support_url}",
                'body_html' => '<p>Hi {owner_name},</p><p>Welcome to Clarity. <strong>{workspace_name}</strong> is ready for setup.</p><p><a href="{setup_url}">Start setup</a></p><p>If anything gets blocked, reply here or use the <a href="{support_url}">support link</a>.</p>',
            ],
            [
                'key' => 'onboarding_recovery',
                'name' => 'Platform Ops - Onboarding Recovery',
                'subject' => 'Finish setup for {workspace_name}',
                'description' => 'Helpful owner nudge for a workspace stuck in onboarding.',
                'variables' => ['owner_name', 'workspace_name', 'next_action', 'next_action_url', 'support_url'],
                'tags' => ['platform_ops_owner_helpline', 'workspace_owner', 'onboarding'],
                'body_text' => "Hi {owner_name},\n\n{workspace_name} is close to being ready. The next setup step is: {next_action}.\n\nContinue setup: {next_action_url}\n\nIf that step is blocked, reply here or open support: {support_url}",
                'body_html' => '<p>Hi {owner_name},</p><p><strong>{workspace_name}</strong> is close to being ready. The next setup step is: <strong>{next_action}</strong>.</p><p><a href="{next_action_url}">Continue setup</a></p><p>If that step is blocked, reply here or <a href="{support_url}">open support</a>.</p>',
            ],
            [
                'key' => 'billing_follow_up',
                'name' => 'Platform Ops - Billing Follow-up',
                'subject' => 'Billing review needed for {workspace_name}',
                'description' => 'Clear billing review request for trials, subscriptions, or provider events.',
                'variables' => ['owner_name', 'workspace_name', 'billing_status', 'action_url', 'support_url'],
                'tags' => ['platform_ops_owner_helpline', 'workspace_owner', 'billing'],
                'body_text' => "Hi {owner_name},\n\nA billing item needs review for {workspace_name}: {billing_status}.\n\nReview billing: {action_url}\n\nReply here if the status looks wrong or you need help resolving it.",
                'body_html' => '<p>Hi {owner_name},</p><p>A billing item needs review for <strong>{workspace_name}</strong>: {billing_status}.</p><p><a href="{action_url}">Review billing</a></p><p>Reply here if the status looks wrong or you need help resolving it.</p>',
            ],
            [
                'key' => 'failed_payment_review',
                'name' => 'Platform Ops - Failed Payment Review',
                'subject' => 'Payment needs attention for {workspace_name}',
                'description' => 'Payment recovery note with checkout and support paths.',
                'variables' => ['owner_name', 'workspace_name', 'payment_reference', 'checkout_url', 'support_url'],
                'tags' => ['platform_ops_owner_helpline', 'workspace_owner', 'billing'],
                'body_text' => "Hi {owner_name},\n\nThe latest payment attempt for {workspace_name} needs attention. Reference: {payment_reference}.\n\nContinue payment: {checkout_url}\n\nIf the payment should have completed, reply here or contact support: {support_url}",
                'body_html' => '<p>Hi {owner_name},</p><p>The latest payment attempt for <strong>{workspace_name}</strong> needs attention. Reference: {payment_reference}.</p><p><a href="{checkout_url}">Continue payment</a></p><p>If the payment should have completed, reply here or <a href="{support_url}">contact support</a>.</p>',
            ],
            [
                'key' => 'low_token_warning',
                'name' => 'Platform Ops - Low AI Credit Warning',
                'subject' => '{workspace_name} AI Credit balance is low',
                'description' => 'Low AI Credit balance warning before assistance is interrupted.',
                'variables' => ['owner_name', 'workspace_name', 'available_tokens', 'top_up_url', 'support_url'],
                'tags' => ['platform_ops_owner_helpline', 'workspace_owner', 'tokens'],
                'body_text' => "Hi {owner_name},\n\n{workspace_name} has {available_tokens} AI Credits available. Top up soon so AI assistance keeps running without interruption.\n\nTop up AI Credits: {top_up_url}\n\nReply here if you want help choosing the right credit level.",
                'body_html' => '<p>Hi {owner_name},</p><p><strong>{workspace_name}</strong> has {available_tokens} AI Credits available. Top up soon so AI assistance keeps running without interruption.</p><p><a href="{top_up_url}">Top up AI Credits</a></p><p>Reply here if you want help choosing the right credit level.</p>',
            ],
            [
                'key' => 'channel_setup_reminder',
                'name' => 'Platform Ops - Channel Setup Reminder',
                'subject' => 'Connect email for {workspace_name}',
                'description' => 'Owner reminder for workspaces missing email channel setup.',
                'variables' => ['owner_name', 'workspace_name', 'setup_url', 'support_url'],
                'tags' => ['platform_ops_owner_helpline', 'workspace_owner', 'channel_setup'],
                'body_text' => "Hi {owner_name},\n\n{workspace_name} is active, but email is not connected yet. Connect email so inbox, replies, and customer follow-up can run reliably.\n\nConnect email: {setup_url}\n\nIf you want us to check the setup with you, reply here.",
                'body_html' => '<p>Hi {owner_name},</p><p><strong>{workspace_name}</strong> is active, but email is not connected yet. Connect email so inbox, replies, and customer follow-up can run reliably.</p><p><a href="{setup_url}">Connect email</a></p><p>If you want us to check the setup with you, reply here.</p>',
            ],
            [
                'key' => 'workspace_suspension_notice',
                'name' => 'Platform Ops - Workspace Suspension Notice',
                'subject' => '{workspace_name} access needs review',
                'description' => 'Account access notice with a direct support path.',
                'variables' => ['owner_name', 'workspace_name', 'reason', 'support_url'],
                'tags' => ['platform_ops_owner_helpline', 'workspace_owner', 'support'],
                'body_text' => "Hi {owner_name},\n\nAccess for {workspace_name} needs review: {reason}.\n\nReview next steps: {support_url}\n\nReply here if you believe access should already be restored.",
                'body_html' => '<p>Hi {owner_name},</p><p>Access for <strong>{workspace_name}</strong> needs review: {reason}.</p><p><a href="{support_url}">Review next steps</a></p><p>Reply here if you believe access should already be restored.</p>',
            ],
            [
                'key' => 'setup_link_resend',
                'name' => 'Platform Ops - Setup Link Resend',
                'subject' => 'Your setup link for {workspace_name}',
                'description' => 'Manual setup or login recovery message for a workspace owner.',
                'variables' => ['owner_name', 'workspace_name', 'setup_url', 'reset_url', 'support_url'],
                'tags' => ['platform_ops_owner_helpline', 'workspace_owner', 'setup'],
                'body_text' => "Hi {owner_name},\n\nHere is the setup link for {workspace_name}: {setup_url}\n\nIf you need to reset your password first, use this link: {reset_url}\n\nReply here if either link does not work.",
                'body_html' => '<p>Hi {owner_name},</p><p>Here is the setup link for <strong>{workspace_name}</strong>: <a href="{setup_url}">continue setup</a>.</p><p>If you need to reset your password first, use this link: <a href="{reset_url}">reset password</a>.</p><p>Reply here if either link does not work.</p>',
            ],
            [
                'key' => 'owner_problem_followup',
                'name' => 'Platform Ops - Owner Problem Follow-up',
                'subject' => 'Following up on {workspace_name}',
                'description' => 'Follow-up for a problem reported by a workspace owner.',
                'variables' => ['owner_name', 'workspace_name', 'issue_summary', 'recommended_action', 'support_url'],
                'tags' => ['platform_ops_owner_helpline', 'workspace_owner', 'support'],
                'body_text' => "Hi {owner_name},\n\nI reviewed the issue for {workspace_name}: {issue_summary}.\n\nRecommended next step: {recommended_action}\n\nYou can reply here or open support: {support_url}",
                'body_html' => '<p>Hi {owner_name},</p><p>I reviewed the issue for <strong>{workspace_name}</strong>: {issue_summary}</p><p><strong>Recommended next step:</strong> {recommended_action}</p><p>You can reply here or <a href="{support_url}">open support</a>.</p>',
            ],
            [
                'key' => 'owner_support_resolution',
                'name' => 'Platform Ops - Owner Support Resolution',
                'subject' => 'Resolved: {workspace_name} support update',
                'description' => 'Resolution note for owner support requests.',
                'variables' => ['owner_name', 'workspace_name', 'resolution_summary', 'next_best_action', 'support_url'],
                'tags' => ['platform_ops_owner_helpline', 'workspace_owner', 'support'],
                'body_text' => "Hi {owner_name},\n\nThis is resolved for {workspace_name}: {resolution_summary}.\n\nNext best action: {next_best_action}\n\nReply here if anything still looks off, or reopen support: {support_url}",
                'body_html' => '<p>Hi {owner_name},</p><p>This is resolved for <strong>{workspace_name}</strong>: {resolution_summary}</p><p><strong>Next best action:</strong> {next_best_action}</p><p>Reply here if anything still looks off, or <a href="{support_url}">reopen support</a>.</p>',
            ],
            [
                'key' => 'support_escalation',
                'name' => 'Platform Ops - Support Escalation',
                'subject' => 'Support escalation: {workspace_name}',
                'description' => 'Internal escalation summary for support/admin follow-through.',
                'variables' => ['workspace_name', 'owner_email', 'issue_summary', 'recommended_action', 'operator_action_url'],
                'tags' => ['platform_ops_owner_helpline', 'workspace_owner', 'support'],
                'body_text' => "Workspace: {workspace_name}\nOwner: {owner_email}\nIssue: {issue_summary}\nRecommended action: {recommended_action}\nOpen operator action: {operator_action_url}",
                'body_html' => '<p><strong>Workspace:</strong> {workspace_name}</p><p><strong>Owner:</strong> {owner_email}</p><p><strong>Issue:</strong> {issue_summary}</p><p><strong>Recommended action:</strong> {recommended_action}</p><p><a href="{operator_action_url}">Open operator action</a></p>',
            ],
        ];
    }

    private function workflowTemplateDefinitions(): array
    {
        return [
            ['key' => 'platform_ops_stuck_onboarding_review', 'name' => 'Platform Ops - Stuck Onboarding Review', 'description' => 'Review stuck onboarding workspaces and draft owner nudges.', 'trigger_config' => ['type' => 'scheduled_review', 'cadence' => 'daily'], 'conditions' => [['field' => 'operational_score', 'operator' => 'less_than', 'value' => 80]], 'actions' => [['type' => 'send_email', 'template_query' => ['intent_key' => 'platform_ops_stuck_onboarding_review', 'preferred_template_key' => 'onboarding_recovery', 'purpose' => 'onboarding_recovery', 'tone' => 'helpful', 'lifecycle_stage' => 'setup', 'audience' => 'workspace_owner', 'required_variables' => ['owner_name', 'workspace_name', 'next_action_url']], 'subject' => 'Finish setup for {workspace_name}', 'body' => 'Hi {owner_name}, your workspace is nearly ready. Finish setup here: {next_action_url}'], ['type' => 'create_task', 'title' => 'Review stuck onboarding workspace'], ['type' => 'send_in_app_notification', 'title' => 'Workspace needs onboarding recovery', 'message' => 'A workspace is stuck in onboarding and needs review.']], 'variables' => ['workspace_name', 'owner_email', 'owner_name', 'next_action_url']],
            ['key' => 'platform_ops_billing_risk_review', 'name' => 'Platform Ops - Billing Risk Review', 'description' => 'Create review tasks for failed payments, past-due subscriptions, and low AI Credit balances.', 'trigger_config' => ['type' => 'billing_signal'], 'conditions' => [['field' => 'plan_status', 'operator' => 'in', 'value' => ['trialing', 'past_due']]], 'actions' => [['type' => 'send_email', 'template_query' => ['intent_key' => 'platform_ops_billing_risk_review', 'preferred_template_key' => 'billing_follow_up', 'purpose' => 'billing_follow_up', 'tone' => 'direct', 'lifecycle_stage' => 'billing_risk', 'audience' => 'workspace_owner', 'required_variables' => ['owner_name', 'workspace_name', 'billing_status', 'action_url']], 'subject' => 'Billing review needed for {workspace_name}', 'body' => 'Hi {owner_name}, billing needs review for {workspace_name}: {billing_status}. Review here: {action_url}'], ['type' => 'create_task', 'title' => 'Review billing risk for {workspace_name}']], 'variables' => ['workspace_name', 'plan_status', 'available_tokens', 'owner_name', 'billing_status', 'action_url']],
            ['key' => 'platform_ops_security_review', 'name' => 'Platform Ops - Security Review', 'description' => 'Review Super Admin security settings and recent operator actions.', 'trigger_config' => ['type' => 'scheduled_review', 'cadence' => 'weekly'], 'conditions' => [], 'actions' => [['type' => 'create_task', 'title' => 'Review operator audit and Super Admin 2FA settings'], ['type' => 'send_in_app_notification', 'title' => 'Security review due', 'message' => 'Review Super Admin security settings and recent operator actions.']], 'variables' => ['operator_action_count', 'security_setting']],
        ];
    }

    private function platformEmailMatchMetadata(string $key): array
    {
        $map = [
            'owner_welcome_setup' => ['purposes' => ['welcome', 'setup'], 'workflow_intents' => ['owner_welcome_setup'], 'lifecycle_stages' => ['setup'], 'audiences' => ['workspace_owner'], 'tones' => ['welcoming', 'helpful'], 'required_variables' => ['owner_name', 'workspace_name', 'setup_url', 'support_url']],
            'onboarding_recovery' => ['purposes' => ['onboarding_recovery', 'setup_reminder'], 'workflow_intents' => ['platform_ops_stuck_onboarding_review'], 'lifecycle_stages' => ['setup', 'stuck_onboarding'], 'audiences' => ['workspace_owner'], 'tones' => ['helpful', 'direct'], 'required_variables' => ['owner_name', 'workspace_name', 'next_action', 'next_action_url', 'support_url']],
            'billing_follow_up' => ['purposes' => ['billing_follow_up', 'payment_reminder'], 'workflow_intents' => ['platform_ops_billing_risk_review'], 'lifecycle_stages' => ['billing_risk', 'past_due'], 'audiences' => ['workspace_owner'], 'tones' => ['direct', 'helpful'], 'required_variables' => ['owner_name', 'workspace_name', 'billing_status', 'action_url', 'support_url']],
            'failed_payment_review' => ['purposes' => ['failed_payment', 'payment_reminder'], 'workflow_intents' => ['platform_ops_billing_risk_review'], 'lifecycle_stages' => ['payment_failed'], 'audiences' => ['workspace_owner'], 'tones' => ['direct', 'helpful'], 'required_variables' => ['owner_name', 'workspace_name', 'payment_reference', 'checkout_url', 'support_url']],
            'low_token_warning' => ['purposes' => ['token_warning', 'billing_follow_up'], 'workflow_intents' => ['platform_ops_billing_risk_review'], 'lifecycle_stages' => ['low_tokens'], 'audiences' => ['workspace_owner'], 'tones' => ['operational', 'direct'], 'required_variables' => ['owner_name', 'workspace_name', 'available_tokens', 'top_up_url', 'support_url']],
            'channel_setup_reminder' => ['purposes' => ['channel_setup', 'setup_reminder'], 'workflow_intents' => ['channel_setup_reminder'], 'lifecycle_stages' => ['setup'], 'audiences' => ['workspace_owner'], 'tones' => ['helpful', 'operational'], 'required_variables' => ['owner_name', 'workspace_name', 'setup_url', 'support_url']],
            'workspace_suspension_notice' => ['purposes' => ['suspension_notice', 'support'], 'workflow_intents' => ['platform_ops_billing_risk_review'], 'lifecycle_stages' => ['suspended'], 'audiences' => ['workspace_owner'], 'tones' => ['direct', 'supportive'], 'required_variables' => ['owner_name', 'workspace_name', 'reason', 'support_url']],
            'setup_link_resend' => ['purposes' => ['setup_link', 'setup_reminder'], 'workflow_intents' => ['platform_ops_stuck_onboarding_review'], 'lifecycle_stages' => ['setup'], 'audiences' => ['workspace_owner'], 'tones' => ['helpful'], 'required_variables' => ['owner_name', 'workspace_name', 'setup_url', 'reset_url', 'support_url']],
            'owner_problem_followup' => ['purposes' => ['support_follow_up'], 'workflow_intents' => ['support_follow_up_escalation'], 'lifecycle_stages' => ['support_open'], 'audiences' => ['workspace_owner'], 'tones' => ['helpful', 'clear'], 'required_variables' => ['owner_name', 'workspace_name', 'issue_summary', 'recommended_action', 'support_url']],
            'owner_support_resolution' => ['purposes' => ['resolution_check', 'support_follow_up'], 'workflow_intents' => ['support_follow_up_escalation'], 'lifecycle_stages' => ['support_resolved'], 'audiences' => ['workspace_owner'], 'tones' => ['helpful', 'clear'], 'required_variables' => ['owner_name', 'workspace_name', 'resolution_summary', 'next_best_action', 'support_url']],
            'support_escalation' => ['purposes' => ['support_escalation'], 'workflow_intents' => ['support_follow_up_escalation'], 'lifecycle_stages' => ['support_escalated'], 'audiences' => ['operator'], 'tones' => ['operational'], 'required_variables' => ['workspace_name', 'owner_email', 'issue_summary', 'recommended_action', 'operator_action_url']],
        ];

        return $map[$key] ?? ['purposes' => ['platform_ops'], 'workflow_intents' => [$key], 'lifecycle_stages' => ['operations'], 'audiences' => ['operator'], 'tones' => ['operational'], 'required_variables' => []];
    }

    private function platformWorkflowRecipeMetadata(string $key): array
    {
        $map = [
            'platform_ops_stuck_onboarding_review' => ['intent_key' => $key, 'expected_outcome' => 'recover stuck onboarding workspaces', 'audience' => 'workspace owner', 'safety_level' => 'approval_recommended', 'recommended_template_query' => ['purpose' => 'onboarding_recovery', 'tone' => 'helpful', 'lifecycle_stage' => 'setup', 'audience' => 'workspace_owner'], 'stop_conditions' => ['onboarding_completed', 'email_replied', 'manual_pause']],
            'platform_ops_billing_risk_review' => ['intent_key' => $key, 'expected_outcome' => 'resolve billing risk before service interruption', 'audience' => 'workspace owner', 'safety_level' => 'approval_recommended', 'recommended_template_query' => ['purpose' => 'billing_follow_up', 'tone' => 'direct', 'lifecycle_stage' => 'billing_risk', 'audience' => 'workspace_owner'], 'stop_conditions' => ['payment_resolved', 'email_replied', 'manual_pause']],
            'platform_ops_security_review' => ['intent_key' => $key, 'expected_outcome' => 'keep Super Admin controls reviewed', 'audience' => 'platform operator', 'safety_level' => 'task_only', 'recommended_template_query' => ['purpose' => 'support_escalation', 'tone' => 'operational', 'lifecycle_stage' => 'security_review', 'audience' => 'operator'], 'stop_conditions' => ['review_completed', 'manual_pause']],
        ];

        return $map[$key] ?? ['intent_key' => $key, 'expected_outcome' => 'complete platform operations follow-through', 'audience' => 'platform operator', 'safety_level' => 'approval_recommended', 'recommended_template_query' => [], 'stop_conditions' => ['manual_pause']];
    }

    private function workflowRecommendations(): array
    {
        return array_map(static fn(array $definition): array => [
            'template_id' => 0,
            'name' => $definition['name'],
            'category' => 'platform_ops',
            'reason' => $definition['description'],
            'impact' => 'high',
            'effort' => 'low',
        ], $this->workflowTemplateDefinitions());
    }
}
