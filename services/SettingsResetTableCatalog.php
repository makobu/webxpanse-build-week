<?php

namespace CRM\Services;

use CRM\Database;

class SettingsResetTableCatalog
{
    private const PROTECTED_TABLES = [
        'users',
        'roles',
        'permissions',
        'role_permissions',
        'user_roles',
    ];

    private const BUSINESS_CONTEXT_TABLES = [
        'email_tracking',
        'email_queue',
        'emails',
        'whatsapp_queue',
        'whatsapp_messages',
        'sms_queue',
        'sms_messages',
        'deal_line_items',
        'deal_automation_audit',
        'deals',
        'notifications',
        'task_subtasks',
        'tasks',
        'communications',
        'conversation_threads',
        'activities',
        'contact_custom_data',
        'contacts',
        'companies',
        'company_profile',
        'products',
        'invoice_line_items',
        'invoice_activity_log',
        'invoice_status_history',
        'invoice_delivery_events',
        'invoice_delivery_log',
        'invoices',
        'events',
        'idea_validation_context',
        'beginner_budget',
        'notes',
        'documents',
        'reports',
        'report_executions',
        'target_reminders',
        'target_advice',
        'target_milestones',
        'targets',
        'finance_journal_entries',
        'finance_transactions',
        'finance_statement_snapshots',
        'finance_snapshots',
        'finance_budget_lines',
        'finance_budgets',
        'finance_expenses',
        'finance_recurring_expenses',
        'finance_owner_equity_profiles',
        'finance_vendors',
        'user_strategy_snapshots',
        'user_strategy_profiles',
        'startup_journey_artifacts',
        'startup_journey_stage_events',
        'startup_journey_stage_responses',
        'startup_journeys',
        'founder_weekly_review_commitments',
        'founder_weekly_reviews',
        'founder_first_customer_sprints',
    ];

    private const AUTOMATION_RUNTIME_TABLES = [
        'form_submissions',
        'forms',
        'workflow_retry_queue',
        'workflow_node_runs',
        'workflow_queue',
        'workflow_variant_results',
        'workflow_variants',
        'workflow_migration_log',
        'workflow_branches',
        'workflow_exclusions',
        'workflow_executions',
        'scheduled_workflow_actions',
        'workflow_automation_proposals',
        'workflows',
        'tag_assignments',
        'tags',
        'campaign_queue',
        'campaign_step_executions',
        'campaign_enrollments',
        'campaign_audience_snapshots',
        'campaign_rate_limits',
        'campaign_steps',
        'campaigns',
        'nurture_touchpoints',
        'nurture_enrollments',
        'commercial_automation_idempotency',
        'commercial_automation_approvals',
        'commercial_automation_runs',
        'guided_demo_action_runs',
        'guided_demo_events',
        'guided_demo_plugin_installs',
        'guided_demo_package_intents',
        'guided_demo_sessions',
        'meeting_bot_runs',
        'meeting_note_taker_runs',
        'email_fetch_log_details',
        'email_fetch_log',
        'email_digest_log',
        'marketing_execution_attempts',
        'marketing_live_execution_events',
        'marketing_execution_queue',
        'marketing_tracking_events',
        'marketing_visitor_sessions',
        'marketing_dashboard_summary_cache',
        'marketing_action_router_items',
        'marketing_planning_queue_items',
        'marketing_task_hub_action_log',
        'marketing_assistant_runs',
        'marketing_ai_creative_runs',
        'marketing_ai_media_outputs',
        'marketing_ai_media_requests',
        'marketing_ai_quality_checks',
        'marketing_ai_context_snapshots',
        'marketing_analytics_snapshots',
        'marketing_audit_events',
        'marketing_bulk_action_log',
        'marketing_cleanup_runs',
        'marketing_crm_sync_events',
        'marketing_email_campaign_runs',
        'marketing_operator_export_packs',
        'marketing_operator_readiness_checks',
        'marketing_report_exports',
    ];

    private const AI_HISTORY_TABLES = [
        'ai_usage',
        'ai_autoresponder_logs',
        'ai_autoresponder_queue',
        'email_assistant_messages',
        'email_assistant_resolutions',
        'email_assistant_action_queue',
        'email_assistant_runs',
        'email_template_learning_samples',
        'task_completion_scan_queue',
        'ai_capability_state_log',
        'ai_task_evidence',
        'ai_guidance_runs',
        'ai_decision_outcomes',
        'ai_threshold_tuning_log',
        'ai_advice_feedback',
        'ai_runtime_control_log',
        'ai_incident_state',
        'ai_operator_demonstrations',
        'ai_tenant_policy_memory',
        'ai_autonomy_eval_runs',
        'ai_action_similarity_index',
        'ai_autonomy_incidents',
        'ai_autonomy_recovery_queue',
        'ai_autonomy_operator_actions',
        'ai_cross_domain_steps',
        'ai_cross_domain_intake_events',
        'ai_cross_domain_runs',
    ];

    private const DEMO_SESSION_TABLES = [
        'demo_experience_events',
        'demo_realtime_events',
        'demo_session_entities',
        'demo_visitor_sessions',
    ];

    private const WORKSPACE_GOVERNANCE_TABLES = [
        'workspace_user_roles',
        'workspace_invite_function_assignments',
        'workspace_invites',
        'workspace_slugs',
        'billing_transactions',
        'billing_checkout_sessions',
        'workspace_ai_usage',
        'workspace_wallet_ledger',
        'workspace_wallets',
        'workspace_subscription_cycles',
        'workspace_subscriptions',
        'workspace_launch_settings',
        'workspace_memberships',
        'workspaces',
    ];

    private const PRESERVED_WORKSPACE_CONFIG_TABLES = [
        'api_keys',
        'attribution_results',
        'automation_battery_snapshots',
        'billing_customers',
        'billing_invoices',
        'billing_plan_prices',
        'billing_provider_events',
        'billing_sync_logs',
        'billing_webhook_events',
        'calendar_integrations',
        'communication_user_state',
        'custom_fields',
        'departments',
        'document_categories',
        'draft_reviews',
        'draft_templates',
        'email_integrations',
        'email_templates',
        'finance_accounts',
        'finance_cash_accounts',
        'finance_expense_categories',
        'finance_income_categories',
        'finance_income_entries',
        'finance_opening_assets',
        'finance_opening_bank_balances',
        'finance_opening_liabilities',
        'finance_opening_receivables',
        'finance_opening_setups',
        'hr_analytics_settings',
        'marketing_assets',
        'marketing_brand_profiles',
        'marketing_campaign_briefs',
        'marketing_campaign_playbooks',
        'marketing_campaign_workspaces',
        'marketing_channel_connectors',
        'marketing_consent_policies',
        'marketing_content_items',
        'marketing_guided_workflows',
        'marketing_landing_pages',
        'marketing_live_connector_secrets',
        'marketing_onboarding_state',
        'marketing_personas',
        'marketing_reusable_templates',
        'marketing_saved_views',
        'marketing_suppression_entries',
        'marketing_workbench_preferences',
        'meeting_bot_config',
        'meeting_note_taker_config',
        'mobile_auth_challenges',
        'mobile_auth_tokens',
        'nurture_profiles',
        'nurture_programs',
        'organization_functions',
        'scheduled_reports',
        'scheduled_report_runs',
        'smart_template_sets',
        'target_metric_providers',
        'user_function_assignments',
        'user_system_sessions',
        'webhooks',
        'whatsapp_assistant_authorized_numbers',
        'whatsapp_assistant_digest_log',
        'whatsapp_assistant_keepalive_log',
        'whatsapp_assistant_messages',
        'whatsapp_assistant_sessions',
        'whatsapp_migration_log',
        'workspace_ai_autoresponder_quiet_hours',
        'workspace_ai_provider_configs',
        'workspace_assistant_configs',
        'workspace_credit_lots',
        'workspace_credit_lot_ledger',
        'workspace_governance_events',
        'workspace_marketplace_activation_bundle_events',
        'workspace_marketplace_activation_bundle_state',
        'workspace_marketplace_recommendation_controls',
        'workspace_marketplace_recommendation_events',
        'workspace_marketplace_recommendation_feedback',
        'workspace_marketplace_setup_journey_events',
        'workspace_marketplace_setup_journey_steps',
        'workspace_negotiated_package_offers',
        'workspace_onboarding_nudges',
        'workspace_onboarding_state',
        'workspace_plugin_capabilities',
        'workspace_plugin_runtime_events',
        'workspace_sample_workflow_registry',
        'workspace_scoring_config',
        'workspace_security_settings',
        'workspace_skill_events',
        'workspace_skill_installs',
        'workspace_sms_channel_configs',
        'workspace_whatsapp_integrations',
    ];

    private const PRESERVED_WORKSPACE_PREFIXES = [
        'ai_',
        'billing_',
        'calendar_',
        'custom_',
        'document_',
        'draft_',
        'finance_',
        'hr_',
        'marketing_',
        'meeting_',
        'ml_',
        'mobile_auth_',
        'organization_',
        'smart_template_',
        'target_metric_',
        'user_function_',
        'user_system_',
        'webhook',
        'whatsapp_assistant_',
        'workspace_',
    ];

    /**
     * @return array<string,array<string,mixed>>
     */
    public function definitions(): array
    {
        return [
            'reset_core_data' => [
                'confirm_text' => 'RESET DATA',
                'success_prefix' => 'Workspace data and AI context reset complete.',
                'tables' => $this->resetCoreDataTables(),
                'preference_keys' => [
                    'ai_task_auto_sync_date',
                    'celebrated_milestones',
                ],
            ],
            'reset_company_context' => [
                'confirm_text' => 'RESET CONTEXT',
                'success_prefix' => 'Workspace company context reset complete.',
                'tables' => $this->resetCompanyContextTables(),
                'preference_keys' => [
                    'ai_task_auto_sync_date',
                    'celebrated_milestones',
                ],
            ],
            'reset_platform_data' => [
                'confirm_text' => 'RESET PLATFORM',
                'success_prefix' => 'Platform-wide reset complete.',
                'tables' => $this->resetPlatformDataTables(),
                'preference_keys' => [
                    'ai_task_auto_sync_date',
                    'celebrated_milestones',
                ],
                'platform_reset' => true,
            ],
        ];
    }

    /**
     * @return string[]
     */
    public function protectedTables(): array
    {
        return self::PROTECTED_TABLES;
    }

    /**
     * @return string[]
     */
    public function resetCoreDataTables(): array
    {
        return $this->uniqueTables(array_merge(
            self::BUSINESS_CONTEXT_TABLES,
            self::AUTOMATION_RUNTIME_TABLES,
            self::AI_HISTORY_TABLES
        ));
    }

    /**
     * @return string[]
     */
    public function resetCompanyContextTables(): array
    {
        return $this->uniqueTables(array_merge(
            self::BUSINESS_CONTEXT_TABLES,
            self::AI_HISTORY_TABLES
        ));
    }

    /**
     * @return string[]
     */
    public function resetPlatformDataTables(): array
    {
        return $this->uniqueTables(array_merge(
            $this->resetCoreDataTables(),
            self::DEMO_SESSION_TABLES,
            self::WORKSPACE_GOVERNANCE_TABLES
        ));
    }

    /**
     * @return string[]
     */
    public function preservedWorkspaceConfigTables(): array
    {
        return self::PRESERVED_WORKSPACE_CONFIG_TABLES;
    }

    /**
     * @return string[]
     */
    public function allResetTargetTables(): array
    {
        return $this->uniqueTables(array_merge(
            $this->resetCoreDataTables(),
            $this->resetCompanyContextTables(),
            $this->resetPlatformDataTables()
        ));
    }

    public function resetCategoryFor(string $tableName): ?string
    {
        $tableName = strtolower(trim($tableName));
        $categories = [
            'business_context' => self::BUSINESS_CONTEXT_TABLES,
            'automation_runtime' => self::AUTOMATION_RUNTIME_TABLES,
            'ai_history' => self::AI_HISTORY_TABLES,
            'demo_session' => self::DEMO_SESSION_TABLES,
            'workspace_governance' => self::WORKSPACE_GOVERNANCE_TABLES,
        ];

        foreach ($categories as $category => $tables) {
            if (in_array($tableName, $tables, true)) {
                return $category;
            }
        }

        return null;
    }

    public function preservedCategoryFor(string $tableName): ?string
    {
        $tableName = strtolower(trim($tableName));
        if (in_array($tableName, self::PRESERVED_WORKSPACE_CONFIG_TABLES, true)) {
            return 'workspace_config';
        }

        foreach (self::PRESERVED_WORKSPACE_PREFIXES as $prefix) {
            if (str_starts_with($tableName, $prefix)) {
                return 'workspace_family:' . $prefix;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function uncategorizedWorkspaceTables(): array
    {
        $rows = Database::query(
            "SELECT table_name
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND column_name = 'workspace_id'
             ORDER BY table_name"
        );

        $uncategorized = [];
        foreach ($rows as $row) {
            $tableName = strtolower((string) ($row['table_name'] ?? ''));
            if ($tableName === '') {
                continue;
            }
            if ($this->resetCategoryFor($tableName) !== null || $this->preservedCategoryFor($tableName) !== null) {
                continue;
            }
            $uncategorized[] = $tableName;
        }

        return $uncategorized;
    }

    /**
     * @param string[] $tables
     * @return string[]
     */
    private function uniqueTables(array $tables): array
    {
        return array_values(array_unique(array_map(
            static fn($table): string => strtolower(trim((string) $table)),
            $tables
        )));
    }
}
