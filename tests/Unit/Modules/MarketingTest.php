<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Session;
use CRM\Services\AIService;
use CRM\Services\AIRuntimeControlService;
use CRM\Services\MarketingPageContextService;
use CRM\Services\MarketingPageGuideUi;
use CRM\Services\MarketingPageQualityMatrix;
use CRM\Services\MarketingUi;
use CRM\Services\LandingPageDesignService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;
use RuntimeException;

class MarketingTest extends DatabaseTestCase
{
    use EndpointHarness;

    private Marketing $marketing;
    private int $userId;
    private int $workspaceId;
    private int $membershipId;
    private string $workspaceUuid;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'marketing', NOW())",
            [uniqid('marketing-user-', true), 'marketing@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), ?, ?, 'active', 'trialing', ?)",
            ['Marketing Tenant Workspace', 'marketing-tenant-' . uniqid(), $this->userId]
        );
        $this->workspaceId = (int) Database::lastInsertId();
        $workspace = Database::queryOne("SELECT uuid FROM workspaces WHERE id = ?", [$this->workspaceId]);
        $this->workspaceUuid = (string) ($workspace['uuid'] ?? '');
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'owner', 'active', 1, NOW(), ?)",
            [$this->workspaceId, $this->userId, $this->userId]
        );
        $this->membershipId = (int) Database::lastInsertId();

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        Session::set('user_id', $this->userId);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        // This suite exercises routes across all three top-level marketing
        // products, so install each dedicated runtime plugin explicitly.
        (new WorkspaceSkillCatalogService())->syncDefinitions();
        foreach ([
            WorkspaceSkillCatalogService::PLUGIN_DESIGN,
            WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA,
            WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
        ] as $pluginKey) {
            Database::execute(
                "INSERT INTO workspace_skill_installs
                 (workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at)
                 VALUES (?, ?, 'installed', '{}', ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, disabled_at = NULL",
                [$this->workspaceId, $pluginKey, $this->userId, $this->userId]
            );
        }
        WorkspaceSkillCatalogService::resetRuntimeCaches();

        $this->marketing = new Marketing();
    }

    public function testMigrationCreatesMarketingTableAndPermissions(): void
    {
        $this->assertTrue(Database::tableExists('marketing_content_items'));
        $this->assertTrue(Database::tableExists('marketing_onboarding_state'));
        $this->assertTrue(Database::tableExists('marketing_saved_views'));
        $this->assertTrue(Database::tableExists('marketing_workbench_preferences'));
        $this->assertTrue(Database::tableExists('marketing_bulk_action_log'));
        $this->assertTrue(Database::tableExists('marketing_landing_page_publications'));
        $this->assertTrue(Database::tableExists('marketing_email_campaign_runs'));
        $this->assertTrue(Database::tableExists('marketing_channel_export_bundles'));
        $this->assertTrue(Database::tableExists('marketing_ai_quality_checks'));
        $this->assertTrue(Database::tableExists('marketing_creative_briefs'));
        $this->assertTrue(Database::tableExists('marketing_asset_requests'));
        $this->assertTrue(Database::tableExists('marketing_asset_usage'));
        $this->assertTrue(Database::tableExists('marketing_media_files'));
        $this->assertTrue(Database::tableExists('marketing_media_audit_events'));
        $this->assertTrue(Database::tableExists('marketing_audience_segments'));
        $this->assertTrue(Database::tableExists('marketing_segment_rules'));
        $this->assertTrue(Database::tableExists('marketing_segment_snapshots'));
        $this->assertTrue(Database::tableExists('marketing_audience_recommendations'));
        $this->assertTrue(Database::tableExists('marketing_audience_activations'));
        $this->assertTrue(Database::tableExists('marketing_persona_segment_map'));
        $this->assertTrue(Database::tableExists('marketing_campaign_playbooks'));
        $this->assertTrue(Database::tableExists('marketing_campaign_playbook_stages'));
        $this->assertTrue(Database::tableExists('marketing_playbook_application_runs'));
        $this->assertTrue(Database::tableExists('marketing_consent_policies'));
        $this->assertTrue(Database::tableExists('marketing_suppression_entries'));
        $this->assertTrue(Database::tableExists('marketing_content_dependencies'));
        $this->assertTrue(Database::tableExists('marketing_content_production_events'));
        $this->assertTrue(Database::tableExists('marketing_calendar_templates'));
        $this->assertTrue(Database::tableExists('marketing_launch_readiness_templates'));
        $this->assertTrue(Database::tableExists('marketing_launch_readiness_reviews'));
        $this->assertTrue(Database::tableExists('marketing_launch_control_records'));
        $this->assertTrue(Database::tableExists('marketing_reusable_templates'));
        $this->assertTrue(Database::tableExists('marketing_guided_workflows'));
        $this->assertTrue(Database::tableExists('marketing_guided_workflow_events'));
        $this->assertTrue(Database::tableExists('marketing_task_hub_preferences'));
        $this->assertTrue(Database::tableExists('marketing_task_hub_action_log'));
        $this->assertTrue(Database::tableExists('marketing_campaign_workspaces'));
        $this->assertTrue(Database::tableExists('marketing_journeys'));
        $this->assertTrue(Database::tableExists('marketing_journey_steps'));
        $this->assertTrue(Database::tableExists('marketing_journey_drafts'));
        $this->assertTrue(Database::tableExists('marketing_ai_media_requests'));
        $this->assertTrue(Database::tableExists('marketing_ai_media_outputs'));
        $this->assertTrue(Database::tableExists('marketing_channel_connectors'));
        $this->assertTrue(Database::tableExists('marketing_channel_connector_test_runs'));
        $this->assertTrue(Database::tableExists('marketing_channel_connector_readiness_reviews'));
        $this->assertTrue(Database::tableExists('marketing_execution_queue'));
        $this->assertTrue(Database::tableExists('marketing_execution_attempts'));
        $this->assertTrue(Database::tableExists('marketing_execution_approvals'));
        $this->assertTrue(Database::tableExists('marketing_live_execution_policies'));
        $this->assertTrue(Database::tableExists('marketing_live_execution_events'));
        $this->assertTrue(Database::tableExists('marketing_live_email_handoffs'));
        $this->assertTrue(Database::tableExists('marketing_live_email_handoff_events'));
        $this->assertTrue(Database::tableExists('marketing_live_channel_handoffs'));
        $this->assertTrue(Database::columnExists('marketing_live_channel_handoffs', 'worker_run_id'));
        $this->assertTrue(Database::tableExists('marketing_live_email_worker_runs'));
        $this->assertTrue(Database::tableExists('marketing_live_channel_worker_runs'));
        $this->assertTrue(Database::tableExists('marketing_live_execution_launcher_runs'));
        $this->assertTrue(Database::tableExists('marketing_live_setup_steps'));
        $this->assertTrue(Database::tableExists('marketing_live_rehearsal_runs'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_rehearsal_run_id'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_rehearsal_evidence_json'));
        $this->assertTrue(Database::tableExists('marketing_live_scheduler_validations'));
        $this->assertTrue(Database::tableExists('marketing_live_worker_health_checks'));
        $this->assertTrue(Database::tableExists('marketing_live_worker_leases'));
        $this->assertTrue(Database::tableExists('marketing_live_worker_schedules'));
        $this->assertTrue(Database::tableExists('marketing_live_outcome_sync_runs'));
        $this->assertTrue(Database::tableExists('marketing_live_orchestration_runs'));
        $this->assertTrue(Database::columnExists('marketing_live_worker_schedules', 'max_handoffs_per_run'));
        $this->assertTrue(Database::columnExists('marketing_live_worker_schedules', 'emergency_paused_at'));
        $this->assertTrue(Database::tableExists('marketing_live_email_delivery_proofs'));
        $this->assertTrue(Database::tableExists('marketing_live_channel_delivery_proofs'));
        $this->assertTrue(Database::columnExists('marketing_live_channel_delivery_proofs', 'worker_run_id'));
        $this->assertTrue(Database::tableExists('marketing_live_email_unsubscribe_tokens'));
        $this->assertTrue(Database::tableExists('marketing_live_email_unsubscribe_events'));
        $this->assertTrue(Database::tableExists('marketing_live_webhook_attempts'));
        $this->assertTrue(Database::tableExists('marketing_campaign_launch_checklists'));
        $this->assertTrue(Database::tableExists('marketing_campaign_launch_checklist_items'));
        $this->assertTrue(Database::tableExists('marketing_operator_export_packs'));
        $this->assertTrue(Database::tableExists('marketing_campaign_creative_requirements'));
        $this->assertTrue(Database::tableExists('marketing_crm_lifecycle_insights'));
        $this->assertTrue(Database::tableExists('marketing_execution_action_snapshots'));
        $this->assertTrue(Database::tableExists('marketing_decision_center_items'));
        $this->assertTrue(Database::tableExists('marketing_campaign_copilot_runs'));
        $this->assertTrue(Database::tableExists('marketing_media_production_work_items'));
        $this->assertTrue(Database::tableExists('marketing_dashboard_summary_cache'));
        $this->assertTrue(Database::tableExists('marketing_action_router_items'));
        $this->assertTrue(Database::tableExists('marketing_ai_context_snapshots'));
        $this->assertTrue(Database::tableExists('marketing_relationship_graph_edges'));
        $this->assertTrue(Database::tableExists('marketing_campaign_budgets'));
        $this->assertTrue(Database::tableExists('marketing_channel_spend'));
        $this->assertTrue(Database::tableExists('marketing_roi_targets'));
        $this->assertTrue(Database::tableExists('marketing_experiments'));
        $this->assertTrue(Database::tableExists('marketing_experiment_variants'));
        $this->assertTrue(Database::tableExists('marketing_experiment_results'));
        $this->assertTrue(Database::tableExists('marketing_report_exports'));
        $this->assertTrue(Database::tableExists('marketing_scheduled_report_drafts'));
        $this->assertTrue(Database::tableExists('marketing_operator_readiness_checks'));
        $this->assertTrue(Database::tableExists('marketing_visitor_sessions'));
        $this->assertTrue(Database::tableExists('marketing_tracking_events'));
        $this->assertTrue(Database::tableExists('marketing_landing_page_views'));
        $this->assertTrue(Database::tableExists('marketing_cta_clicks'));
        $this->assertTrue(Database::tableExists('marketing_conversion_events'));
        $this->assertTrue(Database::tableExists('marketing_attribution_touchpoints'));
        $this->assertTrue(Database::tableExists('marketing_attribution_summaries'));
        $this->assertTrue(Database::tableExists('marketing_conversion_goals'));
        $this->assertTrue(Database::tableExists('marketing_conversion_goal_links'));
        $this->assertTrue(Database::tableExists('marketing_handoff_rules'));
        $this->assertTrue(Database::tableExists('marketing_lead_handoffs'));
        $this->assertTrue(Database::tableExists('marketing_crm_sync_events'));
        $this->assertTrue(Database::tableExists('marketing_handoff_feedback_events'));
        $this->assertTrue(Database::columnExists('marketing_lead_handoffs', 'crm_activity_id'));
        $this->assertTrue(Database::columnExists('marketing_lead_handoffs', 'notification_id'));
        $this->assertTrue(Database::columnExists('marketing_lead_handoffs', 'crm_sync_status'));
        $this->assertTrue(Database::columnExists('marketing_lead_handoffs', 'accepted_at'));
        $this->assertTrue(Database::columnExists('marketing_lead_handoffs', 'lost_at'));
        $this->assertTrue(Database::columnExists('marketing_lead_handoffs', 'sales_outcome'));
        $this->assertTrue(Database::columnExists('marketing_lead_handoffs', 'feedback_reason'));
        $this->assertTrue(Database::columnExists('marketing_lead_handoffs', 'feedback_note'));
        $this->assertTrue(Database::columnExists('marketing_lead_handoffs', 'feedback_by'));
        $this->assertTrue(Database::columnExists('marketing_lead_handoffs', 'feedback_at'));
        $this->assertTrue(Database::columnExists('marketing_campaign_briefs', 'audience_segment_id'));
        $this->assertTrue(Database::columnExists('marketing_campaign_briefs', 'campaign_playbook_id'));
        $this->assertTrue(Database::columnExists('marketing_campaign_playbooks', 'playbook_type'));
        $this->assertTrue(Database::columnExists('marketing_campaign_playbooks', 'default_content_plan_json'));
        $this->assertTrue(Database::columnExists('marketing_campaign_playbooks', 'last_applied_at'));
        $this->assertTrue(Database::columnExists('marketing_content_items', 'production_stage'));
        $this->assertTrue(Database::columnExists('marketing_content_items', 'production_due_at'));
        $this->assertTrue(Database::columnExists('marketing_content_items', 'dependency_status'));
        $this->assertTrue(Database::columnExists('marketing_content_items', 'production_score'));
        $this->assertTrue(Database::columnExists('marketing_content_items', 'next_action'));
        $this->assertTrue(Database::columnExists('marketing_calendar_milestones', 'channel'));
        $this->assertTrue(Database::columnExists('marketing_calendar_milestones', 'capacity_weight'));
        $this->assertTrue(Database::columnExists('marketing_calendar_milestones', 'conflict_status'));
        $this->assertTrue(Database::columnExists('marketing_campaign_briefs', 'launch_readiness_score'));
        $this->assertTrue(Database::columnExists('marketing_campaign_briefs', 'launch_readiness_json'));
        $this->assertTrue(Database::columnExists('marketing_campaign_briefs', 'launch_control_status'));
        $this->assertTrue(Database::columnExists('marketing_campaign_briefs', 'launch_control_json'));
        $this->assertTrue(Database::columnExists('marketing_guided_workflows', 'workflow_key'));
        $this->assertTrue(Database::columnExists('marketing_guided_workflows', 'step_state_json'));
        $this->assertTrue(Database::columnExists('marketing_guided_workflows', 'recommendation_json'));
        $this->assertTrue(Database::columnExists('marketing_task_hub_preferences', 'default_queue'));
        $this->assertTrue(Database::columnExists('marketing_task_hub_action_log', 'action_type'));
        $this->assertTrue(Database::columnExists('marketing_campaign_workspaces', 'health_score'));
        $this->assertTrue(Database::columnExists('marketing_campaign_workspaces', 'linked_summary_json'));
        $this->assertTrue(Database::columnExists('marketing_email_campaign_runs', 'audience_segment_id'));
        $this->assertTrue(Database::columnExists('marketing_email_campaign_runs', 'consent_policy_id'));
        $this->assertTrue(Database::columnExists('marketing_email_campaign_runs', 'consent_status'));
        $this->assertTrue(Database::columnExists('marketing_email_campaign_runs', 'safety_warnings_json'));
        $this->assertTrue(Database::columnExists('marketing_email_campaign_runs', 'segment_snapshot_id'));
        $this->assertTrue(Database::columnExists('marketing_email_campaign_runs', 'preheader_text'));
        $this->assertTrue(Database::columnExists('marketing_email_campaign_runs', 'unsubscribe_text'));
        $this->assertTrue(Database::columnExists('marketing_email_campaign_runs', 'readiness_warnings_json'));
        $this->assertTrue(Database::columnExists('marketing_email_campaign_runs', 'csv_export_json'));
        $this->assertTrue(Database::columnExists('marketing_email_campaign_runs', 'live_handoff_status'));
        $this->assertTrue(Database::columnExists('marketing_email_campaign_runs', 'live_handoff_json'));
        $this->assertTrue(Database::columnExists('marketing_live_email_handoffs', 'operator_status'));
        $this->assertTrue(Database::columnExists('marketing_live_email_handoffs', 'suppression_rechecked_at'));
        $this->assertTrue(Database::columnExists('marketing_live_email_handoffs', 'worker_run_id'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_suppression_result'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_consent_evidence_json'));
        $this->assertTrue(Database::columnExists('marketing_live_email_handoffs', 'consent_basis'));
        $this->assertTrue(Database::columnExists('marketing_live_email_handoffs', 'suppression_result'));
        $this->assertTrue(Database::columnExists('marketing_live_channel_handoffs', 'consent_basis'));
        $this->assertTrue(Database::columnExists('marketing_live_channel_handoffs', 'whatsapp_template_evidence_json'));
        $this->assertTrue(Database::tableExists('marketing_live_outcome_reconciliation_events'));
        $this->assertTrue(Database::tableExists('marketing_live_proof_packs'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_outcome_reconciliation_key'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_outcome_reconciliation_json'));
        $this->assertTrue(Database::columnExists('marketing_channel_export_bundles', 'audience_segment_id'));
        $this->assertTrue(Database::columnExists('marketing_channel_export_bundles', 'segment_snapshot_id'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'audience_segment_id'));
        $this->assertTrue(Database::columnExists('marketing_audience_segments', 'crm_preview_json'));
        $this->assertTrue(Database::columnExists('marketing_audience_segments', 'fit_score'));
        $this->assertTrue(Database::columnExists('marketing_audience_segments', 'fit_score_json'));
        $this->assertTrue(Database::columnExists('marketing_audience_segments', 'activation_score'));
        $this->assertTrue(Database::columnExists('marketing_audience_segments', 'activation_warnings_json'));
        $this->assertTrue(Database::columnExists('marketing_audience_segments', 'last_activation_at'));
        $this->assertTrue(Database::columnExists('marketing_audience_segments', 'stale_reason'));
        $this->assertTrue(Database::columnExists('marketing_audience_segments', 'last_intelligence_at'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'setup_status'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'readiness_score'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'health_status'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'health_score'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'health_probe_json'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'live_hourly_cap'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'live_daily_cap'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'live_cooldown_seconds'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'live_throttle_json'));
        $this->assertTrue(Database::tableExists('marketing_channel_connector_health_probes'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'setup_checklist_json'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'last_readiness_review_at'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'live_adapter_key'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'live_preflight_status'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'live_preflight_checked_at'));
        $this->assertTrue(Database::columnExists('marketing_channel_connectors', 'live_preflight_json'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_policy_snapshot_json'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_idempotency_key'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_rerun_policy'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_last_confirmed_at'));
        $this->assertTrue(Database::tableExists('marketing_live_queue_dispatch_runs'));
        $this->assertTrue(Database::columnExists('marketing_live_worker_leases', 'dispatch_run_id'));
        $this->assertTrue(Database::columnExists('marketing_live_worker_leases', 'channel_worker_run_id'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_dispatch_status'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_dispatch_run_id'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_retry_status'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_retry_count'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_max_retries'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_retry_backoff_seconds'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_retry_after'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_retry_json'));
        $this->assertTrue(Database::tableExists('marketing_live_recovery_events'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_recovery_status'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_recovery_json'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_outcome_status'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_outcome_checked_at'));
        $this->assertTrue(Database::columnExists('marketing_execution_queue', 'live_outcome_json'));
        $this->assertTrue(Database::columnExists('marketing_execution_attempts', 'connector_id'));
        $this->assertTrue(Database::columnExists('marketing_execution_attempts', 'adapter_key'));
        $this->assertTrue(Database::columnExists('marketing_execution_attempts', 'provider_reference'));
        $this->assertTrue(Database::columnExists('marketing_execution_attempts', 'failure_class'));
        $this->assertTrue(Database::columnExists('marketing_execution_attempts', 'duration_ms'));
        $this->assertTrue(Database::columnExists('marketing_execution_attempts', 'request_evidence_json'));
        $this->assertTrue(Database::columnExists('marketing_execution_attempts', 'response_evidence_json'));
        $this->assertTrue(Database::columnExists('marketing_execution_attempts', 'adapter_contract_json'));
        $this->assertTrue(Database::columnExists('marketing_live_webhook_attempts', 'adapter_key'));
        $this->assertTrue(Database::columnExists('marketing_live_channel_handoffs', 'idempotency_key'));
        $this->assertTrue(Database::columnExists('marketing_live_email_handoffs', 'idempotency_key'));
        $this->assertTrue(Database::tableExists('marketing_live_connector_secrets'));
        $this->assertTrue(Database::columnExists('marketing_live_connector_secrets', 'last_verification_status'));
        $this->assertTrue(Database::columnExists('marketing_live_connector_secrets', 'revoked_at'));
        $this->assertTrue(Database::columnExists('marketing_live_connector_secrets', 'expires_at'));
        $this->assertTrue(Database::columnExists('marketing_live_connector_secrets', 'rotation_due_at'));
        $this->assertTrue(Database::columnExists('marketing_live_connector_secrets', 'freshness_status'));
        $this->assertTrue(Database::tableExists('marketing_live_connector_secret_events'));
        $this->assertTrue(Database::columnExists('marketing_execution_attempts', 'idempotency_key'));
        $this->assertTrue(Database::columnExists('marketing_execution_attempts', 'live_confirmation_text'));
        $this->assertTrue(Database::columnExists('marketing_live_execution_policies', 'emergency_paused'));
        $this->assertTrue(Database::columnExists('marketing_live_execution_policies', 'emergency_paused_at'));
        $this->assertTrue(Database::columnExists('marketing_live_execution_policies', 'emergency_pause_reason'));
        $this->assertTrue(Database::columnExists('marketing_media_files', 'approval_status'));
        $this->assertTrue(Database::columnExists('marketing_media_files', 'expiry_date'));
        $this->assertTrue(Database::columnExists('marketing_media_files', 'license_status'));
        $this->assertTrue(Database::columnExists('marketing_assets', 'media_file_id'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'hero_media_file_id'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'social_preview_media_file_id'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'cta_media_file_id'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'section_media_json'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'gallery_media_json'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'theme_settings_json'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'cta_variants_json'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'form_blocks_json'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'draft_version_id'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'conversion_goal_id'));
        $this->assertTrue(Database::columnExists('marketing_content_items', 'conversion_goal_id'));
        $this->assertTrue(Database::columnExists('marketing_campaign_briefs', 'conversion_goal_id'));
        $this->assertTrue(Database::tableExists('marketing_landing_page_sections'));
        $this->assertTrue(Database::tableExists('marketing_landing_page_versions'));
        $this->assertTrue(Database::tableExists('marketing_design_templates'));
        $this->assertTrue(Database::tableExists('marketing_design_template_events'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'design_document_json'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'design_revision'));
        $this->assertTrue(Database::columnExists('marketing_landing_pages', 'design_validation_json'));
        $this->assertTrue(Database::tableExists('marketing_content_media'));
        $this->assertTrue(Database::tableExists('marketing_ai_creative_runs'));
        $this->assertTrue(Database::tableExists('marketing_channel_media_kits'));
        $this->assertTrue(Database::columnExists('marketing_channel_export_bundles', 'media_kit_id'));
        $this->assertTrue(Database::tableExists('marketing_recurring_rhythms'));
        $this->assertTrue(Database::tableExists('marketing_planning_queue_items'));
        $this->assertTrue(Database::tableExists('marketing_checklist_templates'));
        $this->assertTrue(Database::tableExists('marketing_audit_events'));
        $this->assertTrue(Database::tableExists('marketing_cleanup_runs'));
        $this->assertTrue(Database::columnExists('marketing_analytics_snapshots', 'campaign_roi_json'));
        $this->assertTrue(Database::columnExists('marketing_analytics_snapshots', 'content_influence_json'));
        $this->assertTrue(Database::columnExists('marketing_analytics_snapshots', 'utm_performance_json'));
        $this->assertTrue(Database::columnExists('marketing_analytics_snapshots', 'revenue_attribution_json'));

        $permissions = Database::query(
            "SELECT permission_key FROM permissions WHERE permission_key IN ('marketing.read', 'marketing.write', 'marketing.manage')"
        );
        $permissionKeys = array_column($permissions, 'permission_key');
        sort($permissionKeys);
        $this->assertSame(['marketing.manage', 'marketing.read', 'marketing.write'], $permissionKeys);

        $marketingRolePermissions = Database::query(
            "SELECT p.permission_key
             FROM roles r
             JOIN role_permissions rp ON rp.role_id = r.id AND rp.can_access = 1
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.slug = 'marketing' AND p.permission_key LIKE 'marketing.%'
             ORDER BY p.permission_key"
        );
        $this->assertSame(['marketing.read', 'marketing.write'], array_column($marketingRolePermissions, 'permission_key'));

        $promptKeys = Database::query(
            "SELECT prompt_key
             FROM ai_prompt_registry
             WHERE surface = 'marketing' AND status = 'active'
             ORDER BY prompt_key"
        );
        $this->assertSame(
            [
                'assistant_copywriter',
                'assistant_strategist',
                'brief_builder',
                'campaign_planner',
                'content_draft',
                'creative_ad_concept',
                'creative_alt_caption',
                'creative_image_prompt',
                'creative_landing_visual_direction',
                'creative_media_readiness',
                'creative_thumbnail_concept',
                'creative_video_storyboard',
                'integration_readiness',
                'landing_page_copy',
                'performance_analysis',
                'quality_review',
                'strategy_gap_analysis',
            ],
            array_column($promptKeys, 'prompt_key')
        );

        $this->assertContains('marketing', (new AIRuntimeControlService())->listSurfaces());
    }

    public function testCampaignKitPageRendersGuidedReviewFirstWorkspace(): void
    {
        $this->assertTrue(Database::tableExists('marketing_generation_runs'));
        $this->assertTrue(Database::tableExists('marketing_generation_artifacts'));

        $page = $this->runWebEndpoint('public/marketing_campaign_kit.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_campaign_kit.php');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('<h1>Campaign Kit</h1>', $body);
        $this->assertStringContainsString('Build your first campaign kit', $body);
        $this->assertStringContainsString('Generation creates staged drafts only.', $body);
        $this->assertStringNotContainsString('Fatal error', $body);
    }

    public function testMarketingPageGuideSeedRowsAreInactiveAndIdempotent(): void
    {
        $definitions = MarketingPageGuideUi::pageDefinitions();
        $keys = array_values(array_map(static fn (array $definition): string => $definition['key'], $definitions));

        foreach ($definitions as $definition) {
            $row = Database::queryOne(
                "SELECT page_key, label, video_url, is_active
                 FROM marketplace_page_explainers
                 WHERE page_key = ?",
                [$definition['key']]
            );

            $this->assertNotNull($row, $definition['key']);
            $this->assertSame($definition['key'], (string) ($row['page_key'] ?? ''));
            $this->assertSame($definition['label'], (string) ($row['label'] ?? ''));
            $this->assertNull($row['video_url']);
            $this->assertSame(0, (int) ($row['is_active'] ?? 1));
        }

        $beforeCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM marketplace_page_explainers
             WHERE page_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ")",
            $keys
        )['count'] ?? 0);
        $dashboardBefore = Database::queryOne(
            "SELECT label, video_url, is_active
             FROM marketplace_page_explainers
             WHERE page_key = 'dashboard'"
        );

        Database::execute((string) file_get_contents(__DIR__ . '/../../../database/migrations/318_seed_marketing_page_explainers.sql'));

        $afterCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM marketplace_page_explainers
             WHERE page_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ")",
            $keys
        )['count'] ?? 0);
        $dashboardAfter = Database::queryOne(
            "SELECT label, video_url, is_active
             FROM marketplace_page_explainers
             WHERE page_key = 'dashboard'"
        );

        $this->assertSame(count($definitions), $beforeCount);
        $this->assertSame($beforeCount, $afterCount);
        $this->assertSame($dashboardBefore, $dashboardAfter);
    }

    public function testMarketingGuideButtonRendersOnlyWhenPageVideoIsActive(): void
    {
        Database::execute(
            "UPDATE marketplace_page_explainers
             SET video_url = ?, is_active = 1
             WHERE page_key = ?",
            ['assets/videos/marketing-command-center-guide.mp4', 'marketing_command_center']
        );

        $dashboard = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $dashboardBody = (string) ($dashboard['body'] ?? '');

        $this->assertEndpointHealthy($dashboard, 200, 'marketing.php guide');
        $this->assertStringContainsString('Watch guide', $dashboardBody);
        $this->assertStringContainsString('data-page-guide-open="marketing_command_center"', $dashboardBody);
        $this->assertStringContainsString('How to use Marketing Command Center', $dashboardBody);
        $this->assertStringContainsString('marketing-command-center-guide.mp4', $dashboardBody);

        $context = $this->runWebEndpoint('public/marketing_context.php', $this->webSession('owner'), ['method' => 'GET']);
        $contextBody = (string) ($context['body'] ?? '');

        $this->assertEndpointHealthy($context, 200, 'marketing_context.php no guide');
        $this->assertStringNotContainsString('data-page-guide-open="marketing_context"', $contextBody);
        $this->assertStringNotContainsString('Watch guide', $contextBody);
    }

    public function testPhaseThirtyFiveAudienceSegmentsPreviewSnapshotsAndLinkedPlanningRecords(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, lead_source, stage, engagement_score)
             VALUES (?, UUID(), 'Ada', 'Buyer', 'ada@example.com', 'form', 'qualified', 80),
                    (?, UUID(), 'Ben', 'Cold', 'ben@example.com', 'referral', 'new', 10)",
            [$this->workspaceId, $this->workspaceId]
        );

        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Qualified inbound audience',
            'description' => 'Contacts that are qualified and engaged.',
            'status' => 'active',
            'source_scope' => 'contacts',
            'rule_logic' => 'all',
            'rules' => [
                ['source_type' => 'contact', 'field_key' => 'stage', 'operator' => 'equals', 'value_text' => 'qualified'],
                ['source_type' => 'contact', 'field_key' => 'engagement_score', 'operator' => 'greater_than', 'value_text' => '50'],
            ],
            'created_by' => $this->userId,
        ]);

        $segment = $this->marketing->getAudienceSegment($segmentId);
        $this->assertSame('Qualified inbound audience', (string) ($segment['name'] ?? ''));
        $this->assertCount(2, (array) ($segment['rules'] ?? []));

        $preview = $this->marketing->previewAudienceSegment($segmentId, 10, false);
        $this->assertSame(1, (int) $preview['count']);
        $this->assertSame('ada@example.com', (string) ($preview['contacts'][0]['email'] ?? ''));

        $snapshot = $this->marketing->createAudienceSegmentSnapshot($segmentId, $this->userId);
        $this->assertSame(1, (int) ($snapshot['contact_count'] ?? 0));
        $this->assertSame([$preview['contact_ids'][0]], (array) ($snapshot['contact_ids_json'] ?? []));

        $campaignId = $this->createCampaign($this->workspaceId, 'Segmented Campaign');
        $formId = $this->createForm($this->workspaceId, 'Segmented Form');
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Segmented Brief',
            'audience' => 'Qualified inbound',
            'audience_segment_id' => $segmentId,
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ]);
        $brief = $this->marketing->getCampaignBrief($briefId);
        $this->assertSame($segmentId, (int) ($brief['audience_segment_id'] ?? 0));
        $this->assertSame('Qualified inbound audience', (string) ($brief['audience_segment_name'] ?? ''));

        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Segmented Landing',
            'headline' => 'For qualified inbound buyers',
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'audience_segment_id' => $segmentId,
            'created_by' => $this->userId,
        ]);
        $landingPage = $this->marketing->getLandingPage($landingPageId);
        $this->assertSame($segmentId, (int) ($landingPage['audience_segment_id'] ?? 0));
        $this->assertSame('Qualified inbound audience', (string) ($landingPage['audience_segment_name'] ?? ''));

        $emailRunId = $this->marketing->createEmailCampaignRun([
            'name' => 'Segmented Manual Email',
            'campaign_id' => $campaignId,
            'audience_segment_id' => $segmentId,
            'segment_snapshot_id' => (int) $snapshot['id'],
            'subject' => 'A useful next step',
            'created_by' => $this->userId,
        ]);
        $emailRun = $this->marketing->getEmailCampaignRun($emailRunId);
        $this->assertSame($segmentId, (int) ($emailRun['audience_segment_id'] ?? 0));
        $this->assertSame((int) $snapshot['id'], (int) ($emailRun['segment_snapshot_id'] ?? 0));
        $this->assertSame(1, (int) ($emailRun['recipient_count'] ?? 0));
        $this->assertSame('Qualified inbound audience', (string) ($emailRun['audience_segment_name'] ?? ''));

        $contentId = $this->marketing->createContentItem([
            'title' => 'Segmented Content',
            'content_type' => 'email',
            'channel' => 'email',
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingPageId,
            'draft_body' => 'Manual-first segmented copy.',
            'created_by' => $this->userId,
        ]);
        $distributionPostId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'email',
            'planned_copy' => 'Manual export copy',
            'created_by' => $this->userId,
        ]);
        $bundleId = $this->marketing->createChannelExportBundle($distributionPostId, $this->userId);
        $bundle = $this->marketing->getChannelExportBundle($bundleId);
        $this->assertSame($segmentId, (int) ($bundle['audience_segment_id'] ?? 0));
        $this->assertSame('Qualified inbound audience', (string) ($bundle['audience_segment_name'] ?? ''));

        $segmentsPage = $this->runWebEndpoint('public/marketing_segments.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($segmentsPage, 200, 'marketing_segments.php');
        $this->assertStringContainsString('Qualified inbound audience', (string) ($segmentsPage['body'] ?? ''));
        $viewPage = $this->runWebEndpoint('public/marketing_segment_view.php', $this->webSession('owner'), ['method' => 'GET', 'query' => ['id' => $segmentId]]);
        $this->assertEndpointHealthy($viewPage, 200, 'marketing_segment_view.php');
        $this->assertStringContainsString('ada@example.com', (string) ($viewPage['body'] ?? ''));
    }

    public function testPhaseThirtyFiveAudienceSegmentWorkspaceIsolationAndRoleLimits(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createAudienceSegment(['name' => 'Viewer Segment']);
            $this->fail('Viewer should not create audience segments.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Marketing Role Segment',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        try {
            $this->marketing->deleteAudienceSegment($segmentId);
            $this->fail('Marketing role should not delete audience segments.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        $otherWorkspaceId = $this->createWorkspace('Segment Isolation Workspace');
        \CRM\Services\WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $otherSegmentId = $otherMarketing->createAudienceSegment([
            'name' => 'Other Workspace Segment',
            'created_by' => $this->userId,
        ]);

        \CRM\Services\WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $this->assertNull($this->marketing->getAudienceSegment($otherSegmentId));

        $this->marketing->deleteAudienceSegment($segmentId);
        $this->assertNull($this->marketing->getAudienceSegment($segmentId));
    }

    public function testPhaseFiftyThreeAudienceIntelligenceScoresCrmFitAndSuggestions(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, lead_source, stage, assigned_to, created_by)
             VALUES (?, UUID(), 'Intel', 'Lead', 'intel-lead@example.com', 'form', 'qualified', ?, ?),
                    (?, UUID(), 'Other', 'Lead', 'other-lead@example.com', 'social', 'new', NULL, ?)",
            [$this->workspaceId, $this->userId, $this->userId, $this->workspaceId, $this->userId]
        );
        $contactId = (int) Database::queryOne(
            "SELECT id FROM contacts WHERE workspace_id = ? AND email = 'intel-lead@example.com'",
            [$this->workspaceId]
        )['id'];

        Database::execute(
            "INSERT INTO deals (workspace_id, title, description, contact_id, assigned_to, created_by, stage, value, probability, currency, lead_source)
             VALUES (?, 'Audience Intelligence Deal', 'Deal evidence for audience fit.', ?, ?, ?, 'proposal', 9000.00, 70, 'USD', 'form')",
            [$this->workspaceId, $contactId, $this->userId, $this->userId]
        );

        if (Database::columnExists('form_submissions', 'workspace_id')) {
            Database::execute(
                "INSERT INTO form_submissions (workspace_id, visitor_id, contact_id, form_id, page_path)
                 VALUES (?, 'visitor-phase53', ?, 'phase53-form', '/phase-53')",
                [$this->workspaceId, $contactId]
            );
        } else {
            Database::execute(
                "INSERT INTO form_submissions (visitor_id, contact_id, form_id, page_path)
                 VALUES ('visitor-phase53', ?, 'phase53-form', '/phase-53')",
                [$contactId]
            );
        }

        $handoffId = $this->marketing->createLeadHandoff([
            'contact_id' => $contactId,
            'assigned_to' => $this->userId,
            'status' => 'assigned',
            'priority' => 'high',
            'source' => 'manual_marketing_review',
            'handoff_note' => 'Audience intelligence handoff evidence.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->recordLeadHandoffFeedback($handoffId, 'accepted', [
            'feedback_reason' => 'Strong audience fit',
        ]);

        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Phase 53 Qualified Form Leads',
            'status' => 'active',
            'source_scope' => 'mixed',
            'rule_logic' => 'all',
            'rules' => [
                ['source_type' => 'contact', 'field_key' => 'lead_source', 'operator' => 'equals', 'value_text' => 'form'],
                ['source_type' => 'contact', 'field_key' => 'stage', 'operator' => 'equals', 'value_text' => 'qualified'],
            ],
            'created_by' => $this->userId,
        ]);

        $intelligence = $this->marketing->getAudienceSegmentIntelligence($segmentId, true);
        $this->assertSame(1, (int) ($intelligence['matched_contacts'] ?? 0));
        $this->assertGreaterThanOrEqual(80, (int) ($intelligence['fit_score'] ?? 0));
        $this->assertSame(1, (int) ($intelligence['crm_evidence']['deals']['count'] ?? 0));
        $this->assertSame(9000.0, (float) ($intelligence['crm_evidence']['deals']['pipeline_value'] ?? 0));
        $this->assertSame(1, (int) ($intelligence['crm_evidence']['form_submissions']['count'] ?? 0));
        $this->assertSame(1, (int) ($intelligence['crm_evidence']['handoffs']['count'] ?? 0));
        $this->assertSame('accepted', (string) ($intelligence['crm_evidence']['handoffs']['outcomes'][0]['label'] ?? ''));

        $segment = $this->marketing->getAudienceSegment($segmentId);
        $this->assertNotEmpty($segment['crm_preview_json'] ?? []);
        $this->assertGreaterThanOrEqual(80, (int) ($segment['fit_score'] ?? 0));
        $this->assertNotEmpty($segment['last_intelligence_at'] ?? null);

        $suggestions = $this->marketing->suggestAudienceSegmentsFromCrm(8, $this->userId);
        $titles = array_map(static fn(array $row): string => (string) $row['title'], $suggestions);
        $this->assertContains('Lead source: Form', $titles);
        $this->assertContains('Lifecycle stage: Qualified', $titles);
        $this->marketing->suggestAudienceSegmentsFromCrm(8, $this->userId);
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM marketing_audience_recommendations
             WHERE workspace_id = ? AND title = 'Lead source: Form'",
            [$this->workspaceId]
        )['count'] ?? 0));

        $segmentsPage = $this->runWebEndpoint('public/marketing_segments.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($segmentsPage, 200, 'marketing_segments.php audience intelligence');
        $this->assertStringContainsString('CRM Audience Ideas', (string) ($segmentsPage['body'] ?? ''));
        $this->assertStringContainsString('Phase 53 Qualified Form Leads', (string) ($segmentsPage['body'] ?? ''));

        $viewPage = $this->runWebEndpoint('public/marketing_segment_view.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['id' => $segmentId],
        ]);
        $this->assertEndpointHealthy($viewPage, 200, 'marketing_segment_view.php audience intelligence');
        $this->assertStringContainsString('CRM Intelligence', (string) ($viewPage['body'] ?? ''));
        $this->assertStringContainsString('Pipeline Value', (string) ($viewPage['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Audience Intelligence Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertSame([], $otherMarketing->listAudienceRecommendations([], 10, 0));
        try {
            $otherMarketing->getAudienceSegmentIntelligence($segmentId, false);
            $this->fail('Cross-workspace audience intelligence should not be readable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', strtolower($e->getMessage()));
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseEightySixCrmAudienceIntelligenceSummaryIsReadOnlyAndActionable(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, lead_source, stage, engagement_score, created_by)
             VALUES (?, UUID(), 'Audience', 'audience-form@example.com', 'form', 'qualified', 88, ?),
                    (?, UUID(), 'Referral', 'audience-referral@example.com', 'referral', 'new', NULL, ?),
                    (?, UUID(), 'Missing', 'audience-missing@example.com', '', '', NULL, ?)",
            [$this->workspaceId, $this->userId, $this->workspaceId, $this->userId, $this->workspaceId, $this->userId]
        );
        $contactId = (int) Database::queryOne(
            "SELECT id FROM contacts WHERE workspace_id = ? AND email = 'audience-form@example.com'",
            [$this->workspaceId]
        )['id'];
        Database::execute(
            "INSERT INTO deals (workspace_id, title, description, contact_id, assigned_to, created_by, stage, value, probability, currency, lead_source)
             VALUES (?, 'Audience Summary Deal', 'Deal signal for audience summary.', ?, ?, ?, 'proposal', 12000.00, 65, 'USD', 'form')",
            [$this->workspaceId, $contactId, $this->userId, $this->userId]
        );
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Phase 86 Qualified Form Audience',
            'status' => 'active',
            'source_scope' => 'contacts',
            'rules' => [
                ['source_type' => 'contact', 'field_key' => 'lead_source', 'operator' => 'equals', 'value_text' => 'form'],
            ],
            'created_by' => $this->userId,
        ]);
        $this->marketing->createAudienceSegmentSnapshot($segmentId, $this->userId);

        $beforeRecommendations = (int) (Database::queryOne(
            'SELECT COUNT(*) AS count FROM marketing_audience_recommendations WHERE workspace_id = ?',
            [$this->workspaceId]
        )['count'] ?? 0);

        $summary = $this->marketing->getCrmAudienceIntelligenceSummary();

        $afterRecommendations = (int) (Database::queryOne(
            'SELECT COUNT(*) AS count FROM marketing_audience_recommendations WHERE workspace_id = ?',
            [$this->workspaceId]
        )['count'] ?? 0);
        $this->assertSame($beforeRecommendations, $afterRecommendations, 'Audience summary must not create recommendation records just by rendering.');
        $this->assertSame(3, (int) ($summary['counts']['contacts'] ?? 0));
        $this->assertSame(1, (int) ($summary['counts']['segments'] ?? 0));
        $this->assertSame(1, (int) ($summary['counts']['snapshots'] ?? 0));
        $this->assertSame(1, (int) ($summary['counts']['deals'] ?? 0));
        $this->assertSame(12000.0, (float) ($summary['counts']['pipeline_value'] ?? 0));
        $this->assertContains('form', array_column((array) ($summary['source_mix']['lead_source'] ?? []), 'label'));
        $this->assertContains('qualified', array_column((array) ($summary['source_mix']['lifecycle_stage'] ?? []), 'label'));
        $this->assertContains('missing_lead_source', array_column((array) ($summary['missing_data'] ?? []), 'key'));
        $this->assertContains('missing_stage', array_column((array) ($summary['missing_data'] ?? []), 'key'));
        $this->assertContains('missing_engagement', array_column((array) ($summary['missing_data'] ?? []), 'key'));
        $this->assertContains('Lead source: Form', array_column((array) ($summary['recommended_audiences'] ?? []), 'title'));
        $this->assertTrue((bool) ($summary['manual_first_boundary']['read_only'] ?? false));
        $this->assertFalse((bool) ($summary['manual_first_boundary']['mutates_crm'] ?? true));
        $this->assertFalse((bool) ($summary['manual_first_boundary']['external_execution'] ?? true));

        $segmentsPage = $this->runWebEndpoint('public/marketing_segments.php', $this->webSession('owner'), ['method' => 'GET']);
        $body = (string) ($segmentsPage['body'] ?? '');
        $this->assertEndpointHealthy($segmentsPage, 200, 'marketing_segments.php audience summary');
        $this->assertStringContainsString('CRM Audience Intelligence', $body);
        $this->assertStringContainsString('Source Mix', $body);
        $this->assertStringContainsString('Data Gaps', $body);
        $this->assertStringContainsString('Recommended Audiences', $body);
        $this->assertStringContainsString('Read-only', $body);
    }

    public function testPhaseFiftyFourCampaignPlaybooksCreateBriefsAndValidateWorkspaceReadiness(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Playbook Campaign');
        $formId = $this->createForm($this->workspaceId, 'Playbook Form');
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Playbook Audience',
            'status' => 'active',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        $personaId = $this->marketing->createPersona([
            'name' => 'Playbook Persona',
            'segment' => 'Growth operators',
            'pains' => 'Inconsistent campaign launches',
            'goals' => 'Repeatable campaign execution',
            'created_by' => $this->userId,
        ]);
        $offerId = $this->marketing->createContextItem([
            'item_type' => 'offer',
            'title' => 'Playbook Offer',
            'body' => 'A guided campaign execution sprint.',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Playbook Landing',
            'slug' => 'playbook-landing-' . uniqid(),
            'headline' => 'Run a better launch',
            'form_id' => $formId,
            'campaign_id' => $campaignId,
            'audience_segment_id' => $segmentId,
            'created_by' => $this->userId,
        ]);

        $playbookId = $this->marketing->createCampaignPlaybook([
            'name' => 'Launch Campaign Playbook',
            'description' => 'Repeatable campaign operating system for launch motions.',
            'status' => 'active',
            'campaign_goal' => 'Create qualified launch demand',
            'target_audience' => 'Growth operators planning a launch',
            'audience_segment_id' => $segmentId,
            'persona_id' => $personaId,
            'offer_context_item_id' => $offerId,
            'landing_page_id' => $landingPageId,
            'success_metrics' => "12 demos booked\n20 qualified handoffs",
            'channel_plan' => "Email nurture\nLinkedIn content\nLanding page CTA",
            'checklist' => "Brief approved\nLanding page reviewed",
            'owner_user_id' => $this->userId,
            'stages' => [
                ['stage_type' => 'strategy', 'required_record_type' => 'brief', 'title' => 'Lock campaign brief', 'instructions' => 'Confirm audience, offer, and CTA.'],
                ['stage_type' => 'content', 'required_record_type' => 'content', 'title' => 'Create launch content', 'instructions' => 'Draft email, social, and blog support.'],
                ['stage_type' => 'conversion', 'required_record_type' => 'landing_page', 'title' => 'Prepare conversion page', 'instructions' => 'Verify form, proof, media, and CTA.'],
                ['stage_type' => 'distribution', 'required_record_type' => 'channel_export', 'title' => 'Build manual export bundle', 'instructions' => 'Package copy, links, assets, and UTM tags.'],
                ['stage_type' => 'sales_handoff', 'required_record_type' => 'handoff_rule', 'title' => 'Prepare sales handoff', 'instructions' => 'Define owner, SLA, and qualification notes.'],
            ],
            'created_by' => $this->userId,
        ]);

        $playbook = $this->marketing->getCampaignPlaybook($playbookId);
        $this->assertSame('Launch Campaign Playbook', (string) ($playbook['name'] ?? ''));
        $this->assertCount(5, (array) ($playbook['stages'] ?? []));
        $this->assertGreaterThanOrEqual(95, (int) ($playbook['readiness_score'] ?? 0));
        $this->assertSame([], (array) ($playbook['missing_requirements_json'] ?? []));

        $briefId = $this->marketing->createCampaignBriefFromPlaybook($playbookId, $this->userId, [
            'title' => 'Launch Campaign Brief From Playbook',
            'campaign_id' => $campaignId,
        ]);
        $brief = $this->marketing->getCampaignBrief($briefId);
        $this->assertSame($playbookId, (int) ($brief['campaign_playbook_id'] ?? 0));
        $this->assertSame('Launch Campaign Playbook', (string) ($brief['campaign_playbook_name'] ?? ''));
        $this->assertSame($segmentId, (int) ($brief['audience_segment_id'] ?? 0));
        $this->assertSame($personaId, (int) ($brief['persona_id'] ?? 0));
        $this->assertSame($offerId, (int) ($brief['offer_context_item_id'] ?? 0));
        $this->assertSame($landingPageId, (int) ($brief['landing_page_id'] ?? 0));
        $this->assertStringContainsString('manual_first', json_encode($brief['metadata_json'] ?? []));

        $filteredBriefs = $this->marketing->listCampaignBriefs(['campaign_playbook_id' => $playbookId], 10, 0);
        $this->assertCount(1, $filteredBriefs);

        $listPage = $this->runWebEndpoint('public/marketing_playbooks.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($listPage, 200, 'marketing_playbooks.php');
        $this->assertStringContainsString('Launch Campaign Playbook', (string) ($listPage['body'] ?? ''));
        $viewPage = $this->runWebEndpoint('public/marketing_playbook_view.php', $this->webSession('owner'), ['method' => 'GET', 'query' => ['id' => $playbookId]]);
        $this->assertEndpointHealthy($viewPage, 200, 'marketing_playbook_view.php');
        $this->assertStringContainsString('Operating Stages', (string) ($viewPage['body'] ?? ''));
        $editPage = $this->runWebEndpoint('public/marketing_playbook_edit.php', $this->webSession('owner'), ['method' => 'GET', 'query' => ['id' => $playbookId]]);
        $this->assertEndpointHealthy($editPage, 200, 'marketing_playbook_edit.php');
        $this->assertStringContainsString('Edit Campaign Playbook', (string) ($editPage['body'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->deleteCampaignPlaybook($playbookId);
            $this->fail('Marketing role should not archive campaign playbooks.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $otherWorkspaceId = $this->createWorkspace('Campaign Playbook Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->createCampaignPlaybook([
                'name' => 'Invalid Cross Workspace Playbook',
                'audience_segment_id' => $segmentId,
                'created_by' => $this->userId,
            ]);
            $this->fail('Cross-workspace playbook links should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseSixtyFourCampaignPlaybookTemplatesApplyFullManualCampaignKits(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Template Launch Campaign');

        $templateDefinitions = $this->marketing->campaignPlaybookTemplateDefinitions();
        $this->assertArrayHasKey('product_launch', $templateDefinitions);
        $playbookId = $this->marketing->createCampaignPlaybookFromTemplate('product_launch', $this->userId);
        $this->assertSame($playbookId, $this->marketing->createCampaignPlaybookFromTemplate('product_launch', $this->userId));

        $playbook = $this->marketing->getCampaignPlaybook($playbookId);
        $this->assertSame('product_launch', (string) ($playbook['playbook_type'] ?? ''));
        $this->assertSame('product_launch', (string) ($playbook['template_key'] ?? ''));
        $this->assertSame(1, (int) ($playbook['is_template'] ?? 0));
        $this->assertNotEmpty($playbook['default_content_plan_json'] ?? []);

        $run = $this->marketing->applyCampaignPlaybook($playbookId, $this->userId, [
            'campaign_id' => $campaignId,
            'run_name' => 'Template Launch Kit',
            'start_date' => date('Y-m-d', strtotime('+14 days')),
        ]);
        $this->assertGreaterThan(0, (int) ($run['id'] ?? 0));
        $this->assertSame($playbookId, (int) ($run['playbook_id'] ?? 0));
        $this->assertSame($campaignId, (int) ($run['campaign_id'] ?? 0));
        $this->assertGreaterThan(0, (int) ($run['campaign_brief_id'] ?? 0));
        $this->assertGreaterThan(0, (int) ($run['landing_page_id'] ?? 0));
        $this->assertGreaterThanOrEqual(3, count((array) ($run['created_content_ids_json'] ?? [])));
        $this->assertGreaterThanOrEqual(3, count((array) ($run['created_distribution_ids_json'] ?? [])));
        $this->assertGreaterThanOrEqual(4, count((array) ($run['created_calendar_ids_json'] ?? [])));
        $this->assertGreaterThanOrEqual(4, count((array) ($run['created_queue_item_ids_json'] ?? [])));
        $this->assertTrue((bool) ($run['summary_json']['manual_first'] ?? false));
        $this->assertFalse((bool) ($run['summary_json']['external_publish'] ?? true));

        $brief = $this->marketing->getCampaignBrief((int) $run['campaign_brief_id']);
        $this->assertSame($playbookId, (int) ($brief['campaign_playbook_id'] ?? 0));
        $this->assertSame($campaignId, (int) ($brief['campaign_id'] ?? 0));
        $content = $this->marketing->listContentItems(['campaign_brief_id' => (int) $run['campaign_brief_id']], 10, 0);
        $this->assertGreaterThanOrEqual(3, count($content));
        $this->assertSame('marketing_campaign_playbook_application', (string) ($content[0]['metadata_json']['source'] ?? ''));

        $runs = $this->marketing->listCampaignPlaybookApplicationRuns(['playbook_id' => $playbookId], 10, 0);
        $this->assertCount(1, $runs);
        $this->assertNotEmpty($this->marketing->getCampaignPlaybook($playbookId)['last_applied_at'] ?? null);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->applyCampaignPlaybook($playbookId, $this->userId);
            $this->fail('Viewer should not apply campaign playbooks.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $otherWorkspaceId = $this->createWorkspace('Playbook Template Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->applyCampaignPlaybook($playbookId, $this->userId, ['campaign_id' => $campaignId]);
            $this->fail('Cross-workspace campaign playbook applications should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', strtolower($e->getMessage()));
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }

        $page = $this->runWebEndpoint('public/marketing_playbook_view.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['id' => $playbookId],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_playbook_view.php templates');
        $this->assertStringContainsString('Apply Campaign Kit', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Application Runs', (string) ($page['body'] ?? ''));

        $listPage = $this->runWebEndpoint('public/marketing_playbooks.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($listPage, 200, 'marketing_playbooks.php templates');
        $this->assertStringContainsString('Starter Campaign Templates', (string) ($listPage['body'] ?? ''));
    }

    public function testPhaseFiftyFiveContentProductionPipelineTracksStagesDependenciesAndEvents(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Production Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Production Pipeline Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'status' => 'draft',
            'production_stage' => 'drafting',
            'production_due_at' => date('Y-m-d\TH:i', strtotime('+3 days')),
            'owner_user_id' => $this->userId,
            'campaign_id' => $campaignId,
            'draft_body' => 'A working draft for production workflow validation.',
            'production_checklist' => "Brief locked\nDraft written",
            'created_by' => $this->userId,
        ]);

        $item = $this->marketing->getContentItem($contentId);
        $this->assertSame('drafting', (string) ($item['production_stage'] ?? ''));
        $this->assertSame('clear', (string) ($item['dependency_status'] ?? ''));
        $this->assertGreaterThanOrEqual(80, (int) ($item['production_score'] ?? 0));
        $this->assertContains('Draft written', (array) ($item['production_checklist_json'] ?? []));

        $dependencyId = $this->marketing->createContentDependency([
            'content_item_id' => $contentId,
            'dependency_type' => 'asset',
            'title' => 'Final hero image',
            'status' => 'blocked',
            'due_at' => date('Y-m-d\TH:i', strtotime('+1 day')),
            'owner_user_id' => $this->userId,
            'notes' => 'Waiting on design.',
            'created_by' => $this->userId,
        ]);

        $blocked = $this->marketing->getContentItem($contentId);
        $this->assertSame('blocked', (string) ($blocked['dependency_status'] ?? ''));
        $this->assertSame('Resolve open production dependencies.', (string) ($blocked['next_action'] ?? ''));
        $this->assertCount(1, $this->marketing->listContentDependencies($contentId));

        $this->marketing->updateContentDependency($dependencyId, [
            'status' => 'done',
            'notes' => 'Hero image delivered.',
            'created_by' => $this->userId,
        ]);
        $unblocked = $this->marketing->getContentItem($contentId);
        $this->assertSame('clear', (string) ($unblocked['dependency_status'] ?? ''));

        $this->marketing->updateContentProduction($contentId, [
            'production_stage' => 'review',
            'production_checklist' => "Brief locked\nDraft written\nHero image attached\nReview requested",
            'created_by' => $this->userId,
        ]);
        $review = $this->marketing->getContentItem($contentId);
        $this->assertSame('review', (string) ($review['production_stage'] ?? ''));
        $this->assertNotEmpty($review['production_started_at'] ?? null);

        $events = $this->marketing->listContentProductionEvents($contentId, 20, 0);
        $eventTypes = array_column($events, 'event_type');
        $this->assertContains('dependency_added', $eventTypes);
        $this->assertContains('dependency_updated', $eventTypes);
        $this->assertContains('stage_changed', $eventTypes);

        $summary = $this->marketing->getContentProductionSummary($this->userId);
        $this->assertGreaterThanOrEqual(1, (int) ($summary['by_stage']['review'] ?? 0));

        $listPage = $this->runWebEndpoint('public/marketing_content.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['production_stage' => 'review'],
        ]);
        $this->assertEndpointHealthy($listPage, 200, 'marketing_content.php production filters');
        $this->assertStringContainsString('Production Pipeline Content', (string) ($listPage['body'] ?? ''));
        $this->assertStringContainsString('Production Stage', (string) ($listPage['body'] ?? ''));

        $viewPage = $this->runWebEndpoint('public/marketing_content_view.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['id' => $contentId],
        ]);
        $this->assertEndpointHealthy($viewPage, 200, 'marketing_content_view.php production pipeline');
        $this->assertStringContainsString('Content Production Pipeline', (string) ($viewPage['body'] ?? ''));
        $this->assertStringContainsString('Final hero image', (string) ($viewPage['body'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->deleteContentDependency($dependencyId);
            $this->fail('Marketing role should not delete production dependencies.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createContentDependency([
                'content_item_id' => $contentId,
                'title' => 'Viewer dependency',
                'created_by' => $this->userId,
            ]);
            $this->fail('Viewer should not create production dependencies.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $otherWorkspaceId = $this->createWorkspace('Production Pipeline Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->createContentDependency([
                'content_item_id' => $contentId,
                'title' => 'Cross workspace dependency',
                'created_by' => $this->userId,
            ]);
            $this->fail('Cross-workspace production dependency should not be created.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseFiftySixEditorialCalendarViewsTemplatesConflictsAndRescheduling(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Calendar Production Content',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'production_stage' => 'drafting',
            'production_due_at' => date('Y-m-d\TH:i', strtotime('+2 days')),
            'owner_user_id' => $this->userId,
            'draft_body' => 'Calendar production copy.',
            'created_by' => $this->userId,
        ]);
        $calendarDate = date('Y-m-d', strtotime('+4 days'));
        $firstId = $this->marketing->createCalendarMilestone([
            'title' => 'LinkedIn publish checkpoint',
            'milestone_type' => 'publish',
            'channel' => 'linkedin',
            'milestone_date' => $calendarDate,
            'capacity_weight' => 3,
            'content_item_id' => $contentId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $secondId = $this->marketing->createCalendarMilestone([
            'title' => 'Newsletter publish checkpoint',
            'milestone_type' => 'publish',
            'channel' => 'newsletter',
            'milestone_date' => $calendarDate,
            'capacity_weight' => 2,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);

        $templateId = $this->marketing->createCalendarTemplate([
            'name' => 'Launch Publish Template',
            'template_key' => 'launch_publish',
            'default_title' => 'Publish launch content',
            'milestone_type' => 'publish',
            'channel' => 'linkedin',
            'offset_days' => 2,
            'checklist' => "Draft approved\nMedia attached",
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $templates = $this->marketing->listCalendarTemplates(['channel' => 'linkedin']);
        $this->assertContains($templateId, array_map(static fn(array $row): int => (int) $row['id'], $templates));
        $template = array_values(array_filter($templates, static fn(array $row): bool => (int) $row['id'] === $templateId))[0] ?? [];
        $this->assertSame(['Draft approved', 'Media attached'], (array) ($template['checklist_json'] ?? []));

        $calendar = $this->marketing->getEditorialCalendar([
            'date_from' => date('Y-m-d'),
            'date_to' => date('Y-m-d', strtotime('+7 days')),
        ]);
        $this->assertArrayHasKey($calendarDate, (array) ($calendar['by_date'] ?? []));
        $this->assertNotEmpty($calendar['conflicts']);
        $this->assertSame('conflict', (string) ($calendar['conflicts'][0]['status'] ?? ''));
        $this->assertNotEmpty($calendar['production_due']);

        $newDate = date('Y-m-d', strtotime('+8 days'));
        $this->assertTrue($this->marketing->rescheduleCalendarMilestone($secondId, $newDate, $this->userId));
        $rescheduled = $this->marketing->listCalendarMilestones(['date_from' => $newDate, 'date_to' => $newDate], 10, 0);
        $this->assertContains($secondId, array_map(static fn(array $row): int => (int) $row['id'], $rescheduled));

        $page = $this->runWebEndpoint('public/marketing_calendar.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d', strtotime('+10 days'))],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_calendar.php editorial calendar');
        $this->assertStringContainsString('Editorial Calendar Plan', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Calendar Templates', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('LinkedIn publish checkpoint', (string) ($page['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Editorial Calendar Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->createCalendarMilestone([
                'title' => 'Cross workspace calendar content',
                'milestone_date' => date('Y-m-d'),
                'content_item_id' => $contentId,
                'created_by' => $this->userId,
            ]);
            $this->fail('Cross-workspace calendar content should not be linked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }

        $this->assertTrue($this->marketing->deleteCalendarMilestone($firstId));
    }

    public function testPhaseFiftySevenLaunchReadinessScoresBlocksAndApprovesManualLaunches(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Launch Readiness Campaign');

        $blocked = $this->marketing->evaluateLaunchReadiness([
            'launch_name' => 'Incomplete Launch Readiness Review',
            'launch_date' => date('Y-m-d', strtotime('+10 days')),
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ], $this->userId);
        $this->assertSame('blocked', (string) ($blocked['status'] ?? ''));
        $this->assertContains('audience_ready', (array) ($blocked['blocking_reasons_json'] ?? []));
        $this->assertContains('distribution_ready', (array) ($blocked['blocking_reasons_json'] ?? []));

        $templateId = $this->marketing->createLaunchReadinessTemplate([
            'name' => 'Product Launch Readiness',
            'campaign_type' => 'product',
            'required_checks' => "Audience locked\nOffer approved\nUTM link created",
            'recommended_checks' => "Hero media approved\nSales handoff drafted",
            'created_by' => $this->userId,
        ]);
        $this->assertContains($templateId, array_map(static fn(array $row): int => (int) $row['id'], $this->marketing->listLaunchReadinessTemplates(['campaign_type' => 'product'])));

        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Launch Decision Makers',
            'status' => 'active',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        $offerId = $this->marketing->createContextItem([
            'item_type' => 'offer',
            'title' => 'Launch Readiness Sprint',
            'body' => 'A focused readiness review for teams preparing manual launches.',
            'created_by' => $this->userId,
        ]);
        $formId = $this->createForm($this->workspaceId, 'Launch Readiness Form');
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Approved Launch Hero',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/launch-hero.jpg',
            'alt_text' => 'A marketing team reviewing a launch checklist',
            'approval_status' => 'approved',
            'license_status' => 'owned',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Launch Readiness Landing',
            'slug' => 'launch-readiness-' . uniqid(),
            'headline' => 'Run a cleaner launch',
            'form_id' => $formId,
            'campaign_id' => $campaignId,
            'audience_segment_id' => $segmentId,
            'hero_media_file_id' => $mediaId,
            'conversion_goal' => 'demo_request',
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Launch Readiness Brief',
            'objective' => 'Help operators prepare a manual campaign launch.',
            'audience' => 'Marketing leaders planning product launches',
            'audience_segment_id' => $segmentId,
            'offer_context_item_id' => $offerId,
            'offer_text' => 'Launch Readiness Sprint',
            'key_message' => 'Book a launch readiness review before going live.',
            'campaign_id' => $campaignId,
            'landing_page_id' => $landingPageId,
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Launch Readiness Content',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'status' => 'approved',
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingPageId,
            'draft_body' => 'Book a launch readiness review before the team starts manual distribution.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'featured',
            'channel' => 'linkedin',
            'created_by' => $this->userId,
        ]);
        $distributionPostId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'linkedin',
            'planned_copy' => 'Manual launch copy with approved hero media.',
            'status' => 'exported',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createUtmLink([
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'url' => 'https://example.com/launch-readiness',
            'utm_source' => 'linkedin',
            'utm_medium' => 'social',
            'utm_campaign' => 'launch_readiness',
            'created_by' => $this->userId,
        ]);

        $ready = $this->marketing->evaluateLaunchReadiness([
            'launch_name' => 'Complete Launch Readiness Review',
            'launch_date' => date('Y-m-d', strtotime('+14 days')),
            'template_id' => $templateId,
            'campaign_brief_id' => $briefId,
            'content_item_id' => $contentId,
            'distribution_post_id' => $distributionPostId,
            'checklist' => "Brief approved\nFinal copy reviewed\nManual export downloaded\nLaunch owner confirmed",
            'created_by' => $this->userId,
        ], $this->userId);
        $this->assertSame('ready', (string) ($ready['status'] ?? ''));
        $this->assertGreaterThanOrEqual(90, (int) ($ready['readiness_score'] ?? 0));
        $this->assertSame([], (array) ($ready['blocking_reasons_json'] ?? ['unexpected']));
        $this->assertTrue((bool) ($ready['result_json']['manual_first'] ?? false));
        $this->assertFalse((bool) ($ready['result_json']['external_publish'] ?? true));

        $brief = $this->marketing->getCampaignBrief($briefId);
        $this->assertGreaterThanOrEqual(90, (int) ($brief['launch_readiness_score'] ?? 0));
        $this->assertSame('ready', (string) ($brief['launch_readiness_json']['status'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->approveLaunchReadiness((int) $ready['id'], $this->userId);
            $this->fail('Marketing role should not approve launch readiness.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $this->assertTrue($this->marketing->approveLaunchReadiness((int) $ready['id'], $this->userId));
        $approved = $this->marketing->getLaunchReadinessReview((int) $ready['id']);
        $this->assertSame('approved', (string) ($approved['status'] ?? ''));

        $page = $this->runWebEndpoint('public/marketing_launch_readiness.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['status' => 'approved'],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_launch_readiness.php');
        $this->assertStringContainsString('Launch Readiness', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Complete Launch Readiness Review', (string) ($page['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Launch Readiness Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->evaluateLaunchReadiness([
                'launch_name' => 'Cross workspace launch readiness',
                'content_item_id' => $contentId,
                'created_by' => $this->userId,
            ], $this->userId);
            $this->fail('Cross-workspace launch readiness content should not be linked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseFiftyEightAudienceActivationConnectsSegmentsToCampaignLaunches(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Audience Activation Campaign');
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Expansion Decision Committee',
            'status' => 'active',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        $personaId = $this->marketing->createPersona([
            'name' => 'Growth Operator',
            'segment' => 'Operations leaders',
            'pains' => 'Campaigns launch without clear audiences.',
            'goals' => 'Run measurable launches.',
            'created_by' => $this->userId,
        ]);
        $mapId = $this->marketing->mapPersonaToSegment($personaId, $segmentId, [
            'match_score' => 88,
            'match_reason' => 'This persona owns launch audience selection.',
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $mapId);
        $maps = $this->marketing->listPersonaSegmentMaps(['audience_segment_id' => $segmentId]);
        $this->assertSame('Growth Operator', (string) ($maps[0]['persona_name'] ?? ''));

        $offerId = $this->marketing->createContextItem([
            'item_type' => 'offer',
            'title' => 'Audience Activation Sprint',
            'body' => 'A guided sprint for selecting and activating the right launch audience.',
            'created_by' => $this->userId,
        ]);
        $formId = $this->createForm($this->workspaceId, 'Audience Activation Form');
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Audience Activation Hero',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/audience-activation.jpg',
            'alt_text' => 'Campaign team selecting an audience segment',
            'approval_status' => 'approved',
            'license_status' => 'owned',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Audience Activation Landing',
            'slug' => 'audience-activation-' . uniqid(),
            'headline' => 'Launch to the right audience',
            'form_id' => $formId,
            'campaign_id' => $campaignId,
            'hero_media_file_id' => $mediaId,
            'conversion_goal' => 'demo_request',
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Audience Activation Brief',
            'objective' => 'Prove an activated audience can satisfy launch readiness.',
            'offer_context_item_id' => $offerId,
            'offer_text' => 'Audience Activation Sprint',
            'key_message' => 'Book an audience activation review before launch.',
            'campaign_id' => $campaignId,
            'landing_page_id' => $landingPageId,
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Audience Activation Content',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'status' => 'approved',
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingPageId,
            'draft_body' => 'Book an audience activation review before launch.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'featured',
            'channel' => 'linkedin',
            'created_by' => $this->userId,
        ]);
        $distributionPostId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'linkedin',
            'planned_copy' => 'Manual launch copy for the activated audience.',
            'status' => 'exported',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createUtmLink([
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'url' => 'https://example.com/audience-activation',
            'utm_source' => 'linkedin',
            'utm_medium' => 'social',
            'utm_campaign' => 'audience_activation',
            'created_by' => $this->userId,
        ]);

        $activationId = $this->marketing->createAudienceActivation([
            'activation_name' => 'Audience Activation Launch',
            'activation_type' => 'launch',
            'status' => 'active',
            'audience_segment_id' => $segmentId,
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingPageId,
            'content_item_id' => $contentId,
            'distribution_post_id' => $distributionPostId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $activation = $this->marketing->getAudienceActivation($activationId);
        $this->assertSame('Audience Activation Launch', (string) ($activation['activation_name'] ?? ''));
        $this->assertSame('active', (string) ($activation['status'] ?? ''));
        $this->assertContains((string) ($activation['coverage_status'] ?? ''), ['ready', 'warning']);
        $this->assertGreaterThan(50, (int) ($activation['fit_score'] ?? 0));

        $summary = $this->marketing->getAudienceActivationSummary();
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['active'] ?? 0));
        $this->assertNotEmpty($this->marketing->optionData()['audience_activations']);

        $review = $this->marketing->evaluateLaunchReadiness([
            'launch_name' => 'Audience Activation Launch Review',
            'campaign_brief_id' => $briefId,
            'content_item_id' => $contentId,
            'distribution_post_id' => $distributionPostId,
            'checklist' => "Brief approved\nManual export downloaded\nAudience owner confirmed",
            'created_by' => $this->userId,
        ], $this->userId);
        $this->assertNotContains('audience_ready', (array) ($review['blocking_reasons_json'] ?? []));
        $this->assertSame($activationId, (int) ($review['result_json']['audience_activation']['id'] ?? 0));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createAudienceActivation([
                'activation_name' => 'Viewer Activation',
                'audience_segment_id' => $segmentId,
            ]);
            $this->fail('Viewer should not create audience activations.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $this->assertTrue($this->marketing->updateAudienceActivation($activationId, ['status' => 'paused']));
        try {
            $this->marketing->archiveAudienceActivation($activationId);
            $this->fail('Marketing role should not archive audience activations.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $page = $this->runWebEndpoint('public/marketing_audience_activation.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_audience_activation.php');
        $this->assertStringContainsString('Audience Activation', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Audience Activation Launch', (string) ($page['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Audience Activation Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->createAudienceActivation([
                'activation_name' => 'Cross workspace activation',
                'audience_segment_id' => $segmentId,
                'content_item_id' => $contentId,
                'created_by' => $this->userId,
            ]);
            $this->fail('Cross-workspace activation should not link records from another workspace.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }

        $this->assertTrue($this->marketing->archiveAudienceActivation($activationId));
        $archived = $this->marketing->getAudienceActivation($activationId);
        $this->assertSame('archived', (string) ($archived['status'] ?? ''));
    }

    public function testPhaseFiftyNineLaunchControlRoomGatesManualLaunches(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Launch Control Campaign');
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Launch Control Segment',
            'status' => 'active',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        $offerId = $this->marketing->createContextItem([
            'item_type' => 'offer',
            'title' => 'Launch Control Offer',
            'body' => 'A controlled launch handoff for manual execution.',
            'created_by' => $this->userId,
        ]);
        $formId = $this->createForm($this->workspaceId, 'Launch Control Form');
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Launch Control Hero',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/launch-control-hero.jpg',
            'alt_text' => 'Team preparing a controlled marketing launch',
            'approval_status' => 'approved',
            'license_status' => 'owned',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Launch Control Landing',
            'slug' => 'launch-control-' . uniqid(),
            'headline' => 'Launch with control',
            'form_id' => $formId,
            'campaign_id' => $campaignId,
            'audience_segment_id' => $segmentId,
            'hero_media_file_id' => $mediaId,
            'conversion_goal' => 'demo_request',
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Launch Control Brief',
            'objective' => 'Prepare a campaign for manual launch.',
            'audience' => 'Campaign operators',
            'audience_segment_id' => $segmentId,
            'offer_context_item_id' => $offerId,
            'offer_text' => 'Launch Control Offer',
            'key_message' => 'Book a launch control review before going live.',
            'campaign_id' => $campaignId,
            'landing_page_id' => $landingPageId,
            'owner_user_id' => $this->userId,
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Launch Control Content',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'status' => 'approved',
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingPageId,
            'draft_body' => 'Book a launch control review before manual distribution.',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'featured',
            'channel' => 'linkedin',
            'created_by' => $this->userId,
        ]);
        $distributionPostId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'linkedin',
            'planned_copy' => 'Manual launch copy for the Launch Control audience.',
            'status' => 'exported',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createUtmLink([
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'url' => 'https://example.com/launch-control',
            'utm_source' => 'linkedin',
            'utm_medium' => 'social',
            'utm_campaign' => 'launch_control',
            'created_by' => $this->userId,
        ]);
        $activationId = $this->marketing->createAudienceActivation([
            'activation_name' => 'Launch Control Audience Activation',
            'activation_type' => 'launch',
            'status' => 'active',
            'audience_segment_id' => $segmentId,
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingPageId,
            'content_item_id' => $contentId,
            'distribution_post_id' => $distributionPostId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $review = $this->marketing->evaluateLaunchReadiness([
            'launch_name' => 'Launch Control Review',
            'launch_date' => date('Y-m-d', strtotime('+12 days')),
            'campaign_brief_id' => $briefId,
            'content_item_id' => $contentId,
            'distribution_post_id' => $distributionPostId,
            'checklist' => "Audience confirmed\nOffer approved\nManual export downloaded\nLaunch owner confirmed",
            'created_by' => $this->userId,
        ], $this->userId);
        $this->assertSame('ready', (string) ($review['status'] ?? ''));

        $control = $this->marketing->prepareLaunchControl([
            'launch_readiness_review_id' => (int) $review['id'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ], $this->userId);
        $this->assertSame('ready', (string) ($control['status'] ?? ''));
        $this->assertGreaterThanOrEqual(90, (int) ($control['readiness_score'] ?? 0));
        $this->assertSame($activationId, (int) ($control['audience_activation_id'] ?? 0));
        $this->assertSame([], (array) ($control['blocker_json'] ?? ['unexpected']));
        $this->assertTrue((bool) ($control['metadata_json']['manual_first_boundary'] ?? false));
        $this->assertFalse((bool) ($control['metadata_json']['external_publish'] ?? true));

        $summary = $this->marketing->getLaunchControlSummary();
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['ready'] ?? 0));
        $brief = $this->marketing->getCampaignBrief($briefId);
        $this->assertSame('ready', (string) ($brief['launch_control_status'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->markLaunchControlManuallyLaunched((int) $control['id'], $this->userId, 'Marketing role attempt.');
            $this->fail('Marketing role should not mark launch control records manually launched.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }
        $this->assertTrue($this->marketing->updateLaunchControlStatus((int) $control['id'], 'paused'));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $this->assertTrue($this->marketing->updateLaunchControlStatus((int) $control['id'], 'ready'));
        $this->assertTrue($this->marketing->markLaunchControlManuallyLaunched((int) $control['id'], $this->userId, 'Manual launch completed outside the CRM.'));
        $launched = $this->marketing->getLaunchControlRecord((int) $control['id']);
        $this->assertSame('launched_manual', (string) ($launched['status'] ?? ''));
        $this->assertFalse((bool) ($launched['manual_launch_log_json'][0]['external_send'] ?? true));

        $blocked = $this->marketing->prepareLaunchControl([
            'launch_name' => 'Blocked Launch Control',
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ], $this->userId);
        $this->assertSame('blocked', (string) ($blocked['status'] ?? ''));
        $this->assertContains('launch_readiness_ready', (array) ($blocked['blocker_json'] ?? []));

        $page = $this->runWebEndpoint('public/marketing_launch_control.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_launch_control.php');
        $this->assertStringContainsString('Launch Control Room', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Blocked Launch Control', (string) ($page['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Launch Control Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->prepareLaunchControl([
                'launch_name' => 'Cross workspace launch control',
                'content_item_id' => $contentId,
                'created_by' => $this->userId,
            ], $this->userId);
            $this->fail('Cross-workspace launch control should not link records from another workspace.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseSixtyGuidedWorkflowsComputeNextActionsAndPersistState(): void
    {
        $emptyWorkflows = $this->marketing->listGuidedMarketingWorkflows();
        $this->assertCount(7, $emptyWorkflows);
        $campaignGuide = $this->marketing->getGuidedMarketingWorkflow('campaign_launch');
        $this->assertSame('not_started', (string) ($campaignGuide['status'] ?? ''));
        $this->assertSame('context_ready', (string) ($campaignGuide['current_step_key'] ?? ''));

        $this->marketing->refreshGuidedMarketingWorkflows($this->userId);
        $this->assertSame(7, (int) (Database::queryOne(
            'SELECT COUNT(*) AS count FROM marketing_guided_workflows WHERE workspace_id = ?',
            [$this->workspaceId]
        )['count'] ?? 0));

        $campaignId = $this->createCampaign($this->workspaceId, 'Guided Workflow Campaign');
        $personaId = $this->marketing->createPersona([
            'name' => 'Guided Operator',
            'segment' => 'Marketing operators',
            'pains' => 'Missing launch steps.',
            'goals' => 'Know what to do next.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createBrandProfile([
            'name' => 'Guided Workflow Brand',
            'voice' => 'Direct and helpful',
            'tone' => 'Practical',
            'created_by' => $this->userId,
        ]);
        $offerId = $this->marketing->createContextItem([
            'item_type' => 'offer',
            'title' => 'Guided Workflow Offer',
            'body' => 'A guided marketing launch review.',
            'persona_id' => $personaId,
            'created_by' => $this->userId,
        ]);
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Guided Workflow Segment',
            'status' => 'active',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        $this->marketing->createCampaignBrief([
            'title' => 'Guided Workflow Brief',
            'objective' => 'Create enough context to show partial workflow progress.',
            'audience' => 'Marketing operators',
            'audience_segment_id' => $segmentId,
            'offer_context_item_id' => $offerId,
            'campaign_id' => $campaignId,
            'status' => 'active',
            'created_by' => $this->userId,
        ]);

        $updatedCampaignGuide = $this->marketing->getGuidedMarketingWorkflow('campaign_launch');
        $this->assertSame('blocked', (string) ($updatedCampaignGuide['status'] ?? ''));
        $this->assertGreaterThan(0, (int) ($updatedCampaignGuide['progress_score'] ?? 0));
        $this->assertSame('landing_ready', (string) ($updatedCampaignGuide['current_step_key'] ?? ''));
        $this->assertContains('Landing page ready', array_column((array) ($updatedCampaignGuide['missing_requirements'] ?? []), 'label'));

        $summary = $this->marketing->getGuidedMarketingWorkflowSummary();
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['blocked'] ?? 0));
        $this->assertNotEmpty($summary['next_actions']);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $this->assertCount(7, $this->marketing->listGuidedMarketingWorkflows());
        try {
            $this->marketing->updateGuidedMarketingWorkflowState('campaign_launch', ['status' => 'dismissed'], $this->userId);
            $this->fail('Viewer should not update guided workflow state.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $dismissed = $this->marketing->updateGuidedMarketingWorkflowState('campaign_launch', ['status' => 'dismissed', 'note' => 'Hide for now.'], $this->userId);
        $this->assertSame('dismissed', (string) ($dismissed['status'] ?? ''));
        try {
            $this->marketing->updateGuidedMarketingWorkflowState('campaign_launch', ['status' => 'archived'], $this->userId);
            $this->fail('Marketing role should not archive guided workflows.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $this->assertSame('archived', (string) ($this->marketing->updateGuidedMarketingWorkflowState('campaign_launch', ['status' => 'archived'], $this->userId)['status'] ?? ''));
        $this->assertGreaterThanOrEqual(2, (int) (Database::queryOne(
            'SELECT COUNT(*) AS count FROM marketing_guided_workflow_events WHERE workspace_id = ?',
            [$this->workspaceId]
        )['count'] ?? 0));

        $page = $this->runWebEndpoint('public/marketing_guided_workflows.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_guided_workflows.php');
        $this->assertStringContainsString('Marketing Guided Workflows', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Content Production Workflow', (string) ($page['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Guided Workflow Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            $otherWorkflows = (new Marketing())->listGuidedMarketingWorkflows();
            $this->assertCount(7, $otherWorkflows);
            $this->assertSame('not_started', (string) ($otherWorkflows[0]['status'] ?? ''));
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseOneHundredFourWorkflowWizardCatalogStartsManualFirstWizards(): void
    {
        $catalog = $this->marketing->getMarketingWorkflowWizardCatalog($this->userId);
        $this->assertCount(7, (array) ($catalog['wizards'] ?? []));
        $labels = array_column((array) ($catalog['wizards'] ?? []), 'label');
        $this->assertContains('Prepare Email Export', $labels);
        $this->assertContains('Review And Approve Content', $labels);
        $emailWizard = array_values(array_filter(
            (array) ($catalog['wizards'] ?? []),
            static fn(array $wizard): bool => (string) ($wizard['workflow_key'] ?? '') === 'email_export'
        ))[0] ?? [];
        $this->assertFalse((bool) ($emailWizard['manual_first_boundary']['external_send'] ?? true));
        $this->assertFalse((bool) ($emailWizard['manual_first_boundary']['external_publish'] ?? true));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->startMarketingWorkflowWizard('email_export', $this->userId);
            $this->fail('Viewer should not start workflow wizards.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $started = $this->marketing->startMarketingWorkflowWizard('email_export', $this->userId);
        $this->assertTrue((bool) ($started['wizard_started'] ?? false));
        $this->assertSame('in_progress', (string) ($started['status'] ?? ''));
        $this->assertFalse((bool) ($started['manual_first_boundary']['external_send'] ?? true));
        $this->assertSame('in_progress', (string) ($this->marketing->getGuidedMarketingWorkflow('email_export')['status'] ?? ''));

        $rows = Database::query(
            "SELECT event_type
             FROM marketing_guided_workflow_events
             WHERE workspace_id = ? AND event_type = 'in_progress'",
            [$this->workspaceId]
        );
        $this->assertNotEmpty($rows);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $page = $this->runWebEndpoint('public/marketing_guided_workflows.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_guided_workflows.php phase 104');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Workflow Wizards', $body);
        $this->assertStringContainsString('Start Email Wizard', $body);
        $this->assertStringContainsString('Review And Approve Content', $body);

        $dashboard = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($dashboard, 200, 'marketing.php phase 104');
        $this->assertStringContainsString('Prepare Email Export', (string) ($dashboard['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Phase 104 Workflow Wizard Isolation');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherCatalog = (new Marketing())->getMarketingWorkflowWizardCatalog($this->userId);
        $otherEmailWizard = array_values(array_filter(
            (array) ($otherCatalog['wizards'] ?? []),
            static fn(array $wizard): bool => (string) ($wizard['workflow_key'] ?? '') === 'email_export'
        ))[0] ?? [];
        $this->assertNotSame('in_progress', (string) ($otherEmailWizard['status'] ?? ''));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseSixtyOneMarketingTaskHubAggregatesQueuesAndLogsActions(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Task Hub Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Task Hub Assigned Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'status' => 'draft',
            'campaign_id' => $campaignId,
            'owner_user_id' => $this->userId,
            'reviewer_user_id' => $this->userId,
            'production_stage' => 'drafting',
            'production_due_at' => date('Y-m-d H:i:s', strtotime('+2 days')),
            'blocked_reason' => 'Waiting on product proof.',
            'draft_body' => 'Task hub draft with next actions.',
            'created_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestContentReview($contentId, $this->userId, $this->userId, date('Y-m-d\TH:i', strtotime('-1 day')));
        $this->marketing->createCalendarMilestone([
            'title' => 'Task Hub Calendar Milestone',
            'milestone_type' => 'review',
            'milestone_date' => date('Y-m-d', strtotime('+3 days')),
            'content_item_id' => $contentId,
            'campaign_id' => $campaignId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $blockedLaunch = $this->marketing->prepareLaunchControl([
            'launch_name' => 'Task Hub Blocked Launch',
            'campaign_id' => $campaignId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ], $this->userId);
        $this->marketing->refreshGuidedMarketingWorkflows($this->userId);

        $hub = $this->marketing->getMarketingTaskHub($this->userId);
        $this->assertGreaterThanOrEqual(1, (int) ($hub['counts']['assigned_content'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($hub['counts']['due_content'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($hub['counts']['pending_reviews'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($hub['counts']['overdue_reviews'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($hub['counts']['blocked_content'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($hub['counts']['blocked_launches'] ?? 0));
        $this->assertNotEmpty($hub['suggested_actions']);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $this->assertGreaterThanOrEqual(1, (int) ($this->marketing->getMarketingTaskHub($this->userId)['counts']['assigned_content'] ?? 0));
        try {
            $this->marketing->updateMarketingTaskHubPreferences($this->userId, ['default_queue' => 'blocked']);
            $this->fail('Viewer should not update task hub preferences.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $preferences = $this->marketing->updateMarketingTaskHubPreferences($this->userId, [
            'default_queue' => 'blocked',
            'include_team_items' => true,
        ]);
        $this->assertSame('blocked', (string) ($preferences['default_queue'] ?? ''));
        $this->assertSame(1, (int) ($preferences['include_team_items'] ?? 0));
        $actionId = $this->marketing->recordMarketingTaskHubAction('content', $contentId, 'acknowledged', ['queue' => 'blocked'], 'Working it now.', $this->userId);
        $this->assertGreaterThan(0, $actionId);
        $actionHub = $this->marketing->getMarketingTaskHub($this->userId);
        $this->assertNotEmpty($actionHub['queues']['recent_actions']);

        try {
            $this->marketing->recordMarketingTaskHubAction('approval', $approvalId, 'not_allowed', [], '', $this->userId);
            $this->fail('Invalid task hub action type should be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid', $e->getMessage());
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $page = $this->runWebEndpoint('public/marketing_task_hub.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_task_hub.php');
        $this->assertStringContainsString('Marketing Task Hub', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Task Hub Assigned Content', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Task Hub Blocked Launch', (string) ($page['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Task Hub Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->recordMarketingTaskHubAction('content', $contentId, 'acknowledged', [], '', $this->userId);
            $this->fail('Task hub action should not accept cross-workspace content.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }

        $launched = $this->marketing->getLaunchControlRecord((int) $blockedLaunch['id']);
        $this->assertSame('blocked', (string) ($launched['status'] ?? ''));
    }

    public function testPhaseSeventySevenNextBestActionsUnifyMarketingQueues(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Next Best Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Next Best Assigned Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'status' => 'draft',
            'campaign_id' => $campaignId,
            'owner_user_id' => $this->userId,
            'production_stage' => 'drafting',
            'production_due_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'created_by' => $this->userId,
        ]);
        $this->marketing->refreshGuidedMarketingWorkflows($this->userId);

        $actions = $this->marketing->getMarketingNextBestActions($this->userId, 20);
        $this->assertNotEmpty($actions['actions']);
        $sources = array_values(array_unique(array_column((array) $actions['actions'], 'source')));
        $this->assertContains('Command Flow', $sources);
        $this->assertContains('Task Hub', $sources);
        $this->assertContains('Guided Workflow', $sources);
        $this->assertContains('Production', $sources);
        $this->assertGreaterThanOrEqual(1, (int) ($actions['counts']['total'] ?? 0));
        $this->assertStringContainsString('manual-first', implode(' ', (array) ($actions['guardrails'] ?? [])));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $viewerActions = $this->marketing->getMarketingNextBestActions($this->userId, 8);
        $this->assertNotEmpty($viewerActions['actions']);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $page = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing.php unified next best actions');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Unified Next Best Actions', $body);
        $this->assertStringContainsString('Task Hub', $body);
        $this->assertStringContainsString('Actions stay manual-first', $body);
        $this->assertStringContainsString('marketing_content_view.php?id=' . $contentId, $body);
    }

    public function testPhaseSixtyTwoCampaignWorkspaceUnifiesCampaignExecutionRecords(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $activationId = $this->marketing->createAudienceActivation([
            'activation_name' => 'Campaign Workspace Audience Activation',
            'activation_type' => 'launch',
            'status' => 'active',
            'audience_segment_id' => $fixture['segment_id'],
            'campaign_id' => $fixture['campaign_id'],
            'campaign_brief_id' => $fixture['brief_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $review = $this->marketing->evaluateLaunchReadiness([
            'launch_name' => 'Campaign Workspace Readiness',
            'launch_date' => date('Y-m-d', strtotime('+10 days')),
            'campaign_brief_id' => $fixture['brief_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'checklist' => "Audience confirmed\nOffer approved\nManual export ready",
            'created_by' => $this->userId,
        ], $this->userId);
        $control = $this->marketing->prepareLaunchControl([
            'launch_readiness_review_id' => (int) $review['id'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ], $this->userId);
        $this->assertSame($activationId, (int) ($control['audience_activation_id'] ?? 0));

        $workspace = $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);
        $this->assertSame('ready', (string) ($workspace['status'] ?? ''));
        $this->assertGreaterThanOrEqual(85, (int) ($workspace['health_score'] ?? 0));
        $this->assertSame([], (array) ($workspace['missing_requirements_json'] ?? ['unexpected']));
        $this->assertGreaterThanOrEqual(1, (int) ($workspace['linked_summary_json']['briefs']['count'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($workspace['linked_summary_json']['audience_activations']['count'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($workspace['linked_summary_json']['landing_pages']['count'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($workspace['linked_summary_json']['content_items']['count'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($workspace['linked_summary_json']['distribution_posts']['count'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($workspace['linked_summary_json']['utm_links']['count'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($workspace['linked_summary_json']['launch_readiness']['count'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($workspace['linked_summary_json']['launch_control']['count'] ?? 0));
        $this->assertTrue((bool) ($workspace['metadata_json']['manual_first_boundary'] ?? false));
        $this->assertFalse((bool) ($workspace['metadata_json']['external_publish'] ?? true));
        $this->assertSame(1, (int) (Database::queryOne(
            'SELECT COUNT(*) AS count FROM marketing_campaign_workspaces WHERE workspace_id = ? AND campaign_id = ?',
            [$this->workspaceId, $fixture['campaign_id']]
        )['count'] ?? 0));

        $summary = $this->marketing->getMarketingCampaignWorkspaceSummary();
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['ready'] ?? 0));
        $this->assertGreaterThanOrEqual(85, (int) ($summary['average_health'] ?? 0));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $this->assertSame('ready', (string) ($this->marketing->getMarketingCampaignWorkspace($fixture['campaign_id'])['status'] ?? ''));
        try {
            $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);
            $this->fail('Viewer should not refresh campaign workspaces.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $this->assertSame('ready', (string) ($this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId)['status'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $page = $this->runWebEndpoint('public/marketing_campaign_workspace.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $fixture['campaign_id']],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_campaign_workspace.php');
        $this->assertStringContainsString('Marketing Campaign Workspace', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Release QA Campaign', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Release QA Brief', (string) ($page['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Campaign Workspace Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            $this->assertNull((new Marketing())->getMarketingCampaignWorkspace($fixture['campaign_id']));
            (new Marketing())->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);
            $this->fail('Campaign workspace refresh should not accept cross-workspace campaigns.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseSeventyThreeCampaignOperatingRoomConnectsLaunchAndMeasurementFlow(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $this->marketing->createAudienceActivation([
            'activation_name' => 'Operating Room Audience',
            'activation_type' => 'launch',
            'status' => 'active',
            'audience_segment_id' => $fixture['segment_id'],
            'campaign_id' => $fixture['campaign_id'],
            'campaign_brief_id' => $fixture['brief_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $review = $this->marketing->evaluateLaunchReadiness([
            'launch_name' => 'Operating Room Readiness',
            'launch_date' => date('Y-m-d', strtotime('+7 days')),
            'campaign_brief_id' => $fixture['brief_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'checklist' => "Audience confirmed\nManual export ready",
            'created_by' => $this->userId,
        ], $this->userId);
        $this->marketing->prepareLaunchControl([
            'launch_readiness_review_id' => (int) $review['id'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ], $this->userId);
        $this->marketing->createCampaignLaunchChecklist([
            'title' => 'Operating Room Launch Checklist',
            'campaign_id' => $fixture['campaign_id'],
            'campaign_brief_id' => $fixture['brief_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'created_by' => $this->userId,
        ], $this->userId);

        $workspace = $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);
        $this->assertGreaterThanOrEqual(1, (int) ($workspace['linked_summary_json']['launch_checklists']['count'] ?? 0));
        $room = $this->marketing->getCampaignOperatingRoom($fixture['campaign_id']);
        $this->assertSame($fixture['campaign_id'], (int) ($room['campaign_id'] ?? 0));
        $this->assertTrue((bool) ($room['guardrails']['manual_first'] ?? false));
        $this->assertFalse((bool) ($room['guardrails']['external_publish'] ?? true));
        $this->assertContains('Launch Control', array_column((array) ($room['stages'] ?? []), 'label'));
        $this->assertContains('Measurement Loop', array_column((array) ($room['stages'] ?? []), 'label'));
        $launchStage = null;
        foreach ((array) ($room['stages'] ?? []) as $stage) {
            if ((string) ($stage['key'] ?? '') === 'launch') {
                $launchStage = $stage;
                break;
            }
        }
        $this->assertIsArray($launchStage);
        $this->assertGreaterThanOrEqual(1, (int) ($launchStage['linked_summary']['launch_checklists']['count'] ?? 0));

        $page = $this->runWebEndpoint('public/marketing_campaign_workspace.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $fixture['campaign_id']],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_campaign_workspace.php phase 73');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Campaign Operating Room', $body);
        $this->assertStringContainsString('Launch Checklists', $body);
        $this->assertStringContainsString('Measurement Loop', $body);
        $this->assertStringContainsString('Manual-first guardrail', $body);
    }

    public function testPhaseSeventyNineCampaignExecutionReadinessMatrix(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $this->marketing->createAudienceActivation([
            'activation_name' => 'Execution Readiness Audience',
            'activation_type' => 'launch',
            'status' => 'active',
            'audience_segment_id' => $fixture['segment_id'],
            'campaign_id' => $fixture['campaign_id'],
            'campaign_brief_id' => $fixture['brief_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $review = $this->marketing->evaluateLaunchReadiness([
            'launch_name' => 'Execution Readiness Review',
            'launch_date' => date('Y-m-d', strtotime('+6 days')),
            'campaign_brief_id' => $fixture['brief_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'checklist' => "Audience confirmed\nManual export ready\nUTM checked",
            'created_by' => $this->userId,
        ], $this->userId);
        $this->marketing->prepareLaunchControl([
            'launch_readiness_review_id' => (int) $review['id'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ], $this->userId);
        $this->marketing->createCampaignLaunchChecklist([
            'title' => 'Execution Readiness Checklist',
            'campaign_id' => $fixture['campaign_id'],
            'campaign_brief_id' => $fixture['brief_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'created_by' => $this->userId,
        ], $this->userId);
        $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);

        $readiness = $this->marketing->getCampaignExecutionReadiness($fixture['campaign_id']);
        $this->assertSame('ready_for_manual_execution', (string) ($readiness['status'] ?? ''));
        $this->assertTrue((bool) ($readiness['ready_to_launch'] ?? false));
        $this->assertSame([], (array) ($readiness['blocked_checks'] ?? ['unexpected']));
        $this->assertGreaterThanOrEqual(100, (int) ($readiness['score'] ?? 0));
        $this->assertContains('Campaign brief and strategy', array_column((array) ($readiness['checks'] ?? []), 'label'));
        $this->assertFalse((bool) ($readiness['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($readiness['guardrails']['external_publish'] ?? true));

        $page = $this->runWebEndpoint('public/marketing_campaign_workspace.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $fixture['campaign_id']],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_campaign_workspace.php phase 79');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Campaign Execution Readiness', $body);
        $this->assertStringContainsString('Required manual-launch gates', $body);
        $this->assertStringContainsString('Ready For Manual Execution', $body);
        $this->assertStringContainsString('Execution readiness is a manual launch gate', $body);
    }

    public function testPhaseEightyTwoCampaignLaunchScorecardTracksManualLaunchGates(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $initial = $this->marketing->getCampaignLaunchScorecard($fixture['campaign_id']);
        $this->assertSame('blocked', (string) ($initial['status'] ?? ''));
        $initialGates = [];
        foreach ((array) ($initial['gates'] ?? []) as $gate) {
            $initialGates[(string) ($gate['key'] ?? '')] = $gate;
        }
        foreach (['strategy_brief', 'audience_selected', 'offer_selected', 'landing_page_ready', 'content_ready', 'approval_complete', 'manual_launch_checklist_complete'] as $expectedGate) {
            $this->assertArrayHasKey($expectedGate, $initialGates);
        }
        $this->assertSame('ready', (string) ($initialGates['offer_selected']['status'] ?? ''));
        $this->assertSame('blocked', (string) ($initialGates['content_ready']['status'] ?? ''));
        $this->assertFalse((bool) ($initial['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($initial['guardrails']['external_publish'] ?? true));

        $approvalId = $this->marketing->requestContentReview($fixture['content_id'], $this->userId, $this->userId, date('Y-m-d\TH:i', strtotime('+1 day')));
        $this->assertTrue($this->marketing->decideContentApproval($approvalId, 'approved', 'Ready for launch scorecard.', $this->userId));
        $channelBundleId = $this->marketing->createChannelExportBundle($fixture['distribution_post_id'], $this->userId);
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Scorecard Ready Connector',
            'connector_type' => 'email',
            'status' => 'configured',
            'execution_mode' => 'dry_run',
            'capabilities' => ['csv_export', 'unsubscribe_check', 'dry_run'],
            'setup_checklist' => ['consent policy reviewed', 'suppression list reviewed', 'manual export owner named'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->marketing->evaluateChannelConnectorReadiness($connectorId, $this->userId);
        $this->marketing->createCampaignLaunchChecklist([
            'title' => 'Scorecard Launch Checklist',
            'campaign_id' => $fixture['campaign_id'],
            'campaign_brief_id' => $fixture['brief_id'],
            'audience_segment_id' => $fixture['segment_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'channel_export_bundle_id' => $channelBundleId,
            'connector_id' => $connectorId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ], $this->userId);

        $scorecard = $this->marketing->getCampaignLaunchScorecard($fixture['campaign_id']);
        $this->assertSame('ready_for_manual_launch', (string) ($scorecard['status'] ?? ''));
        $this->assertTrue((bool) ($scorecard['ready_for_manual_launch'] ?? false));
        $this->assertSame([], (array) ($scorecard['blocked_gates'] ?? ['unexpected']));
        $this->assertGreaterThanOrEqual(100, (int) ($scorecard['score'] ?? 0));

        $page = $this->runWebEndpoint('public/marketing_campaign_workspace.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $fixture['campaign_id']],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_campaign_workspace.php phase 82');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Campaign Launch Scorecard', $body);
        $this->assertStringContainsString('Final manual-launch checklist', $body);
        $this->assertStringContainsString('Ready For Manual Launch', $body);
        $this->assertStringContainsString('Launch scorecard is an operator checklist only', $body);
    }

    public function testPhaseEightySevenCampaignLaunchPacketGroupsManualLaunchEvidence(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);

        $initial = $this->marketing->getCampaignLaunchPacket($fixture['campaign_id']);
        $this->assertSame('blocked', (string) ($initial['status'] ?? ''));
        $this->assertGreaterThanOrEqual(1, (int) ($initial['counts']['blocked_gates'] ?? 0));
        $this->assertContains('Strategy Packet', array_column((array) ($initial['sections'] ?? []), 'label'));
        $this->assertContains('Production Packet', array_column((array) ($initial['sections'] ?? []), 'label'));
        $this->assertFalse((bool) ($initial['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($initial['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($initial['guardrails']['external_ads'] ?? true));

        $approvalId = $this->marketing->requestContentReview($fixture['content_id'], $this->userId, $this->userId, date('Y-m-d\TH:i', strtotime('+1 day')));
        $this->marketing->decideContentApproval($approvalId, 'approved', 'Packet approval complete.', $this->userId);
        $channelBundleId = $this->marketing->createChannelExportBundle($fixture['distribution_post_id'], $this->userId);
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Packet Ready Connector',
            'connector_type' => 'email',
            'status' => 'configured',
            'execution_mode' => 'dry_run',
            'capabilities' => ['csv_export', 'unsubscribe_check', 'dry_run'],
            'setup_checklist' => ['consent policy reviewed', 'suppression list reviewed', 'manual export owner named'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->marketing->evaluateChannelConnectorReadiness($connectorId, $this->userId);
        $this->marketing->createCampaignLaunchChecklist([
            'title' => 'Packet Launch Checklist',
            'campaign_id' => $fixture['campaign_id'],
            'campaign_brief_id' => $fixture['brief_id'],
            'audience_segment_id' => $fixture['segment_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'channel_export_bundle_id' => $channelBundleId,
            'connector_id' => $connectorId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ], $this->userId);

        $packet = $this->marketing->getCampaignLaunchPacket($fixture['campaign_id']);
        $this->assertSame('ready_for_manual_launch', (string) ($packet['status'] ?? ''));
        $this->assertTrue((bool) ($packet['ready_for_manual_launch'] ?? false));
        $this->assertSame(0, (int) ($packet['counts']['blocked_gates'] ?? -1));
        $this->assertGreaterThanOrEqual(1, (int) ($packet['counts']['approved_reviews'] ?? 0));
        $sectionsByKey = [];
        foreach ((array) ($packet['sections'] ?? []) as $section) {
            $sectionsByKey[(string) ($section['key'] ?? '')] = $section;
        }
        $this->assertSame('ready', (string) ($sectionsByKey['strategy']['status'] ?? ''));
        $this->assertSame('ready', (string) ($sectionsByKey['production']['status'] ?? ''));

        $page = $this->runWebEndpoint('public/marketing_campaign_workspace.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $fixture['campaign_id']],
        ]);
        $body = (string) ($page['body'] ?? '');
        $this->assertEndpointHealthy($page, 200, 'marketing_campaign_workspace.php phase 87');
        $this->assertStringContainsString('Campaign Launch Packet', $body);
        $this->assertStringContainsString('Launch Packet Actions', $body);
        $this->assertStringContainsString('Strategy Packet', $body);
        $this->assertStringContainsString('Launch packet is execution evidence', $body);
    }

    public function testPhaseNinetyOneCampaignLaunchWorkspaceV2BuildsRiskRegisterAndManualExportBundle(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);

        $initial = $this->marketing->getCampaignLaunchWorkspaceV2Summary($fixture['campaign_id']);
        $this->assertSame('blocked', (string) ($initial['status'] ?? ''));
        $this->assertGreaterThan(0, (int) ($initial['counts']['risks_high'] ?? 0));
        $this->assertFalse((bool) ($initial['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($initial['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($initial['guardrails']['external_ads'] ?? true));

        $bundleLabels = array_column((array) ($initial['manual_export_bundle'] ?? []), 'label');
        foreach (['Strategy Brief', 'Audience Snapshot', 'Landing Page', 'Content Pack', 'Distribution Export', 'Tracking Links', 'Approval Evidence', 'Launch Checklist', 'Measurement Baseline'] as $label) {
            $this->assertContains($label, $bundleLabels);
        }
        $riskLabels = array_column((array) ($initial['risk_register'] ?? []), 'label');
        $this->assertContains('Launch content approved', $riskLabels);
        $this->assertContains('Approval complete', $riskLabels);

        $this->marketing->createAudienceActivation([
            'activation_name' => 'Launch Workspace V2 Audience',
            'activation_type' => 'launch',
            'status' => 'active',
            'audience_segment_id' => $fixture['segment_id'],
            'campaign_id' => $fixture['campaign_id'],
            'campaign_brief_id' => $fixture['brief_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $review = $this->marketing->evaluateLaunchReadiness([
            'launch_name' => 'Launch Workspace V2 Review',
            'launch_date' => date('Y-m-d', strtotime('+6 days')),
            'campaign_brief_id' => $fixture['brief_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'checklist' => "Audience confirmed\nManual export ready\nUTM checked",
            'created_by' => $this->userId,
        ], $this->userId);
        $this->marketing->prepareLaunchControl([
            'launch_readiness_review_id' => (int) $review['id'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ], $this->userId);
        $approvalId = $this->marketing->requestContentReview($fixture['content_id'], $this->userId, $this->userId, date('Y-m-d\TH:i', strtotime('+1 day')));
        $this->marketing->decideContentApproval($approvalId, 'approved', 'Launch Workspace V2 approval complete.', $this->userId);
        $channelBundleId = $this->marketing->createChannelExportBundle($fixture['distribution_post_id'], $this->userId);
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Launch Workspace V2 Connector',
            'connector_type' => 'email',
            'status' => 'configured',
            'execution_mode' => 'dry_run',
            'capabilities' => ['csv_export', 'unsubscribe_check', 'dry_run'],
            'setup_checklist' => ['consent policy reviewed', 'suppression list reviewed', 'manual export owner named'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->marketing->evaluateChannelConnectorReadiness($connectorId, $this->userId);
        $this->marketing->createCampaignLaunchChecklist([
            'title' => 'Launch Workspace V2 Checklist',
            'campaign_id' => $fixture['campaign_id'],
            'campaign_brief_id' => $fixture['brief_id'],
            'audience_segment_id' => $fixture['segment_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'channel_export_bundle_id' => $channelBundleId,
            'connector_id' => $connectorId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ], $this->userId);
        $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);

        $ready = $this->marketing->getCampaignLaunchWorkspaceV2Summary($fixture['campaign_id']);
        $this->assertSame('ready_for_manual_launch', (string) ($ready['status'] ?? ''));
        $this->assertTrue((bool) ($ready['ready_for_manual_launch'] ?? false));
        $this->assertSame(0, (int) ($ready['counts']['risks_high'] ?? -1));
        $this->assertGreaterThanOrEqual(8, (int) ($ready['counts']['export_items_ready'] ?? 0));
        $this->assertNotEmpty((array) ($ready['integration_links'] ?? []));

        $page = $this->runWebEndpoint('public/marketing_campaign_workspace.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $fixture['campaign_id']],
        ]);
        $body = (string) ($page['body'] ?? '');
        $this->assertEndpointHealthy($page, 200, 'marketing_campaign_workspace.php phase 91');
        $this->assertStringContainsString('Launch Workspace Command View', $body);
        $this->assertStringContainsString('Risk Register', $body);
        $this->assertStringContainsString('Manual Export Bundle', $body);
        $this->assertStringContainsString('Launch Workspace V2 packages evidence', $body);
    }

    public function testPhaseNinetyTwoOperatorExportPacksPersistManualHandoffSnapshots(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);

        $snapshot = $this->marketing->buildOperatorExportPackSnapshot($fixture['campaign_id']);
        $this->assertSame('blocked', (string) ($snapshot['status'] ?? ''));
        $this->assertNotEmpty((array) ($snapshot['risk_snapshot'] ?? []));
        $this->assertContains('Content Pack', array_column((array) ($snapshot['copy_ready_sections'] ?? []), 'label'));
        $this->assertFalse((bool) ($snapshot['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($snapshot['guardrails']['external_publish'] ?? true));

        $packId = $this->marketing->createOperatorExportPack($fixture['campaign_id'], $this->userId);
        $pack = $this->marketing->getOperatorExportPack($packId);
        $this->assertNotNull($pack);
        $this->assertSame($fixture['campaign_id'], (int) ($pack['campaign_id'] ?? 0));
        $this->assertSame('blocked', (string) ($pack['status'] ?? ''));
        $this->assertNotEmpty((array) ($pack['risk_snapshot_json'] ?? []));
        $this->assertContains('Operator Export Packs page guide', array_column(\CRM\Services\MarketplacePageExplainerService::marketingSettingsPageDefinitions(), 'label'));

        $campaignPacks = $this->marketing->listOperatorExportPacks(['campaign_id' => $fixture['campaign_id']], 10, 0);
        $this->assertCount(1, $campaignPacks);
        $this->assertSame($packId, (int) ($campaignPacks[0]['id'] ?? 0));

        $page = $this->runWebEndpoint('public/marketing_operator_export_packs.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['id' => $packId],
        ]);
        $body = (string) ($page['body'] ?? '');
        $this->assertEndpointHealthy($page, 200, 'marketing_operator_export_packs.php phase 92');
        $this->assertStringContainsString('Operator Export Packs', $body);
        $this->assertStringContainsString('Copy-Ready Sections', $body);
        $this->assertStringContainsString('Risk Snapshot', $body);
        $this->assertStringContainsString('No sending or publishing happens here', $body);

        $otherWorkspaceId = $this->createWorkspace('Operator Export Pack Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getOperatorExportPack($packId));
        $this->assertSame([], $otherMarketing->listOperatorExportPacks([], 10, 0));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        $this->assertTrue($this->marketing->archiveOperatorExportPack($packId, $this->userId));
        $archived = $this->marketing->getOperatorExportPack($packId);
        $this->assertSame('archived', (string) ($archived['status'] ?? ''));
    }

    public function testPhaseNinetyThreeCampaignCreativeRequirementsDriveLaunchReadiness(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);

        $missingMediaRequirementId = $this->marketing->createCampaignCreativeRequirement([
            'campaign_id' => $fixture['campaign_id'],
            'title' => 'Launch hero visual',
            'requirement_type' => 'image',
            'channel' => 'website',
            'placement' => 'hero',
            'priority' => 'high',
            'creative_brief_id' => $fixture['creative_brief_id'],
            'content_item_id' => $fixture['content_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'created_by' => $this->userId,
            'owner_user_id' => $this->userId,
        ]);
        $blockedMediaId = $this->marketing->createMediaFile([
            'title' => 'Blocked launch video',
            'media_type' => 'video',
            'source_type' => 'url',
            'source_url' => 'https://example.com/blocked-launch-video.mp4',
            'approval_status' => 'blocked',
            'license_status' => 'restricted',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createCampaignCreativeRequirement([
            'campaign_id' => $fixture['campaign_id'],
            'title' => 'Launch explainer video',
            'requirement_type' => 'video',
            'channel' => 'youtube',
            'placement' => 'launch video',
            'priority' => 'urgent',
            'media_file_id' => $blockedMediaId,
            'asset_request_id' => $fixture['asset_request_id'],
            'created_by' => $this->userId,
            'owner_user_id' => $this->userId,
        ]);
        $readyMediaId = $this->marketing->createMediaFile([
            'title' => 'Approved email banner',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/approved-email-banner.jpg',
            'alt_text' => 'Approved campaign email banner',
            'approval_status' => 'approved',
            'license_status' => 'owned',
            'accessibility_status' => 'ready',
            'created_by' => $this->userId,
        ]);
        $readyRequirementId = $this->marketing->createCampaignCreativeRequirement([
            'campaign_id' => $fixture['campaign_id'],
            'title' => 'Email banner',
            'requirement_type' => 'email_banner',
            'channel' => 'email',
            'placement' => 'header',
            'priority' => 'normal',
            'media_file_id' => $readyMediaId,
            'created_by' => $this->userId,
            'owner_user_id' => $this->userId,
        ]);

        $missing = $this->marketing->getCampaignCreativeRequirement($missingMediaRequirementId);
        $ready = $this->marketing->getCampaignCreativeRequirement($readyRequirementId);
        $this->assertSame('in_progress', (string) ($missing['status'] ?? ''));
        $this->assertSame('ready', (string) ($ready['status'] ?? ''));
        $this->assertSame('Approved media is attached and ready for manual campaign export.', (string) ($ready['readiness_json']['message'] ?? ''));

        $summary = $this->marketing->getCampaignCreativeReadinessSummary($fixture['campaign_id']);
        $this->assertSame('blocked', (string) ($summary['status'] ?? ''));
        $this->assertSame(3, (int) ($summary['counts']['total'] ?? 0));
        $this->assertSame(1, (int) ($summary['counts']['ready'] ?? 0));
        $this->assertSame(1, (int) ($summary['counts']['blocked'] ?? 0));
        $this->assertSame(1, (int) ($summary['counts']['missing_media'] ?? 0));
        $this->assertFalse((bool) ($summary['guardrails']['external_publish'] ?? true));

        $workspace = $this->marketing->getCampaignLaunchWorkspaceV2Summary($fixture['campaign_id']);
        $this->assertContains('Creative Assets', array_column((array) ($workspace['manual_export_bundle'] ?? []), 'label'));
        $this->assertContains('Creative Requirements', array_column((array) ($workspace['integration_links'] ?? []), 'label'));
        $creativeRisks = array_filter(
            (array) ($workspace['risk_register'] ?? []),
            static fn(array $risk): bool => (string) ($risk['source'] ?? '') === 'Creative Readiness'
        );
        $this->assertNotEmpty($creativeRisks);

        $creativeWorkspace = $this->marketing->getAiCreativeWorkspaceSummary($this->userId);
        $this->assertGreaterThanOrEqual(3, (int) ($creativeWorkspace['counts']['campaign_creative_requirements'] ?? 0));
        $this->assertContains('Campaign Needs', array_column((array) ($creativeWorkspace['pipeline_stages'] ?? []), 'label'));

        $page = $this->runWebEndpoint('public/marketing_creative.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $fixture['campaign_id']],
        ]);
        $body = (string) ($page['body'] ?? '');
        $this->assertEndpointHealthy($page, 200, 'marketing_creative.php phase 93');
        $this->assertStringContainsString('Campaign Creative Requirements', $body);
        $this->assertStringContainsString('Add Campaign Requirement', $body);
        $this->assertStringContainsString('Approved media is attached and ready for manual campaign export.', $body);

        $otherWorkspaceId = $this->createWorkspace('Campaign Creative Requirement Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getCampaignCreativeRequirement($missingMediaRequirementId));
        $this->assertSame([], $otherMarketing->listCampaignCreativeRequirements([], 10, 0));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseNinetySevenCampaignExecutionActionCenterRanksOperatorWork(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);

        $initial = $this->marketing->getCampaignExecutionActionCenter($fixture['campaign_id'], $this->userId);
        $this->assertSame('blocked', (string) ($initial['status'] ?? ''));
        $this->assertGreaterThan(0, (int) ($initial['counts']['urgent_actions'] ?? 0));
        $this->assertFalse((bool) ($initial['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($initial['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($initial['guardrails']['external_ads'] ?? true));
        $this->assertContains('Create export pack', array_column((array) ($initial['quick_commands'] ?? []), 'label'));
        $this->assertContains('Save action snapshot', array_column((array) ($initial['quick_commands'] ?? []), 'label'));
        $this->assertContains('Risk Register', array_column((array) ($initial['actions'] ?? []), 'source'));

        $packId = $this->marketing->createOperatorExportPack($fixture['campaign_id'], $this->userId);
        $withPack = $this->marketing->getCampaignExecutionActionCenter($fixture['campaign_id'], $this->userId);
        $this->assertGreaterThanOrEqual(1, (int) ($withPack['counts']['export_packs'] ?? 0));
        $this->assertSame($packId, (int) ($withPack['operator_snapshot']['latest_export_pack']['id'] ?? 0));

        $snapshot = $this->marketing->createCampaignExecutionActionSnapshot($fixture['campaign_id'], $this->userId);
        $this->assertSame('current', (string) ($snapshot['snapshot_status'] ?? ''));
        $this->assertSame($fixture['campaign_id'], (int) ($snapshot['campaign_id'] ?? 0));
        $this->assertGreaterThan(0, (int) ($snapshot['actions_total'] ?? 0));
        $this->assertNotEmpty((array) ($snapshot['actions_json'] ?? []));
        $this->assertFalse((bool) ($snapshot['guardrails_json']['external_publish'] ?? true));

        $latestSnapshot = $this->marketing->getLatestCampaignExecutionActionSnapshot($fixture['campaign_id']);
        $this->assertSame((int) ($snapshot['id'] ?? 0), (int) ($latestSnapshot['id'] ?? 0));
        $withSnapshot = $this->marketing->getCampaignExecutionActionCenter($fixture['campaign_id'], $this->userId);
        $this->assertSame((int) ($snapshot['id'] ?? 0), (int) ($withSnapshot['operator_snapshot']['latest_action_snapshot']['id'] ?? 0));

        $secondSnapshot = $this->marketing->createCampaignExecutionActionSnapshot($fixture['campaign_id'], $this->userId);
        $superseded = $this->marketing->getCampaignExecutionActionSnapshot((int) ($snapshot['id'] ?? 0));
        $this->assertSame('superseded', (string) ($superseded['snapshot_status'] ?? ''));
        $this->assertSame('current', (string) ($secondSnapshot['snapshot_status'] ?? ''));

        $page = $this->runWebEndpoint('public/marketing_campaign_workspace.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $fixture['campaign_id']],
        ]);
        $body = (string) ($page['body'] ?? '');
        $this->assertEndpointHealthy($page, 200, 'marketing_campaign_workspace.php phase 97');
        $this->assertStringContainsString('Execution Action Center', $body);
        $this->assertStringContainsString('Ranked next actions', $body);
        $this->assertStringContainsString('Create export pack', $body);
        $this->assertStringContainsString('Save action snapshot', $body);
        $this->assertStringContainsString('Action Snapshot', $body);
        $this->assertStringContainsString('does not send, publish, start ads, or call external channel APIs', $body);

        $otherWorkspaceId = $this->createWorkspace('Execution Action Center Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getCampaignExecutionActionSnapshot((int) ($secondSnapshot['id'] ?? 0)));
        $this->assertSame([], $otherMarketing->listCampaignExecutionActionSnapshots([], 10, 0));
        try {
            $otherMarketing->getCampaignExecutionActionCenter($fixture['campaign_id'], $this->userId);
            $this->fail('Cross-workspace campaign action center should not load.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseFortyNineLaunchSnapshotReportingSurfacesExecutionEvidence(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);
        $snapshot = $this->marketing->createCampaignExecutionActionSnapshot($fixture['campaign_id'], $this->userId);

        $summary = $this->marketing->getMarketingExecutionEvidenceSummary(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+1 day')), 3);
        $this->assertContains((string) ($summary['status'] ?? ''), ['ready', 'needs_attention', 'blocked']);
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['current'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['campaigns_with_current_snapshots'] ?? 0));
        $this->assertSame((int) ($snapshot['campaign_id'] ?? 0), (int) ($summary['latest_snapshots'][0]['campaign_id'] ?? 0));
        $this->assertFalse((bool) ($summary['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($summary['guardrails']['external_send'] ?? true));

        $analytics = $this->marketing->getMarketingAnalyticsSummary('weekly');
        $this->assertGreaterThanOrEqual(1, (int) ($analytics['execution_evidence']['counts']['current'] ?? 0));

        $exportId = $this->marketing->createMarketingReportExport('operator', [
            'report_format' => 'json',
            'period_start' => date('Y-m-d', strtotime('-1 day')),
            'period_end' => date('Y-m-d', strtotime('+1 day')),
        ], $this->userId);
        $export = $this->marketing->listMarketingReportExports(['id' => $exportId], 1, 0)[0] ?? [];
        $this->assertGreaterThanOrEqual(1, (int) ($export['payload_json']['execution_evidence']['counts']['current'] ?? 0));
        $this->assertFalse((bool) ($export['payload_json']['execution_evidence']['guardrails']['external_send'] ?? true));

        foreach ([
            'public/marketing.php' => 'marketing.php execution evidence',
            'public/marketing_admin.php' => 'marketing_admin.php execution evidence',
            'public/marketing_weekly_report.php' => 'marketing_weekly_report.php execution evidence',
            'public/marketing_monthly_report.php' => 'marketing_monthly_report.php execution evidence',
        ] as $endpoint => $label) {
            $response = $this->runWebEndpoint($endpoint, $this->webSession('owner'), ['method' => 'GET']);
            $body = (string) ($response['body'] ?? '');
            $this->assertEndpointHealthy($response, 200, $label);
            $this->assertStringContainsString('Execution Evidence', $body);
        }

        $otherWorkspaceId = $this->createWorkspace('Execution Evidence Reporting Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $otherSummary = $otherMarketing->getMarketingExecutionEvidenceSummary(null, null, 3);
        $this->assertSame(0, (int) ($otherSummary['counts']['total'] ?? -1));
        $this->assertSame([], $otherMarketing->listCampaignExecutionActionSnapshots([], 10, 0));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseFiftyDecisionCenterCapturesProductDecisionsAndRoleLimits(): void
    {
        Database::execute(
            "INSERT INTO campaigns (workspace_id, uuid, name, status, created_by)
             VALUES (?, UUID(), ?, 'active', ?)",
            [$this->workspaceId, 'Phase 50 Decision Campaign', $this->userId]
        );
        $campaignId = (int) Database::lastInsertId();

        $before = $this->marketing->getMarketingDecisionCenter($this->userId, 6);
        $this->assertArrayHasKey('suggested_decisions', $before);
        $this->assertFalse((bool) ($before['guardrails']['external_publish'] ?? true));

        $decisionId = $this->marketing->createMarketingDecision([
            'title' => 'Decide launch owner for partner campaign',
            'decision_type' => 'launch_readiness',
            'priority' => 'urgent',
            'source_type' => 'campaign',
            'source_id' => $campaignId,
            'campaign_id' => $campaignId,
            'recommended_action' => 'Assign one owner to clear launch blockers before distribution.',
            'rationale' => 'The campaign needs a single accountable operator.',
            'expected_impact' => 'Launch work becomes auditable and less fragmented.',
            'created_by' => $this->userId,
            'owner_user_id' => $this->userId,
        ]);

        $decision = $this->marketing->getMarketingDecision($decisionId);
        $this->assertSame('Decide launch owner for partner campaign', (string) ($decision['title'] ?? ''));
        $this->assertSame('urgent', (string) ($decision['priority'] ?? ''));
        $this->assertSame($campaignId, (int) ($decision['campaign_id'] ?? 0));
        $this->assertFalse((bool) ($decision['metadata_json']['external_send'] ?? true));

        $center = $this->marketing->getMarketingDecisionCenter($this->userId, 6);
        $this->assertGreaterThanOrEqual(1, (int) ($center['counts']['open'] ?? 0));
        $this->assertSame($decisionId, (int) ($center['saved_decisions'][0]['id'] ?? 0));

        $this->marketing->decideMarketingDecision($decisionId, 'accepted', 'Owner assigned in the weekly launch review.', $this->userId);
        $accepted = $this->marketing->getMarketingDecision($decisionId);
        $this->assertSame('accepted', (string) ($accepted['decision_status'] ?? ''));
        $this->assertSame($this->userId, (int) ($accepted['decided_by'] ?? 0));

        $page = $this->runWebEndpoint('public/marketing_decisions.php', $this->webSession('owner'), ['method' => 'GET']);
        $body = (string) ($page['body'] ?? '');
        $this->assertEndpointHealthy($page, 200, 'marketing_decisions.php phase 50');
        $this->assertStringContainsString('Marketing Decision Center', $body);
        $this->assertStringContainsString('Decision Queue', $body);
        $this->assertStringContainsString('Suggested Decisions', $body);
        $this->assertStringContainsString('Manual-first boundary', $body);

        $commandCenter = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($commandCenter, 200, 'marketing.php phase 50 decision center');
        $this->assertStringContainsString('Decision Center', (string) ($commandCenter['body'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createMarketingDecision(['title' => 'Viewer decision']);
            $this->fail('Viewer should not create Marketing decisions.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $marketingDecisionId = $this->marketing->createMarketingDecision([
            'title' => 'Marketing role can record decision',
            'decision_type' => 'custom',
            'created_by' => $this->userId,
        ]);
        try {
            $this->marketing->archiveMarketingDecision($marketingDecisionId, $this->userId);
            $this->fail('Marketing role should not archive Marketing decisions.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $this->marketing->archiveMarketingDecision($marketingDecisionId, $this->userId);
        $this->assertSame('archived', (string) ($this->marketing->getMarketingDecision($marketingDecisionId)['decision_status'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Decision Center Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getMarketingDecision($decisionId));
        try {
            $otherMarketing->createMarketingDecision([
                'title' => 'Cross workspace campaign decision',
                'decision_type' => 'launch_readiness',
                'campaign_id' => $campaignId,
                'created_by' => $this->userId,
            ]);
            $this->fail('Cross-workspace campaign decision should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Linked record', $e->getMessage());
        }
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseFiftyOneCampaignExecutionConsoleCombinesLaunchEvidenceAndDecisions(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);
        $decisionId = $this->marketing->createMarketingDecision([
            'title' => 'Decide campaign launch scope',
            'decision_type' => 'launch_readiness',
            'priority' => 'high',
            'campaign_id' => $fixture['campaign_id'],
            'source_type' => 'campaign',
            'source_id' => $fixture['campaign_id'],
            'recommended_action' => 'Approve the launch scope before export packaging.',
            'created_by' => $this->userId,
        ]);
        $snapshot = $this->marketing->createCampaignExecutionActionSnapshot($fixture['campaign_id'], $this->userId);

        $console = $this->marketing->getCampaignExecutionConsole($fixture['campaign_id'], $this->userId);
        $this->assertSame($fixture['campaign_id'], (int) ($console['campaign_id'] ?? 0));
        $this->assertSame($decisionId, (int) ($console['decisions'][0]['id'] ?? 0));
        $this->assertSame((int) ($snapshot['id'] ?? 0), (int) ($console['latest_action_snapshot']['id'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($console['counts']['open_decisions'] ?? 0));
        $this->assertFalse((bool) ($console['guardrails']['external_send'] ?? true));

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $fixture['campaign_id']],
        ]);
        $body = (string) ($page['body'] ?? '');
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php phase 51 campaign console');
        $this->assertStringContainsString('Campaign Execution Console', $body);
        $this->assertStringContainsString('Action Center', $body);
        $this->assertStringContainsString('Execution Evidence', $body);
        $this->assertStringContainsString('Decisions', $body);
        $this->assertStringContainsString('Checklists And Packs', $body);
        $this->assertStringContainsString('does not send, publish, start ads, or call external channel APIs', $body);

        $otherWorkspaceId = $this->createWorkspace('Campaign Execution Console Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        try {
            $otherMarketing->getCampaignExecutionConsole($fixture['campaign_id'], $this->userId);
            $this->fail('Cross-workspace campaign execution console should not load.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseFiftyTwoCampaignCopilotRunsAreDraftSideAndWorkspaceScoped(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $this->marketing->refreshMarketingCampaignWorkspace($fixture['campaign_id'], $this->userId);
        $this->marketing->createCampaignExecutionActionSnapshot($fixture['campaign_id'], $this->userId);

        $run = $this->marketing->runCampaignCopilot($fixture['campaign_id'], 'operator_handoff', $this->userId);
        $this->assertSame('operator_handoff', (string) ($run['run_type'] ?? ''));
        $this->assertSame('completed', (string) ($run['status'] ?? ''));
        $this->assertFalse((bool) ($run['provider_metadata_json']['external_generation'] ?? true));
        $this->assertFalse((bool) ($run['result_json']['manual_first_boundary']['overwrites_records'] ?? true));
        $this->assertNotEmpty((array) ($run['result_json']['recommendations'] ?? []));

        $console = $this->marketing->getCampaignExecutionConsole($fixture['campaign_id'], $this->userId);
        $this->assertSame((int) ($run['id'] ?? 0), (int) ($console['copilot_runs'][0]['id'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($console['counts']['copilot_runs'] ?? 0));

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $fixture['campaign_id']],
        ]);
        $body = (string) ($page['body'] ?? '');
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php phase 52 copilot');
        $this->assertStringContainsString('Campaign AI Copilot', $body);
        $this->assertStringContainsString('Run Copilot', $body);
        $this->assertStringContainsString('Campaign copilot: operator handoff', $body);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->runCampaignCopilot($fixture['campaign_id'], 'readiness_summary', $this->userId);
            $this->fail('Viewer should not run campaign copilot.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $otherWorkspaceId = $this->createWorkspace('Campaign Copilot Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertSame([], $otherMarketing->listCampaignCopilotRuns(['campaign_id' => $fixture['campaign_id']], 10, 0));
        try {
            $otherMarketing->runCampaignCopilot($fixture['campaign_id'], 'readiness_summary', $this->userId);
            $this->fail('Cross-workspace campaign copilot should not run.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseOneHundredSixMediaProductionWorkflowTracksVisualWorkAndPermissions(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Phase 106 approved hero media',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/phase-106-hero.png',
            'alt_text' => 'Operator dashboard hero visual',
            'usage_rights' => 'owned',
            'approval_status' => 'approved',
            'license_status' => 'owned',
            'created_by' => $this->userId,
        ]);
        $requirementId = $this->marketing->createCampaignCreativeRequirement([
            'campaign_id' => $fixture['campaign_id'],
            'title' => 'Phase 106 hero visual requirement',
            'requirement_type' => 'landing_visual',
            'priority' => 'high',
            'content_item_id' => $fixture['content_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'media_file_id' => $mediaId,
            'created_by' => $this->userId,
            'owner_user_id' => $this->userId,
        ]);

        $workId = $this->marketing->createMediaProductionWorkItem([
            'title' => 'Create campaign hero visual pack',
            'work_type' => 'landing_visual',
            'priority' => 'urgent',
            'status' => 'assigned',
            'campaign_id' => $fixture['campaign_id'],
            'content_item_id' => $fixture['content_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'creative_brief_id' => $fixture['creative_brief_id'],
            'asset_request_id' => $fixture['asset_request_id'],
            'campaign_creative_requirement_id' => $requirementId,
            'media_file_id' => $mediaId,
            'requested_format' => 'PNG hero and 1:1 crop',
            'dimensions' => '1600x900, 1080x1080',
            'due_at' => date('Y-m-d H:i:s', strtotime('+2 days')),
            'assigned_to' => $this->userId,
            'owner_user_id' => $this->userId,
            'checklist' => "Alt text\nUsage rights\nMobile crop",
            'production_notes' => 'Keep copy and visual consistent with the campaign brief.',
            'created_by' => $this->userId,
        ]);

        $workItem = $this->marketing->getMediaProductionWorkItem($workId);
        $this->assertSame('Create campaign hero visual pack', (string) ($workItem['title'] ?? ''));
        $this->assertSame('landing_visual', (string) ($workItem['work_type'] ?? ''));
        $this->assertSame($fixture['campaign_id'], (int) ($workItem['campaign_id'] ?? 0));
        $this->assertGreaterThanOrEqual(90, (int) ($workItem['readiness_json']['score'] ?? 0));
        $this->assertFalse((bool) ($workItem['readiness_json']['external_publish'] ?? true));

        $summary = $this->marketing->getMediaProductionWorkflowSummary($this->userId);
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['open'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['assigned_to_me'] ?? 0));
        $this->assertContains($workId, array_map(static fn(array $item): int => (int) ($item['id'] ?? 0), (array) ($summary['open_items'] ?? [])));

        $this->marketing->updateMediaProductionWorkItem($workId, [
            'status' => 'ready',
            'media_file_id' => $mediaId,
            'checklist' => "Alt text\nUsage rights\nMobile crop\nApproved crop",
        ]);
        $updated = $this->marketing->getMediaProductionWorkItem($workId);
        $this->assertSame('ready', (string) ($updated['status'] ?? ''));
        $this->assertNotEmpty((string) ($updated['completed_at'] ?? ''));

        $console = $this->marketing->getCampaignExecutionConsole($fixture['campaign_id'], $this->userId);
        $this->assertGreaterThanOrEqual(1, (int) ($console['counts']['media_production_work'] ?? 0));
        $this->assertSame($workId, (int) ($console['media_production_work'][0]['id'] ?? 0));

        $creativePage = $this->runWebEndpoint('public/marketing_creative.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($creativePage, 200, 'marketing_creative.php media production workflow');
        $this->assertStringContainsString('Media Production Workflow', (string) ($creativePage['body'] ?? ''));
        $this->assertStringContainsString('Create Media Work', (string) ($creativePage['body'] ?? ''));

        $executionPage = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $fixture['campaign_id']],
        ]);
        $this->assertEndpointHealthy($executionPage, 200, 'marketing_execution.php media production workflow');
        $this->assertStringContainsString('Media Production', (string) ($executionPage['body'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createMediaProductionWorkItem(['title' => 'Viewer media work']);
            $this->fail('Viewer should not create media production work.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->archiveMediaProductionWorkItem($workId, $this->userId);
            $this->fail('Marketing role should not archive media production work.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $this->marketing->archiveMediaProductionWorkItem($workId, $this->userId);
        $this->assertSame('archived', (string) ($this->marketing->getMediaProductionWorkItem($workId)['status'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Media Production Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getMediaProductionWorkItem($workId));
        try {
            $otherMarketing->createMediaProductionWorkItem([
                'title' => 'Cross workspace media production',
                'media_file_id' => $mediaId,
                'created_by' => $this->userId,
            ]);
            $this->fail('Cross-workspace media production link should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Linked record', $e->getMessage());
        }
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseNinetyEightCommandCenterSurfacesCrossSystemIntegrationHealth(): void
    {
        Database::execute(
            "INSERT INTO campaigns (workspace_id, uuid, name, status, created_by)
             VALUES (?, UUID(), ?, 'active', ?)",
            [$this->workspaceId, 'Phase 98 Integration Campaign', $this->userId]
        );
        $campaignId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO forms (workspace_id, uuid, name, fields, created_by)
             VALUES (?, UUID(), ?, ?, ?)",
            [$this->workspaceId, 'Phase 98 Lead Form', json_encode([]), $this->userId]
        );
        $formId = (int) Database::lastInsertId();
        $formUuid = (string) (Database::queryOne('SELECT uuid FROM forms WHERE id = ?', [$formId])['uuid'] ?? '');

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_by)
             VALUES (?, UUID(), ?, ?, ?, ?)",
            [$this->workspaceId, 'Phase', 'NinetyEight', 'phase98@example.com', $this->userId]
        );
        $contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO form_submissions (workspace_id, visitor_id, contact_id, form_id, form_definition_id, form_data, page_path)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$this->workspaceId, 'phase-98-visitor', $contactId, $formUuid, $formId, json_encode(['email' => 'phase98@example.com']), '/phase-98']
        );

        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value)
             VALUES (?, ?, ?, ?, ?, 'proposal', 12000)",
            [$this->workspaceId, 'Phase 98 Pipeline Deal', $contactId, $this->userId, $this->userId]
        );

        Database::execute(
            "INSERT INTO tasks (workspace_id, title, contact_id, assigned_to, created_by, status, priority)
             VALUES (?, ?, ?, ?, ?, 'pending', 'high')",
            [$this->workspaceId, 'Phase 98 Marketing Handoff Task', $contactId, $this->userId, $this->userId]
        );

        Database::execute(
            "INSERT INTO email_templates (workspace_id, name, slug, subject, body_html, body_text, category, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 'marketing', 1, ?)",
            [
                $this->workspaceId,
                'Phase 98 Email Template',
                'phase-98-email-' . uniqid(),
                'Phase 98 subject',
                '<p>Phase 98 body</p>',
                'Phase 98 body',
                $this->userId,
            ]
        );

        $this->marketing->createUtmLink([
            'campaign_id' => $campaignId,
            'url' => 'https://example.com/phase-98',
            'utm_source' => 'crm',
            'utm_medium' => 'manual',
            'utm_campaign' => 'phase98',
            'created_by' => $this->userId,
        ]);

        $readiness = $this->marketing->getMarketingIntegrationReadiness();
        $this->assertArrayHasKey('system_connections', $readiness);
        $this->assertArrayHasKey('crm_connections', $readiness['checks']);
        $this->assertSame('ready', (string) ($readiness['system_connections']['crm_campaigns']['status'] ?? ''));
        $this->assertSame('ready', (string) ($readiness['system_connections']['forms_and_submissions']['status'] ?? ''));
        $this->assertSame('ready', (string) ($readiness['system_connections']['contacts_and_companies']['status'] ?? ''));
        $this->assertSame('ready', (string) ($readiness['system_connections']['deals_pipeline']['status'] ?? ''));
        $this->assertSame('ready', (string) ($readiness['system_connections']['tasks_and_handoffs']['status'] ?? ''));
        $this->assertSame('ready', (string) ($readiness['system_connections']['email_templates']['status'] ?? ''));
        $this->assertGreaterThanOrEqual(6, (int) ($readiness['counts']['system_connections_ready'] ?? 0));
        $this->assertFalse((bool) ($readiness['external_publish'] ?? true));
        $this->assertFalse((bool) ($readiness['external_send'] ?? true));

        $summary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertArrayHasKey('integration_readiness', $summary);
        $this->assertSame(
            (int) ($readiness['counts']['system_connections_ready'] ?? 0),
            (int) ($summary['integration_readiness']['counts']['system_connections_ready'] ?? 0)
        );

        $page = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $body = (string) ($page['body'] ?? '');
        $this->assertEndpointHealthy($page, 200, 'marketing.php phase 98');
        $this->assertStringContainsString('Cross-System Integration Health', $body);
        $this->assertStringContainsString('CRM Campaigns', $body);
        $this->assertStringContainsString('Forms and Submissions', $body);
        $this->assertStringContainsString('Manual-first guardrail', $body);

        $otherWorkspaceId = $this->createWorkspace('Phase 98 Integration Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherReadiness = (new Marketing())->getMarketingIntegrationReadiness();
        $this->assertSame(0, (int) ($otherReadiness['system_connections']['crm_campaigns']['count'] ?? -1));
        $this->assertSame('attention', (string) ($otherReadiness['system_connections']['crm_campaigns']['status'] ?? ''));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseNinetyNineIntegrationReadinessUsesDashboardSafeLimitsAndDirectCounts(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 99 Load Discipline Content',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'draft_body' => 'Phase 99 content body.',
            'created_by' => $this->userId,
        ]);
        $channels = ['linkedin', 'facebook', 'instagram', 'x', 'email', 'newsletter', 'whatsapp', 'sms', 'ads', 'youtube', 'website', 'other'];
        foreach ($channels as $index => $channel) {
            $this->marketing->createDistributionPost([
                'content_item_id' => $contentId,
                'channel' => $channel,
                'planned_copy' => 'Phase 99 copy ' . $index,
                'status' => 'draft',
                'created_by' => $this->userId,
            ]);
        }

        $limited = $this->marketing->getMarketingIntegrationReadiness(5);
        $this->assertSame(5, (int) ($limited['performance']['detail_limit'] ?? 0));
        $this->assertTrue((bool) ($limited['performance']['summary_counts_are_direct'] ?? false));
        $this->assertTrue((bool) ($limited['performance']['heavy_detail_lists_limited'] ?? false));
        $this->assertSame(12, (int) ($limited['counts']['distribution_posts'] ?? 0));
        $this->assertSame(12, (int) ($limited['checks']['channel_exports']['counts']['distribution_posts'] ?? 0));
        $this->assertSame(12, (int) ($limited['checks']['channel_exports']['counts']['distribution_posts_without_bundle'] ?? 0));
        $this->assertSame(12, (int) ($limited['checks']['destination_checklists']['counts']['distribution_posts_missing_checklist'] ?? 0));
        $this->assertFalse((bool) ($limited['checks']['channel_exports']['ready'] ?? true));
        $this->assertFalse((bool) ($limited['checks']['destination_checklists']['ready'] ?? true));

        $summary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertSame(40, (int) ($summary['integration_readiness']['performance']['detail_limit'] ?? 0));
        $this->assertSame(12, (int) ($summary['integration_readiness']['counts']['distribution_posts'] ?? 0));
        $this->assertArrayHasKey('page_speed_profile', $summary);
        $this->assertTrue((bool) ($summary['page_speed_profile']['direct_summary_counts'] ?? false));
        $this->assertTrue((bool) ($summary['page_speed_profile']['heavy_detail_lists_limited'] ?? false));
        $this->assertSame(6, (int) ($summary['page_speed_profile']['dashboard_detail_cap'] ?? 0));
        $this->assertSame(40, (int) ($summary['page_speed_profile']['integration_detail_limit'] ?? 0));
        $this->assertSame(8, (int) ($summary['page_speed_profile']['crm_lifecycle_detail_limit'] ?? 0));
        $this->assertFalse((bool) ($summary['page_speed_profile']['manual_first_boundary']['external_publish'] ?? true));

        $page = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing.php phase 99 page speed');
        $this->assertStringContainsString('Page Speed Guardrails', (string) ($page['body'] ?? ''));

        $admin = $this->runWebEndpoint('public/marketing_admin.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($admin, 200, 'marketing_admin.php phase 99 page speed');
        $this->assertStringContainsString('Marketing Page Speed Guardrails', (string) ($admin['body'] ?? ''));
    }

    public function testPhaseOneHundredSevenDashboardSummaryCacheImprovesPageSpeedSafely(): void
    {
        $onboarding = $this->marketing->getMarketingOnboardingStatus();
        $first = $this->marketing->getCachedDashboardSummary($onboarding, 120);
        $this->assertSame('miss', (string) ($first['page_speed_profile']['cache_status'] ?? ''));
        $this->assertSame(120, (int) ($first['page_speed_profile']['cache_ttl_seconds'] ?? 0));

        $second = $this->marketing->getCachedDashboardSummary($onboarding, 120);
        $this->assertSame('hit', (string) ($second['page_speed_profile']['cache_status'] ?? ''));
        $this->assertSame((int) ($first['counts']['drafts'] ?? -1), (int) ($second['counts']['drafts'] ?? -2));
        $this->assertFalse((bool) ($second['page_speed_profile']['manual_first_boundary']['external_publish'] ?? true));

        $cacheStatus = $this->marketing->getMarketingDashboardCacheStatus();
        $this->assertTrue((bool) ($cacheStatus['available'] ?? false));
        $this->assertNotEmpty((array) ($cacheStatus['entries'] ?? []));
        $this->assertTrue((bool) ($cacheStatus['guardrails']['workspace_scoped'] ?? false));
        $this->assertFalse((bool) ($cacheStatus['guardrails']['external_api_calls'] ?? true));

        $page = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing.php dashboard summary cache');
        $this->assertStringContainsString('summary cache status', (string) ($page['body'] ?? ''));

        $admin = $this->runWebEndpoint('public/marketing_admin.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($admin, 200, 'marketing_admin.php dashboard summary cache');
        $this->assertStringContainsString('Dashboard Summary Cache', (string) ($admin['body'] ?? ''));

        $cleared = $this->marketing->clearMarketingDashboardSummaryCache();
        $this->assertGreaterThanOrEqual(1, $cleared);
        $afterClear = $this->marketing->getMarketingDashboardCacheStatus();
        $this->assertNull($afterClear['current']);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->clearMarketingDashboardSummaryCache();
            $this->fail('Marketing role should not clear dashboard summary cache.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);

        $this->marketing->getCachedDashboardSummary($onboarding, 120);
        $otherWorkspaceId = $this->createWorkspace('Dashboard Cache Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $otherStatus = $otherMarketing->getMarketingDashboardCacheStatus();
        $this->assertSame([], (array) ($otherStatus['entries'] ?? []));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseOneHundredEightMarketingActionRouterConnectsRecommendationsToCrmTools(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Action Router Campaign');
        $formId = $this->createForm($this->workspaceId, 'Action Router Form');
        $taskId = $this->createTask($this->workspaceId, 'Action Router Follow-up');

        $router = $this->marketing->getMarketingActionRouter($this->userId, 12, [
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'task_id' => $taskId,
        ]);
        $this->assertNotEmpty((array) ($router['items'] ?? []));
        $this->assertTrue((bool) ($router['guardrails']['workspace_scoped'] ?? false));
        $this->assertFalse((bool) ($router['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($router['guardrails']['external_publish'] ?? true));

        $snapshot = $this->marketing->generateMarketingActionRouterSnapshot([
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'task_id' => $taskId,
        ], $this->userId, 12);
        $this->assertGreaterThan(0, (int) ($snapshot['upserted'] ?? 0));
        $stored = $this->marketing->listMarketingActionRouterItems(['open' => true], 50, 0);
        $this->assertNotEmpty($stored);
        $first = $stored[0];
        $this->assertSame($this->workspaceId, (int) ($first['workspace_id'] ?? 0));
        $this->assertNotEmpty((string) ($first['target_href'] ?? ''));
        $this->assertStringNotContainsString('http://', (string) ($first['target_href'] ?? ''));
        $this->assertStringNotContainsString('https://', (string) ($first['target_href'] ?? ''));
        $this->assertFalse((bool) ($first['manual_first_boundary']['external_api_call'] ?? true));

        $beforeCount = count($stored);
        $this->marketing->generateMarketingActionRouterSnapshot([
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'task_id' => $taskId,
        ], $this->userId, 12);
        $this->assertSame($beforeCount, count($this->marketing->listMarketingActionRouterItems(['open' => true], 50, 0)));

        $this->assertTrue($this->marketing->updateMarketingActionRouterItemStatus((int) $first['id'], 'accepted', 'Operator accepted this router action.', $this->userId));
        $accepted = $this->marketing->getMarketingActionRouterItem((int) $first['id']);
        $this->assertSame('accepted', (string) ($accepted['action_status'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->generateMarketingActionRouterSnapshot([], $this->userId, 4);
            $this->fail('Viewer should not generate Action Router snapshots.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->updateMarketingActionRouterItemStatus((int) $first['id'], 'archived', 'Archive attempt.', $this->userId);
            $this->fail('Marketing role should not archive Action Router items.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $this->assertTrue($this->marketing->updateMarketingActionRouterItemStatus((int) $first['id'], 'archived', 'Owner archived this item.', $this->userId));

        $page = $this->runWebEndpoint('public/marketing_action_router.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_action_router.php owner');
        $this->assertStringContainsString('Marketing Action Router', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('CRM-Linked Action Queue', (string) ($page['body'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $viewerPage = $this->runWebEndpoint('public/marketing_action_router.php', $this->webSession('viewer'), ['method' => 'GET']);
        $this->assertEndpointHealthy($viewerPage, 200, 'marketing_action_router.php viewer');
        $this->assertStringNotContainsString('Refresh Router</button>', (string) ($viewerPage['body'] ?? ''));
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);

        $summary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertArrayHasKey('action_router', $summary);
        $this->assertArrayHasKey('action_router_open', (array) ($summary['counts'] ?? []));

        $otherWorkspaceId = $this->createWorkspace('Action Router Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertSame([], $otherMarketing->listMarketingActionRouterItems(['open' => true], 50, 0));
        $this->assertNull($otherMarketing->getMarketingActionRouterItem((int) $first['id']));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseOneHundredSixteenCrmIntegrationActionCenterSummarizesConnectedTools(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'CRM Integration Campaign');
        $formId = $this->createForm($this->workspaceId, 'CRM Integration Form');
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, lead_source, stage, assigned_to, created_by)
             VALUES (?, UUID(), 'Router', 'Lead', 'router-lead@example.com', 'form', 'qualified', ?, ?)",
            [$this->workspaceId, $this->userId, $this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, description, contact_id, assigned_to, created_by, stage, value, probability, currency, lead_source, campaign_id)
             VALUES (?, 'CRM Integration Deal', 'Deal evidence for Marketing integration.', ?, ?, ?, 'proposal', 12000.00, 65, 'USD', 'form', ?)",
            [$this->workspaceId, $contactId, $this->userId, $this->userId, $campaignId]
        );
        $dealId = (int) Database::lastInsertId();
        $taskId = $this->createTask($this->workspaceId, 'CRM Integration Follow-up');
        $contentId = $this->marketing->createContentItem([
            'title' => 'CRM Integration Content',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'draft',
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'task_id' => $taskId,
            'created_by' => $this->userId,
        ]);
        $landingId = $this->marketing->createLandingPage([
            'title' => 'CRM Integration Landing',
            'slug' => 'crm-integration-' . uniqid(),
            'headline' => 'Integrated campaign path',
            'body_sections' => "Proof\nThe campaign is tied to CRM evidence.",
            'cta_blocks' => "Book\nBook the handoff.",
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'conversion_goal' => 'demo_request',
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);

        $center = $this->marketing->getMarketingCrmIntegrationActionCenter([
            'deal_id' => $dealId,
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'task_id' => $taskId,
        ], $this->userId, 8);
        $this->assertSame('deal', (string) ($center['scope'] ?? ''));
        $this->assertArrayHasKey('crm_foundation', (array) ($center['lanes'] ?? []));
        $this->assertArrayHasKey('conversion_capture', (array) ($center['lanes'] ?? []));
        $this->assertArrayHasKey('execution_handoff', (array) ($center['lanes'] ?? []));
        $this->assertArrayHasKey('revenue_attribution', (array) ($center['lanes'] ?? []));
        $this->assertGreaterThan(0, (int) ($center['score'] ?? 0));
        $this->assertTrue((bool) ($center['performance']['page_load_guard'] ?? false));
        $this->assertFalse((bool) ($center['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($center['guardrails']['external_publish'] ?? true));
        $this->assertTrue((bool) ($center['guardrails']['uses_existing_crm_tools'] ?? false));
        $this->assertNotEmpty((array) ($center['next_actions'] ?? []));
        $this->assertSame($contentId, (int) ($this->marketing->getContentItem($contentId)['id'] ?? 0));
        $this->assertSame($landingId, (int) ($this->marketing->getLandingPage($landingId)['id'] ?? 0));

        $page = $this->runWebEndpoint('public/marketing_action_router.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => [
                'deal_id' => $dealId,
                'campaign_id' => $campaignId,
                'form_id' => $formId,
                'task_id' => $taskId,
            ],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_action_router.php CRM integration action center');
        $this->assertStringContainsString('CRM Integration Action Center', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Conversion Capture', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Next CRM Actions', (string) ($page['body'] ?? ''));
    }

    public function testPhaseOneHundredSeventeenOperatingRhythmConnectsDailyWeeklyQueues(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Operating Rhythm Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Operating Rhythm Content',
            'content_type' => 'newsletter',
            'channel' => 'email',
            'status' => 'draft',
            'production_stage' => 'drafting',
            'production_due_at' => date('Y-m-d\TH:i'),
            'scheduled_at' => date('Y-m-d\TH:i'),
            'campaign_id' => $campaignId,
            'owner_user_id' => $this->userId,
            'next_action' => 'Finish the draft and request review.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->requestContentReview($contentId, $this->userId, $this->userId, date('Y-m-d\TH:i', strtotime('-1 hour')));
        $this->marketing->createCalendarMilestone([
            'title' => 'Operating rhythm launch checkpoint',
            'milestone_type' => 'publish',
            'channel' => 'email',
            'milestone_date' => date('Y-m-d'),
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->createPlanningQueueItem([
            'title' => 'Operating rhythm overdue blocker',
            'item_type' => 'review',
            'priority' => 'high',
            'status' => 'blocked',
            'week_start' => date('Y-m-d', strtotime('monday this week')),
            'due_at' => date('Y-m-d\TH:i', strtotime('-1 day')),
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);

        $rhythm = $this->marketing->getMarketingOperatingRhythmCenter($this->userId, 8);
        $this->assertArrayHasKey('today_focus', (array) ($rhythm['lanes'] ?? []));
        $this->assertArrayHasKey('this_week', (array) ($rhythm['lanes'] ?? []));
        $this->assertArrayHasKey('blocked_work', (array) ($rhythm['lanes'] ?? []));
        $this->assertArrayHasKey('handoffs_execution', (array) ($rhythm['lanes'] ?? []));
        $this->assertGreaterThanOrEqual(1, (int) ($rhythm['counts']['today_focus'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($rhythm['counts']['this_week'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($rhythm['counts']['blocked_work'] ?? 0));
        $this->assertNotEmpty((array) ($rhythm['next_actions'] ?? []));
        $this->assertTrue((bool) ($rhythm['page_speed']['list_queries_are_capped'] ?? false));
        $this->assertFalse((bool) ($rhythm['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($rhythm['guardrails']['external_publish'] ?? true));

        $summary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertArrayHasKey('operating_rhythm', $summary);
        $this->assertGreaterThanOrEqual(1, (int) ($summary['operating_rhythm']['counts']['today_focus'] ?? 0));

        $page = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing.php operating rhythm');
        $this->assertStringContainsString('Marketing Operating Rhythm', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Today Focus', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Blocked And Overdue', (string) ($page['body'] ?? ''));
    }

    public function testPhaseOneHundredEighteenCampaignRevenueLoopConnectsCampaignsToRevenueEvidence(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $campaignId = (int) $fixture['campaign_id'];
        $this->marketing->createAudienceActivation([
            'activation_name' => 'Revenue Loop Audience Activation',
            'activation_type' => 'launch',
            'status' => 'active',
            'audience_segment_id' => $fixture['segment_id'],
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $fixture['brief_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'email_run_id' => $fixture['email_run_id'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->createConversionGoal([
            'title' => 'Revenue Loop Demo Goal',
            'goal_type' => 'demo_request',
            'success_metric' => 'demo_requests',
            'target_count' => 3,
            'target_value' => 9000,
            'tracking_source' => 'campaign',
            'status' => 'active',
            'campaign_id' => $campaignId,
            'landing_page_id' => $fixture['landing_page_id'],
            'form_id' => $fixture['form_id'],
            'content_item_id' => $fixture['content_id'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $budgetId = $this->marketing->createCampaignBudget([
            'title' => 'Revenue Loop Budget',
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $fixture['brief_id'],
            'planned_budget' => 500,
            'actual_spend' => 125,
            'status' => 'active',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->createRoiTarget([
            'title' => 'Revenue Loop ROI Target',
            'budget_id' => $budgetId,
            'campaign_id' => $campaignId,
            'target_revenue' => 2000,
            'target_leads' => 4,
            'target_conversions' => 2,
            'status' => 'active',
            'created_by' => $this->userId,
        ]);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, assigned_to)
             VALUES (?, UUID(), 'Revenue', 'revenue-loop@example.com', ?)",
            [$this->workspaceId, $this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, description, contact_id, assigned_to, created_by, stage, value, probability, currency, lead_source, campaign_id)
             VALUES (?, 'Revenue Loop Deal', 'Deal evidence for campaign revenue loop.', ?, ?, ?, 'won', 2500.00, 100, 'USD', 'campaign', ?)",
            [$this->workspaceId, $contactId, $this->userId, $this->userId, $campaignId]
        );
        $dealId = (int) Database::lastInsertId();
        $this->marketing->createLeadHandoff([
            'campaign_id' => $campaignId,
            'form_id' => $fixture['form_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'content_item_id' => $fixture['content_id'],
            'contact_id' => $contactId,
            'deal_id' => $dealId,
            'assigned_to' => $this->userId,
            'status' => 'converted',
            'priority' => 'high',
            'source' => 'phase_118_revenue_loop',
            'handoff_note' => 'Revenue loop should see this sales follow-up.',
            'converted_at' => date('Y-m-d H:i:s'),
            'created_by' => $this->userId,
        ]);
        Database::execute(
            "INSERT INTO marketing_attribution_touchpoints
             (workspace_id, uuid, campaign_id, content_item_id, landing_page_id, form_id, contact_id, deal_id, touchpoint_type, attribution_model, source, medium, campaign_name, channel, revenue_amount, occurred_at)
             VALUES (?, UUID(), ?, ?, ?, ?, ?, ?, 'revenue', 'linear', 'linkedin', 'social', 'Revenue Loop Campaign', 'linkedin', 1500.00, NOW())",
            [$this->workspaceId, $campaignId, $fixture['content_id'], $fixture['landing_page_id'], $fixture['form_id'], $contactId, $dealId]
        );
        $model = Database::queryOne("SELECT id FROM attribution_models WHERE slug = 'last_touch' LIMIT 1");
        Database::execute(
            "INSERT INTO attribution_results
             (workspace_id, model_id, contact_id, deal_id, campaign_id, conversion_event, conversion_at, touch_type, channel, attribution_weight, credited_value)
             VALUES (?, ?, ?, ?, ?, 'deal_won', NOW(), 'manual_export', 'linkedin', 1, 1500.00)",
            [$this->workspaceId, (int) ($model['id'] ?? 1), $contactId, $dealId, $campaignId]
        );

        $loop = $this->marketing->getCampaignRevenueLoopSummary($campaignId, 8);
        $this->assertGreaterThanOrEqual(75, (int) ($loop['score'] ?? 0));
        $this->assertArrayHasKey('strategy', (array) ($loop['lanes'] ?? []));
        $this->assertArrayHasKey('sales_handoff', (array) ($loop['lanes'] ?? []));
        $this->assertArrayHasKey('roi_plan', (array) ($loop['lanes'] ?? []));
        $this->assertSame(1, (int) ($loop['counts']['campaigns'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($loop['counts']['conversion_goals'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($loop['counts']['lead_handoffs'] ?? 0));
        $this->assertSame(1500.0, (float) ($loop['counts']['attributed_revenue'] ?? 0));
        $this->assertFalse((bool) ($loop['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($loop['guardrails']['external_publish'] ?? true));
        $this->assertTrue((bool) ($loop['performance']['page_load_guard'] ?? false));

        $summary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertArrayHasKey('campaign_revenue_loop', $summary);
        $this->assertArrayHasKey('campaign_revenue_loop', (array) ($summary['performance'] ?? []));

        $dashboard = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($dashboard, 200, 'marketing.php campaign revenue loop');
        $this->assertStringContainsString('Campaign-To-Revenue Loop', (string) ($dashboard['body'] ?? ''));
        $this->assertStringContainsString('Campaign Evidence', (string) ($dashboard['body'] ?? ''));

        $performance = $this->runWebEndpoint('public/marketing_performance.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($performance, 200, 'marketing_performance.php campaign revenue loop');
        $this->assertStringContainsString('Campaign-To-Revenue Loop', (string) ($performance['body'] ?? ''));
        $this->assertStringContainsString('Campaign Readiness', (string) ($performance['body'] ?? ''));
    }

    public function testPhaseOneHundredNineteenDecisionEngineRanksProductDecisionsWithoutSideEffects(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Decision Engine Revenue Gap Campaign');
        $beforeCount = count($this->marketing->listMarketingDecisions([], 100, 0));

        $engine = $this->marketing->getMarketingDecisionEngine($this->userId, 10);
        $afterCount = count($this->marketing->listMarketingDecisions([], 100, 0));

        $this->assertSame($beforeCount, $afterCount);
        $this->assertNotEmpty((array) ($engine['decisions'] ?? []));
        $this->assertArrayHasKey('campaign_revenue_loop', (array) ($engine['source_scores'] ?? []));
        $this->assertArrayHasKey('revenue_loop', (array) ($engine['categories'] ?? []));
        $this->assertSame('prioritize_campaign_revenue_loop', (string) ($engine['top_decision']['key'] ?? ''));
        $this->assertSame('suggested', (string) ($engine['top_decision']['decision_status'] ?? ''));
        $this->assertFalse((bool) ($engine['guardrails']['records_created'] ?? true));
        $this->assertFalse((bool) ($engine['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($engine['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($engine['guardrails']['external_api_calls'] ?? true));

        $summary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertArrayHasKey('decision_engine', $summary);
        $this->assertSame('prioritize_campaign_revenue_loop', (string) ($summary['decision_engine']['top_decision']['key'] ?? ''));

        $dashboard = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($dashboard, 200, 'marketing.php decision engine');
        $body = (string) ($dashboard['body'] ?? '');
        $this->assertStringContainsString('Decision Engine', $body);
        $this->assertStringContainsString('Prioritize campaign-to-revenue gaps', $body);
        $this->assertStringContainsString('Manual-first', $body);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $viewerEngine = $this->marketing->getMarketingDecisionEngine($this->userId, 6);
            $this->assertNotEmpty((array) ($viewerEngine['decisions'] ?? []));
            $this->assertFalse((bool) ($viewerEngine['guardrails']['external_send'] ?? true));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        $otherWorkspaceId = $this->createWorkspace('Decision Engine Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $otherEngine = $otherMarketing->getMarketingDecisionEngine($this->userId, 6);
        $this->assertNotEmpty((array) ($otherEngine['decisions'] ?? []));
        $this->assertNotSame($campaignId, (int) ($otherEngine['top_decision']['evidence']['campaign_id'] ?? 0));
        $this->assertFalse((bool) ($otherEngine['guardrails']['records_created'] ?? true));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseOneHundredTwentyMarketingSystemMapOrientsWorkflowAndNavigation(): void
    {
        $onboarding = $this->marketing->getMarketingOnboardingStatus();
        $summary = $this->marketing->getDashboardSummary($onboarding);
        $map = $this->marketing->getMarketingSystemMap($summary, $onboarding);

        $this->assertSame(8, (int) ($map['counts']['total'] ?? 0));
        $this->assertArrayHasKey('stages', $map);
        $this->assertSame('setup', (string) ($map['stages'][0]['key'] ?? ''));
        $this->assertNotEmpty((array) ($map['lowest_stage'] ?? []));
        $this->assertFalse((bool) ($map['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($map['guardrails']['external_publish'] ?? true));
        $this->assertTrue((bool) ($map['guardrails']['integrates_crm_surfaces'] ?? false));
        $this->assertArrayHasKey('system_map', $summary);

        $definition = MarketingPageGuideUi::pageDefinition('marketing_system_map.php');
        $this->assertIsArray($definition);
        $this->assertSame('marketing_system_map', (string) ($definition['key'] ?? ''));
        $this->assertSame('command', MarketingUi::pageSection('marketing_system_map.php'));

        $page = $this->runWebEndpoint('public/marketing_system_map.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_system_map.php owner');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Marketing System Map', $body);
        $this->assertStringContainsString('Operator Flow', $body);
        $this->assertStringContainsString('Workspace scoped', $body);
        $this->assertStringContainsString('Guardrails', $body);

        $dashboard = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($dashboard, 200, 'marketing.php system map');
        $this->assertStringContainsString('Marketing System Map', (string) ($dashboard['body'] ?? ''));
        $this->assertStringContainsString('href="marketing_system_map.php"', (string) ($dashboard['body'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $viewerPage = $this->runWebEndpoint('public/marketing_system_map.php', $this->webSession('viewer'), ['method' => 'GET']);
            $this->assertEndpointHealthy($viewerPage, 200, 'marketing_system_map.php viewer');
            $this->assertStringContainsString('Marketing System Map', (string) ($viewerPage['body'] ?? ''));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }
    }

    public function testPhaseOneHundredTwentyOneUnifiedCampaignWorkspaceMapConnectsLaunchAndRevenueLanes(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $campaignId = (int) $fixture['campaign_id'];

        $map = $this->marketing->getUnifiedCampaignWorkspaceMap($campaignId);
        $this->assertSame($campaignId, (int) ($map['campaign_id'] ?? 0));
        $this->assertSame(7, (int) ($map['counts']['total'] ?? 0));
        $laneKeys = array_column((array) ($map['lanes'] ?? []), 'key');
        $this->assertContains('strategy', $laneKeys);
        $this->assertContains('content', $laneKeys);
        $this->assertContains('distribution', $laneKeys);
        $this->assertContains('revenue', $laneKeys);
        $this->assertFalse((bool) ($map['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($map['guardrails']['external_publish'] ?? true));
        $this->assertTrue((bool) ($map['guardrails']['crm_integrated'] ?? false));

        $page = $this->runWebEndpoint('public/marketing_campaign_workspace.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $campaignId],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_campaign_workspace.php unified map');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Unified Campaign Map', $body);
        $this->assertStringContainsString('Revenue Loop', $body);
        $this->assertStringContainsString('manual-first guardrail', strtolower($body));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $viewerMap = $this->marketing->getUnifiedCampaignWorkspaceMap($campaignId);
            $this->assertSame($campaignId, (int) ($viewerMap['campaign_id'] ?? 0));
            $this->assertFalse((bool) ($viewerMap['guardrails']['external_api_calls'] ?? true));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        $otherWorkspaceId = $this->createWorkspace('Unified Campaign Map Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            $otherMarketing = new Marketing();
            $otherMarketing->getUnifiedCampaignWorkspaceMap($campaignId);
            $this->fail('Campaign map should not read a campaign outside the active workspace.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Campaign was not found', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseOneHundredTwentyTwoAiContextQualityGatePreflightsContextAndGuardrails(): void
    {
        $emptyGate = $this->marketing->getMarketingAiContextQualityGate([], $this->userId);
        $this->assertContains((string) ($emptyGate['status'] ?? ''), ['ready', 'needs_context', 'blocked']);
        $this->assertArrayHasKey('generation_mode', $emptyGate);
        $this->assertFalse((bool) ($emptyGate['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($emptyGate['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($emptyGate['guardrails']['external_api_calls'] ?? true));
        $this->assertTrue((bool) ($emptyGate['guardrails']['manual_review_required'] ?? false));

        $fixture = $this->createMarketingReleaseQaFixture();
        $query = [
            'assistant_type' => 'copywriter',
            'content_item_id' => (int) $fixture['content_id'],
            'campaign_brief_id' => (int) $fixture['brief_id'],
            'landing_page_id' => (int) $fixture['landing_page_id'],
        ];

        $gate = $this->marketing->getMarketingAiContextQualityGate($query, $this->userId);
        $this->assertContains((string) ($gate['status'] ?? ''), ['ready', 'needs_context', 'blocked']);
        $this->assertGreaterThan(0, (int) ($gate['score'] ?? 0));
        $this->assertGreaterThanOrEqual(3, (int) ($gate['record_coverage']['ready'] ?? 0));
        $this->assertGreaterThanOrEqual(6, (int) ($gate['record_coverage']['total'] ?? 0));
        $this->assertSame('ready', (string) ($gate['control_panel']['selected_records']['content_item']['status'] ?? ''));
        $this->assertSame('ready', (string) ($gate['control_panel']['selected_records']['campaign_brief']['status'] ?? ''));
        $this->assertFalse((bool) ($gate['guardrails']['automatic_overwrite'] ?? true));
        $this->assertFalse((bool) ($gate['guardrails']['external_publish'] ?? true));
        $this->assertNotEmpty((array) ($gate['recommendations'] ?? []));

        $page = $this->runWebEndpoint('public/marketing_assistants.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => $query,
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_assistants.php AI context quality gate');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('AI Context Quality Gate', $body);
        $this->assertStringContainsString('Go/no-go preflight', $body);
        $this->assertStringContainsString('Manual review required', $body);
        $this->assertStringContainsString('No external API side effects', $body);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $viewerGate = $this->marketing->getMarketingAiContextQualityGate($query, $this->userId);
            $this->assertFalse((bool) ($viewerGate['guardrails']['external_send'] ?? true));
            $viewerPage = $this->runWebEndpoint('public/marketing_assistants.php', $this->webSession('viewer'), [
                'method' => 'GET',
                'query' => $query,
            ]);
            $this->assertEndpointHealthy($viewerPage, 200, 'marketing_assistants.php AI context quality gate viewer');
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        $otherWorkspaceId = $this->createWorkspace('AI Context Quality Gate Isolation');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->getMarketingAiContextQualityGate($query, $this->userId);
            $this->fail('AI context quality gate should not read linked records outside the active workspace.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Linked record was not found', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseOneHundredTwentyThreeWorkflowReadinessAuditsSetupToExecutionWithoutSideEffects(): void
    {
        $empty = $this->marketing->getMarketingWorkflowReadiness();
        $this->assertSame(9, (int) ($empty['counts']['total'] ?? 0));
        $this->assertContains((string) ($empty['status'] ?? ''), ['ready', 'attention', 'blocked']);
        $this->assertFalse((bool) ($empty['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($empty['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($empty['guardrails']['external_api_calls'] ?? true));
        $this->assertTrue((bool) ($empty['guardrails']['read_only_diagnostic'] ?? false));
        $laneKeys = array_column((array) ($empty['lanes'] ?? []), 'key');
        $this->assertContains('setup_context', $laneKeys);
        $this->assertContains('campaign_strategy', $laneKeys);
        $this->assertContains('distribution_execution', $laneKeys);
        $this->assertContains('operations_governance', $laneKeys);

        $this->createMarketingReleaseQaFixture();
        $summary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertArrayHasKey('workflow_readiness', $summary);
        $readiness = (array) ($summary['workflow_readiness'] ?? []);
        $this->assertSame(9, (int) ($readiness['counts']['total'] ?? 0));
        $this->assertGreaterThan(0, (int) ($readiness['score'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($readiness['record_counts']['content_items'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($readiness['record_counts']['campaign_briefs'] ?? 0));
        $this->assertNotEmpty((array) ($readiness['recommended_actions'] ?? []));
        $this->assertStringContainsString('Workflow Readiness', (string) ($readiness['recommended_product_decision'] ?? ''));

        $dashboard = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($dashboard, 200, 'marketing.php workflow readiness');
        $dashboardBody = (string) ($dashboard['body'] ?? '');
        $this->assertStringContainsString('Workflow Reality Check', $dashboardBody);
        $this->assertStringContainsString('Setup to execution readiness', $dashboardBody);
        $this->assertStringContainsString('Manual-first', $dashboardBody);

        $admin = $this->runWebEndpoint('public/marketing_admin.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($admin, 200, 'marketing_admin.php workflow readiness');
        $adminBody = (string) ($admin['body'] ?? '');
        $this->assertStringContainsString('Workflow Reality Check', $adminBody);
        $this->assertStringContainsString('Open Workflow Gaps', $adminBody);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $viewerReadiness = $this->marketing->getMarketingWorkflowReadiness($summary, $this->marketing->getMarketingOnboardingStatus());
            $this->assertFalse((bool) ($viewerReadiness['guardrails']['external_api_calls'] ?? true));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        $currentContentCount = (int) ($readiness['record_counts']['content_items'] ?? 0);
        $otherWorkspaceId = $this->createWorkspace('Workflow Readiness Isolation');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            $otherReadiness = (new Marketing())->getMarketingWorkflowReadiness();
            $this->assertLessThan($currentContentCount, (int) ($otherReadiness['record_counts']['content_items'] ?? 0));
            $this->assertFalse((bool) ($otherReadiness['guardrails']['external_send'] ?? true));
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseOneHundredTwentyFourCampaignExecutionBriefingCombinesGoNoGoSignals(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $campaignId = (int) $fixture['campaign_id'];

        $briefing = $this->marketing->getCampaignExecutionBriefing($campaignId, $this->userId);
        $this->assertSame($campaignId, (int) ($briefing['campaign_id'] ?? 0));
        $this->assertContains((string) ($briefing['status'] ?? ''), ['ready_for_manual_launch', 'needs_attention', 'blocked']);
        $this->assertGreaterThan(0, (int) ($briefing['score'] ?? 0));
        $this->assertArrayHasKey('map_status', (array) ($briefing['evidence'] ?? []));
        $this->assertArrayHasKey('execution_status', (array) ($briefing['evidence'] ?? []));
        $this->assertArrayHasKey('launch_status', (array) ($briefing['evidence'] ?? []));
        $this->assertNotEmpty((array) ($briefing['next_decisions'] ?? []));
        $this->assertTrue((bool) ($briefing['guardrails']['read_only_briefing'] ?? false));
        $this->assertFalse((bool) ($briefing['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($briefing['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($briefing['guardrails']['external_api_calls'] ?? true));

        $page = $this->runWebEndpoint('public/marketing_campaign_workspace.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $campaignId],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_campaign_workspace.php execution briefing');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Campaign Execution Briefing', $body);
        $this->assertStringContainsString('Manual-first go/no-go', $body);
        $this->assertStringContainsString('Unified Campaign Map', $body);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $viewerBriefing = $this->marketing->getCampaignExecutionBriefing($campaignId, $this->userId);
            $this->assertSame($campaignId, (int) ($viewerBriefing['campaign_id'] ?? 0));
            $this->assertFalse((bool) ($viewerBriefing['guardrails']['external_api_calls'] ?? true));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        $otherWorkspaceId = $this->createWorkspace('Campaign Execution Briefing Isolation');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->getCampaignExecutionBriefing($campaignId, $this->userId);
            $this->fail('Campaign execution briefing should not read campaigns outside the active workspace.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Campaign was not found', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseOneHundredNineMarketingAiContextEvidenceSnapshotsAreAuditableAndWorkspaceScoped(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'AI Evidence Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'AI Evidence Content',
            'content_type' => 'email',
            'channel' => 'email',
            'campaign_id' => $campaignId,
            'objective' => 'Prove AI context evidence is captured.',
            'target_audience' => 'Marketing operators',
            'created_by' => $this->userId,
        ]);

        $draft = $this->marketing->generateDraft([
            'id' => $contentId,
            'title' => 'AI Evidence Content',
            'content_type' => 'email',
            'channel' => 'email',
            'campaign_id' => $campaignId,
            'objective' => 'Prove AI context evidence is captured.',
            'target_audience' => 'Marketing operators',
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, (int) ($draft['ai_context']['context_snapshot_id'] ?? 0));

        $assistant = $this->marketing->runMarketingAssistant('copywriter', [
            'prompt' => 'Explain why the evidence snapshot matters.',
            'content_item_id' => $contentId,
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, (int) ($assistant['context_snapshot_id'] ?? 0));

        $copilot = $this->marketing->runCampaignCopilot($campaignId, 'readiness_summary', $this->userId);
        $this->assertSame('completed', (string) ($copilot['status'] ?? ''));

        $snapshots = $this->marketing->listMarketingAiContextSnapshots([], 50, 0);
        $surfaces = array_values(array_unique(array_column($snapshots, 'surface')));
        $this->assertContains('content_draft', $surfaces);
        $this->assertContains('assistant', $surfaces);
        $this->assertContains('campaign_copilot', $surfaces);
        $first = $snapshots[0];
        $this->assertSame($this->workspaceId, (int) ($first['workspace_id'] ?? 0));
        $this->assertNotEmpty((string) ($first['context_hash'] ?? ''));
        $this->assertFalse((bool) ($first['provider_json']['side_effects']['external_publish'] ?? $first['provider_json']['external_generation'] ?? false));
        $this->assertFalse((bool) ($first['provider_json']['side_effects']['external_send'] ?? false));

        $evidence = $this->marketing->getMarketingAiContextEvidence($this->userId, 10);
        $this->assertGreaterThanOrEqual(3, (int) ($evidence['counts']['total'] ?? 0));
        $this->assertArrayHasKey('content_draft', (array) ($evidence['counts']['surfaces'] ?? []));
        $this->assertNotEmpty((array) ($evidence['guardrails'] ?? []));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $viewerSnapshots = $this->marketing->listMarketingAiContextSnapshots([], 10, 0);
        $this->assertNotEmpty($viewerSnapshots);
        try {
            $this->marketing->captureMarketingAiContextSnapshot('custom', 'viewer_attempt', ['context_bundle' => []], [], [], $this->userId);
            $this->fail('Viewer should not create AI context evidence snapshots.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $manualSnapshotId = $this->marketing->captureMarketingAiContextSnapshot(
            'custom',
            'manual_marketing_snapshot',
            ['context_bundle' => ['manual' => true]],
            ['context_score' => 0.5, 'workspace_brain_score' => 25],
            ['content_item_id' => $contentId],
            $this->userId
        );
        $this->assertGreaterThan(0, $manualSnapshotId);
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);

        $assistantPage = $this->runWebEndpoint('public/marketing_assistants.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($assistantPage, 200, 'marketing_assistants.php AI evidence');
        $this->assertStringContainsString('AI Context Evidence', (string) ($assistantPage['body'] ?? ''));
        $this->assertStringContainsString('Average context score', (string) ($assistantPage['body'] ?? ''));

        $adminPage = $this->runWebEndpoint('public/marketing_admin.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($adminPage, 200, 'marketing_admin.php AI evidence');
        $this->assertStringContainsString('AI Context Evidence', (string) ($adminPage['body'] ?? ''));
        $this->assertStringNotContainsString('Fatal error', (string) ($adminPage['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('AI Evidence Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertSame([], $otherMarketing->listMarketingAiContextSnapshots([], 10, 0));
        $this->assertNull($otherMarketing->getMarketingAiContextSnapshot((int) ($first['id'] ?? 0)));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseOneHundredTenMarketingRelationshipGraphMapsRecordsAndOrphans(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Relationship Campaign');
        $formId = $this->createForm($this->workspaceId, 'Relationship Form');
        $personaId = $this->marketing->createPersona([
            'name' => 'Relationship Persona',
            'segment' => 'Operators',
            'created_by' => $this->userId,
        ]);
        $offerId = $this->marketing->createContextItem([
            'item_type' => 'offer',
            'title' => 'Relationship Offer',
            'body' => 'A clear offer for relationship graph testing.',
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Relationship Brief',
            'campaign_id' => $campaignId,
            'persona_id' => $personaId,
            'offer_context_item_id' => $offerId,
            'objective' => 'Map relationships.',
            'audience' => 'Marketing operators',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Relationship Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'created_by' => $this->userId,
        ]);
        $orphanContentId = $this->marketing->createContentItem([
            'title' => 'Orphan Relationship Content',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'created_by' => $this->userId,
        ]);
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Relationship Hero',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/relationship-hero.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => 'Relationship hero image',
            'usage_rights' => 'owned',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'media_role' => 'featured',
            'created_by' => $this->userId,
        ]);
        $landingId = $this->marketing->createLandingPage([
            'title' => 'Relationship Landing',
            'slug' => 'relationship-landing',
            'headline' => 'Relationship graph landing',
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'created_by' => $this->userId,
        ]);
        $distributionId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'linkedin',
            'planned_copy' => 'Relationship graph distribution copy.',
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $distributionId);

        $refresh = $this->marketing->refreshMarketingRelationshipGraph($this->userId);
        $this->assertGreaterThanOrEqual(7, (int) ($refresh['created'] ?? 0));
        $relationships = $this->marketing->listMarketingRelationships([], 100, 0);
        $this->assertNotEmpty($relationships);
        $types = array_values(array_unique(array_column($relationships, 'relationship_type')));
        $this->assertContains('supports', $types);
        $this->assertContains('uses', $types);
        $this->assertContains('distributed_as', $types);

        $map = $this->marketing->getMarketingRelationshipMapForRecord('content_item', $contentId, 25);
        $this->assertNotEmpty((array) ($map['relationships'] ?? []));
        $summary = $this->marketing->getMarketingRelationshipGraphSummary(25);
        $this->assertGreaterThan(0, (int) ($summary['counts']['edges'] ?? 0));
        $orphanIds = array_map(static fn(array $row): int => (int) ($row['record_id'] ?? 0), (array) ($summary['orphaned'] ?? []));
        $this->assertContains($orphanContentId, $orphanIds);

        $manualRelationshipId = $this->marketing->createMarketingRelationship([
            'source_type' => 'content_item',
            'source_id' => $contentId,
            'target_type' => 'landing_page',
            'target_id' => $landingId,
            'relationship_type' => 'supports',
            'label' => 'Content supports landing page',
        ], $this->userId);
        $this->assertGreaterThan(0, $manualRelationshipId);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $this->assertNotEmpty($this->marketing->listMarketingRelationships([], 10, 0));
        try {
            $this->marketing->refreshMarketingRelationshipGraph($this->userId);
            $this->fail('Viewer should not refresh the relationship graph.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->archiveMarketingRelationship($manualRelationshipId);
            $this->fail('Marketing role should not archive relationship edges.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $this->assertTrue($this->marketing->archiveMarketingRelationship($manualRelationshipId));

        $page = $this->runWebEndpoint('public/marketing_relationships.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_relationships.php owner');
        $this->assertStringContainsString('Marketing Relationship Graph', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Graph Edges', (string) ($page['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Relationship Graph Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertSame([], $otherMarketing->listMarketingRelationships([], 10, 0));
        $this->assertNull($otherMarketing->getMarketingRelationship((int) ($relationships[0]['id'] ?? 0)));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseOneHundredElevenCampaignRelationshipReadinessUsesGraphEvidence(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Graph Ready Campaign');
        $formId = $this->createForm($this->workspaceId, 'Graph Ready Form');
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Graph Ready Segment',
            'description' => 'A campaign audience for relationship readiness.',
            'status' => 'active',
            'source_scope' => 'contacts',
            'rule_logic' => 'all',
            'rules' => [
                ['source_type' => 'contact', 'field_key' => 'stage', 'operator' => 'equals', 'value_text' => 'qualified'],
            ],
            'created_by' => $this->userId,
        ]);
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Graph Ready Brief',
            'campaign_id' => $campaignId,
            'audience_segment_id' => $segmentId,
            'objective' => 'Prove graph-aware readiness.',
            'audience' => 'Qualified buyers',
            'offer_text' => 'Graph readiness sprint',
            'key_message' => 'Launch only when campaign evidence is connected.',
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Graph Ready Hero',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/graph-ready.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => 'Graph ready campaign hero',
            'usage_rights' => 'owned',
            'created_by' => $this->userId,
        ]);
        $landingId = $this->marketing->createLandingPage([
            'title' => 'Graph Ready Landing',
            'slug' => 'graph-ready-' . uniqid(),
            'headline' => 'Launch with connected evidence',
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'audience_segment_id' => $segmentId,
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Graph Ready Content',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'status' => 'approved',
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingId,
            'draft_body' => 'Relationship evidence before manual launch.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'featured',
            'created_by' => $this->userId,
        ]);
        $distributionId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'linkedin',
            'planned_copy' => 'Manual distribution with connected evidence.',
            'status' => 'exported',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createUtmLink([
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'url' => 'https://example.com/graph-ready',
            'utm_source' => 'linkedin',
            'utm_medium' => 'social',
            'utm_campaign' => 'graph_ready',
            'created_by' => $this->userId,
        ]);
        $activationId = $this->marketing->createAudienceActivation([
            'activation_name' => 'Graph Ready Activation',
            'activation_type' => 'launch',
            'status' => 'active',
            'audience_segment_id' => $segmentId,
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingId,
            'content_item_id' => $contentId,
            'distribution_post_id' => $distributionId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $activationId);
        $review = $this->marketing->evaluateLaunchReadiness([
            'launch_name' => 'Graph Ready Launch Review',
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingId,
            'content_item_id' => $contentId,
            'distribution_post_id' => $distributionId,
            'checklist' => "Brief approved\nAudience confirmed\nManual export ready\nUTM checked",
            'created_by' => $this->userId,
        ], $this->userId);
        $this->assertGreaterThan(0, (int) ($review['id'] ?? 0));

        $workspace = $this->marketing->refreshMarketingCampaignWorkspace($campaignId, $this->userId);
        $this->assertGreaterThanOrEqual(80, (int) ($workspace['health_score'] ?? 0));
        $beforeGraph = $this->marketing->getCampaignRelationshipReadiness($campaignId);
        $this->assertSame('needs_graph_refresh', (string) ($beforeGraph['status'] ?? ''));
        $this->assertSame(0, (int) ($beforeGraph['counts']['direct_edges'] ?? -1));
        $this->assertContains('Refresh relationship graph', array_column((array) ($beforeGraph['next_actions'] ?? []), 'label'));

        $this->marketing->refreshMarketingRelationshipGraph($this->userId);
        $afterGraph = $this->marketing->getCampaignRelationshipReadiness($campaignId);
        $this->assertSame('relationship_ready', (string) ($afterGraph['status'] ?? ''));
        $this->assertTrue((bool) ($afterGraph['ready_for_operator_review'] ?? false));
        $this->assertGreaterThanOrEqual(4, (int) ($afterGraph['counts']['direct_edges'] ?? 0));
        $this->assertSame((int) ($afterGraph['counts']['graph_expected'] ?? 0), (int) ($afterGraph['counts']['graph_connected'] ?? -1));
        $this->assertSame('Use the relationship graph as the campaign evidence layer before relying on AI, automation, or manual launch decisions.', (string) ($afterGraph['recommended_product_decision'] ?? ''));
        $this->assertFalse((bool) ($afterGraph['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($afterGraph['guardrails']['external_send'] ?? true));

        $page = $this->runWebEndpoint('public/marketing_campaign_workspace.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['campaign_id' => $campaignId],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_campaign_workspace.php relationship readiness');
        $this->assertStringContainsString('Campaign Relationship Readiness', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Evidence Edges', (string) ($page['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Campaign Relationship Readiness Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        try {
            $otherMarketing->getCampaignRelationshipReadiness($campaignId);
            $this->fail('Cross-workspace campaign relationship readiness should not load.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', strtolower($e->getMessage()));
        }
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseOneHundredTwelveContentProductionCommandQueuePrioritizesOperatorWork(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Production Command Campaign');
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Production Command Brief',
            'campaign_id' => $campaignId,
            'objective' => 'Prioritize content production work.',
            'audience' => 'Marketing operators',
            'created_by' => $this->userId,
        ]);
        $blockedId = $this->marketing->createContentItem([
            'title' => 'Blocked Production Command Item',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'production_stage' => 'drafting',
            'production_due_at' => date('Y-m-d\TH:i', strtotime('-2 days')),
            'dependency_status' => 'blocked',
            'blocked_reason' => 'Waiting on legal claim review.',
            'draft_body' => 'Blocked production draft.',
            'created_by' => $this->userId,
        ]);
        $reviewId = $this->marketing->createContentItem([
            'title' => 'Review Production Command Item',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'status' => 'review',
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'production_stage' => 'review',
            'production_due_at' => date('Y-m-d\TH:i', strtotime('+2 days')),
            'draft_body' => 'Review queue production item.',
            'created_by' => $this->userId,
        ]);
        $missingLinkId = $this->marketing->createContentItem([
            'title' => 'Missing Link Production Item',
            'content_type' => 'ad_copy',
            'channel' => 'ads',
            'production_stage' => 'idea',
            'draft_body' => 'Needs campaign and brief links.',
            'created_by' => $this->userId,
        ]);

        $queue = $this->marketing->getContentProductionCommandQueue(null, 6);
        $this->assertContains((string) ($queue['status'] ?? ''), ['blocked', 'needs_attention']);
        $this->assertGreaterThanOrEqual(3, (int) ($queue['counts']['total'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($queue['counts']['blocked'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($queue['counts']['overdue'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($queue['counts']['review_queue'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($queue['counts']['missing_links'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($queue['counts']['media_warnings'] ?? 0));
        $this->assertSame(6, (int) ($queue['performance']['limit'] ?? 0));
        $this->assertTrue((bool) ($queue['performance']['page_load_guard'] ?? false));
        $this->assertFalse((bool) ($queue['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($queue['guardrails']['external_send'] ?? true));
        $this->assertSame('Use the production command queue as the daily operator view before scheduling or exporting content.', (string) ($queue['recommended_product_decision'] ?? ''));
        $labels = array_column((array) ($queue['next_actions'] ?? []), 'label');
        $this->assertContains('Resolve blocked content', $labels);
        $this->assertContains('Review overdue production work', $labels);
        $blockedIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($queue['queues']['blocked'] ?? []));
        $reviewIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($queue['queues']['review_queue'] ?? []));
        $missingIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($queue['queues']['missing_links'] ?? []));
        $this->assertContains($blockedId, $blockedIds);
        $this->assertContains($reviewId, $reviewIds);
        $this->assertContains($missingLinkId, $missingIds);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $viewerQueue = $this->marketing->getContentProductionCommandQueue(null, 3);
        $this->assertNotEmpty((array) ($viewerQueue['queues'] ?? []));
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);

        $page = $this->runWebEndpoint('public/marketing_content.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_content.php production command queue');
        $this->assertStringContainsString('Production Command Queue', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Next Operator Actions', (string) ($page['body'] ?? ''));
    }

    public function testPhaseOneHundredThirteenLandingVisualCommandQueueFindsMediaAndConversionGaps(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Landing Visual Campaign');
        $formId = $this->createForm($this->workspaceId, 'Landing Visual Form');
        $readyMediaId = $this->marketing->createMediaFile([
            'title' => 'Accessible Landing Visual',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/landing-accessible.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => 'Operator reviewing landing page visuals',
            'usage_rights' => 'owned',
            'created_by' => $this->userId,
        ]);
        $missingAltMediaId = $this->marketing->createMediaFile([
            'title' => 'Missing Alt Landing Visual',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/landing-missing-alt.jpg',
            'mime_type' => 'image/jpeg',
            'usage_rights' => 'owned',
            'created_by' => $this->userId,
        ]);
        $missingVisualId = $this->marketing->createLandingPage([
            'title' => 'Missing Visual Landing',
            'slug' => 'missing-visual-' . uniqid(),
            'headline' => 'Needs visual readiness',
            'body_sections' => "Problem\nVisual planning is missing.",
            'cta_blocks' => "Book\nBook a review.",
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);
        $accessibilityGapId = $this->marketing->createLandingPage([
            'title' => 'Accessibility Gap Landing',
            'slug' => 'accessibility-gap-' . uniqid(),
            'headline' => 'Needs accessible media',
            'body_sections' => "Proof\nVisual proof exists.",
            'cta_blocks' => "Book\nBook a visual review.",
            'conversion_goal' => 'demo_request',
            'form_id' => $formId,
            'campaign_id' => $campaignId,
            'hero_media_file_id' => $missingAltMediaId,
            'social_preview_media_file_id' => $missingAltMediaId,
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);
        $readyLandingId = $this->marketing->createLandingPage([
            'title' => 'Ready Visual Landing',
            'slug' => 'ready-visual-' . uniqid(),
            'headline' => 'Ready visual landing page',
            'body_sections' => "Outcome\nA strong media-led conversion page.",
            'cta_blocks' => "Start\nBook the campaign review.",
            'conversion_goal' => 'demo_request',
            'form_id' => $formId,
            'campaign_id' => $campaignId,
            'hero_media_file_id' => $readyMediaId,
            'social_preview_media_file_id' => $readyMediaId,
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);

        $queue = $this->marketing->getLandingPageVisualCommandQueue(6);
        $this->assertGreaterThanOrEqual(3, (int) ($queue['counts']['scanned'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($queue['counts']['needs_hero_media'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($queue['counts']['needs_social_preview'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($queue['counts']['needs_accessibility'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($queue['counts']['needs_conversion'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($queue['counts']['ready_to_preview'] ?? 0));
        $this->assertTrue((bool) ($queue['performance']['page_load_guard'] ?? false));
        $this->assertFalse((bool) ($queue['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($queue['guardrails']['external_send'] ?? true));
        $this->assertSame('Treat landing pages as media-led conversion assets: every page should have a hero visual, social preview, accessible media text, and conversion wiring before manual launch.', (string) ($queue['recommended_product_decision'] ?? ''));
        $heroIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($queue['queues']['needs_hero_media'] ?? []));
        $accessibilityIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($queue['queues']['needs_accessibility'] ?? []));
        $readyIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($queue['queues']['ready_to_preview'] ?? []));
        $this->assertContains($missingVisualId, $heroIds);
        $this->assertContains($accessibilityGapId, $accessibilityIds);
        $this->assertContains($readyLandingId, $readyIds);
        $labels = array_column((array) ($queue['next_actions'] ?? []), 'label');
        $this->assertContains('Attach hero visuals', $labels);
        $this->assertContains('Fix media accessibility', $labels);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $viewerQueue = $this->marketing->getLandingPageVisualCommandQueue(3);
        $this->assertNotEmpty((array) ($viewerQueue['queues'] ?? []));
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);

        $page = $this->runWebEndpoint('public/marketing_landing_pages.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_landing_pages.php visual command queue');
        $this->assertStringContainsString('Landing Visual Readiness', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Next Visual Actions', (string) ($page['body'] ?? ''));
    }

    public function testPhaseOneHundredFourteenQueueFollowThroughCreatesReusableTasksAndCreativeWork(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Queue Action Content',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'status' => 'draft',
            'draft_body' => 'Needs a campaign-ready visual.',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $contentResult = $this->marketing->createMarketingQueueAction([
            'surface' => 'content_production',
            'source_id' => $contentId,
            'action_key' => 'content_media_request',
        ], $this->userId);

        $this->assertNotEmpty((array) ($contentResult['created'] ?? []));
        $this->assertFalse((bool) ($contentResult['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($contentResult['guardrails']['external_send'] ?? true));
        $createdTypes = array_column((array) ($contentResult['created'] ?? []), 'type');
        $this->assertContains('asset_request', $createdTypes);
        $this->assertContains('media_work', $createdTypes);
        $assetRequestCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM marketing_asset_requests WHERE workspace_id = ? AND content_item_id = ? AND metadata_json LIKE ?",
            [$this->workspaceId, $contentId, '%content_media_request%']
        )['c'] ?? 0);
        $mediaWorkCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM marketing_media_production_work_items WHERE workspace_id = ? AND content_item_id = ? AND metadata_json LIKE ?",
            [$this->workspaceId, $contentId, '%content_media_request%']
        )['c'] ?? 0);
        $taskCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM tasks WHERE workspace_id = ? AND metadata_json LIKE ?",
            [$this->workspaceId, '%content_media_request%']
        )['c'] ?? 0);
        $this->assertSame(1, $assetRequestCount);
        $this->assertSame(1, $mediaWorkCount);
        if (in_array('task', $createdTypes, true)) {
            $this->assertSame(1, $taskCount);
        } else {
            $this->assertSame(0, $taskCount);
            $this->assertNotEmpty((array) ($contentResult['warnings'] ?? []));
        }

        $repeatContentResult = $this->marketing->createMarketingQueueAction([
            'surface' => 'content_production',
            'source_id' => $contentId,
            'action_key' => 'content_media_request',
        ], $this->userId);
        $this->assertCount($taskCount > 0 ? 3 : 2, (array) ($repeatContentResult['reused'] ?? []));
        $this->assertSame($assetRequestCount, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM marketing_asset_requests WHERE workspace_id = ? AND content_item_id = ? AND metadata_json LIKE ?",
            [$this->workspaceId, $contentId, '%content_media_request%']
        )['c'] ?? 0));

        $landingId = $this->marketing->createLandingPage([
            'title' => 'Queue Action Landing',
            'slug' => 'queue-action-landing-' . uniqid(),
            'headline' => 'Needs a hero visual',
            'body_sections' => "Problem\nThe landing page needs media.",
            'cta_blocks' => "Book\nBook a review.",
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);
        $landingResult = $this->marketing->createMarketingQueueAction([
            'surface' => 'landing_visual',
            'source_id' => $landingId,
            'action_key' => 'landing_hero_visual',
        ], $this->userId);
        $this->assertNotEmpty((array) ($landingResult['created'] ?? []));
        $this->assertContains('media_work', array_column((array) ($landingResult['created'] ?? []), 'type'));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM marketing_media_production_work_items WHERE workspace_id = ? AND landing_page_id = ? AND metadata_json LIKE ?",
            [$this->workspaceId, $landingId, '%landing_hero_visual%']
        )['c'] ?? 0));
        $landingTaskCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM tasks WHERE workspace_id = ? AND metadata_json LIKE ?",
            [$this->workspaceId, '%landing_hero_visual%']
        )['c'] ?? 0);
        if (in_array('task', array_column((array) ($landingResult['created'] ?? []), 'type'), true)) {
            $this->assertSame(1, $landingTaskCount);
        } else {
            $this->assertSame(0, $landingTaskCount);
            $this->assertNotEmpty((array) ($landingResult['warnings'] ?? []));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createMarketingQueueAction([
                'surface' => 'landing_visual',
                'source_id' => $landingId,
                'action_key' => 'landing_preview_review',
            ], $this->userId);
            $this->fail('Viewer should not create marketing queue follow-through work.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permission', $e->getMessage());
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        $contentPage = $this->runWebEndpoint('public/marketing_content.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($contentPage, 200, 'marketing_content.php queue follow-through controls');
        $this->assertStringContainsString('Create task/request', (string) ($contentPage['body'] ?? ''));

        $landingPage = $this->runWebEndpoint('public/marketing_landing_pages.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($landingPage, 200, 'marketing_landing_pages.php queue follow-through controls');
        $this->assertStringContainsString('Create task/request', (string) ($landingPage['body'] ?? ''));
    }

    public function testPhaseOneHundredFifteenOperatorQueueSummariesUseWorkspaceScopedCache(): void
    {
        $this->marketing->clearMarketingDashboardSummaryCache();
        $contentId = $this->marketing->createContentItem([
            'title' => 'Cached Queue Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'status' => 'draft',
            'draft_body' => 'Needs production queue caching.',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $landingId = $this->marketing->createLandingPage([
            'title' => 'Cached Queue Landing',
            'slug' => 'cached-queue-landing-' . uniqid(),
            'headline' => 'Cached visual readiness',
            'body_sections' => "Problem\nThe visual queue should cache.",
            'cta_blocks' => "Book\nBook a cache review.",
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);

        $firstContent = $this->marketing->getCachedContentProductionCommandQueue(null, 6, 120);
        $secondContent = $this->marketing->getCachedContentProductionCommandQueue(null, 6, 120);
        $this->assertSame('miss', (string) ($firstContent['performance']['cache_status'] ?? ''));
        $this->assertSame('hit', (string) ($secondContent['performance']['cache_status'] ?? ''));
        $this->assertSame(120, (int) ($secondContent['performance']['cache_ttl_seconds'] ?? 0));
        $this->assertStringContainsString('operator_queue:content_production', (string) ($secondContent['performance']['cache_key'] ?? ''));

        $firstLanding = $this->marketing->getCachedLandingPageVisualCommandQueue(6, 120);
        $secondLanding = $this->marketing->getCachedLandingPageVisualCommandQueue(6, 120);
        $this->assertSame('miss', (string) ($firstLanding['performance']['cache_status'] ?? ''));
        $this->assertSame('hit', (string) ($secondLanding['performance']['cache_status'] ?? ''));
        $this->assertStringContainsString('operator_queue:landing_visual', (string) ($secondLanding['performance']['cache_key'] ?? ''));
        $this->assertContains($contentId, array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($secondContent['queues']['media_warnings'] ?? [])));
        $this->assertContains($landingId, array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($secondLanding['queues']['needs_hero_media'] ?? [])));

        $cacheStatus = $this->marketing->getMarketingDashboardCacheStatus();
        $cacheKeys = array_column((array) ($cacheStatus['entries'] ?? []), 'cache_key');
        $this->assertNotEmpty(array_filter($cacheKeys, static fn(string $key): bool => str_contains($key, 'operator_queue:content_production')));
        $this->assertNotEmpty(array_filter($cacheKeys, static fn(string $key): bool => str_contains($key, 'operator_queue:landing_visual')));
        $this->assertFalse((bool) ($cacheStatus['guardrails']['external_api_calls'] ?? true));

        $contentPage = $this->runWebEndpoint('public/marketing_content.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($contentPage, 200, 'marketing_content.php queue cache');
        $this->assertStringContainsString('Queue cache:', (string) ($contentPage['body'] ?? ''));

        $landingPage = $this->runWebEndpoint('public/marketing_landing_pages.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($landingPage, 200, 'marketing_landing_pages.php queue cache');
        $this->assertStringContainsString('Queue cache:', (string) ($landingPage['body'] ?? ''));
    }

    public function testPhaseOneHundredOneAutomationReadinessIsManualFirstAndWorkspaceScoped(): void
    {
        $scheduledContentId = $this->marketing->createContentItem([
            'title' => 'Phase 101 Scheduled Content',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'status' => 'approved',
            'scheduled_at' => date('Y-m-d\TH:i', strtotime('+2 days')),
            'draft_body' => 'Scheduled manual content.',
            'created_by' => $this->userId,
        ]);
        $reviewContentId = $this->marketing->createContentItem([
            'title' => 'Phase 101 Review Content',
            'content_type' => 'email',
            'channel' => 'email',
            'draft_body' => 'Review-ready copy.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->requestContentReview($reviewContentId, $this->userId, $this->userId, date('Y-m-d\TH:i', strtotime('+1 day')));
        $this->marketing->createDistributionPost([
            'content_item_id' => $scheduledContentId,
            'channel' => 'linkedin',
            'planned_copy' => 'Manual export package copy.',
            'status' => 'scheduled',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createEmailCampaignRun([
            'name' => 'Phase 101 Email Run',
            'content_item_id' => $reviewContentId,
            'subject' => 'Phase 101 subject',
            'body_snapshot' => 'Phase 101 email body',
            'send_checklist' => ['Review subject', 'Export CSV'],
            'unsubscribe_text' => 'Unsubscribe by replying stop.',
            'cta_url' => 'https://example.com/phase-101',
            'cta_label' => 'Book',
            'approval_status' => 'approved',
            'status' => 'ready',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createRecurringRhythm([
            'name' => 'Phase 101 Weekly Marketing Rhythm',
            'rhythm_type' => 'planning',
            'cadence' => 'weekly',
            'next_run_at' => date('Y-m-d\TH:i', strtotime('+3 days')),
            'checklist' => "Review queue\nPrepare exports",
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->createPlanningQueueItem([
            'title' => 'Phase 101 Follow-Up Queue',
            'week_start' => date('Y-m-d', strtotime('monday this week')),
            'item_type' => 'review',
            'priority' => 'high',
            'content_item_id' => $reviewContentId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->createScheduledReportDraft([
            'title' => 'Phase 101 Weekly Report Draft',
            'report_type' => 'weekly',
            'cadence' => 'weekly',
            'status' => 'active',
            'next_run_at' => date('Y-m-d\TH:i', strtotime('+5 days')),
            'recipients' => ['ops@example.com'],
            'created_by' => $this->userId,
        ]);
        $this->marketing->createExecutionQueueItem([
            'execution_type' => 'social',
            'status' => 'approved',
            'execution_mode' => 'dry_run',
            'content_item_id' => $scheduledContentId,
            'requested_by' => $this->userId,
            'created_by' => $this->userId,
        ]);

        $readiness = $this->marketing->getMarketingAutomationReadiness($this->userId);
        $this->assertArrayHasKey('loops', $readiness);
        $this->assertSame('ready', (string) ($readiness['loops']['content_schedule_reminders']['status'] ?? ''));
        $this->assertSame('ready', (string) ($readiness['loops']['review_nudges']['status'] ?? ''));
        $this->assertSame('ready', (string) ($readiness['loops']['email_export_prep']['status'] ?? ''));
        $this->assertSame('ready', (string) ($readiness['loops']['weekly_planning_rhythm']['status'] ?? ''));
        $this->assertSame('ready', (string) ($readiness['loops']['report_draft_cycle']['status'] ?? ''));
        $this->assertSame('ready', (string) ($readiness['loops']['dry_run_execution_queue']['status'] ?? ''));
        $this->assertFalse((bool) ($readiness['manual_first_boundary']['external_send'] ?? true));
        $this->assertFalse((bool) ($readiness['manual_first_boundary']['external_publish'] ?? true));
        $this->assertStringContainsString('manual-first', strtolower((string) ($readiness['recommended_product_decision'] ?? '')));

        $summary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertArrayHasKey('automation_readiness', $summary);
        $this->assertGreaterThanOrEqual(5, (int) ($summary['automation_readiness']['counts']['ready'] ?? 0));

        $dashboard = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($dashboard, 200, 'marketing.php phase 101');
        $this->assertStringContainsString('Manual Automation Readiness', (string) ($dashboard['body'] ?? ''));
        $this->assertStringContainsString('Content Schedule Reminders', (string) ($dashboard['body'] ?? ''));

        $operations = $this->runWebEndpoint('public/marketing_operations.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($operations, 200, 'marketing_operations.php phase 101');
        $this->assertStringContainsString('Manual Automation Readiness', (string) ($operations['body'] ?? ''));
        $this->assertStringContainsString('Dry-Run Execution Queue', (string) ($operations['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Phase 101 Automation Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherReadiness = (new Marketing())->getMarketingAutomationReadiness($this->userId);
        $this->assertSame(0, (int) ($otherReadiness['loops']['content_schedule_reminders']['ready_signal'] ?? -1));
        $this->assertSame('missing', (string) ($otherReadiness['loops']['content_schedule_reminders']['status'] ?? ''));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseOneHundredTwoAiWorkspaceBrainBuildsUnifiedContextAndExplainsGaps(): void
    {
        $emptyBrain = $this->marketing->getMarketingAiWorkspaceBrain($this->userId);
        $this->assertArrayHasKey('coverage', $emptyBrain);
        $this->assertArrayHasKey('safe_usage_policy', $emptyBrain);
        $this->assertFalse((bool) ($emptyBrain['safe_usage_policy']['external_send'] ?? true));
        $this->assertFalse((bool) ($emptyBrain['safe_usage_policy']['external_publish'] ?? true));
        $this->assertFalse((bool) ($emptyBrain['safe_usage_policy']['automatic_overwrite'] ?? true));
        $this->assertNotEmpty($emptyBrain['missing_context']);

        $brandId = $this->marketing->createBrandProfile([
            'name' => 'Phase 102 Brand',
            'voice' => 'Clear and helpful.',
            'tone' => 'Confident operator.',
            'value_props' => 'CRM-native marketing context.',
            'proof_points' => 'Connected campaigns, forms, content, and follow-up.',
            'cta_defaults' => 'Book a planning call',
            'is_default' => 1,
            'created_by' => $this->userId,
        ]);
        $personaId = $this->marketing->createPersona([
            'name' => 'Phase 102 Revenue Operator',
            'segment' => 'Marketing operator',
            'pains' => 'Fragmented campaign context.',
            'goals' => 'Launch with confidence.',
            'preferred_channels' => ['email', 'linkedin'],
            'created_by' => $this->userId,
        ]);
        foreach ([
            'offer' => 'Workflow audit',
            'proof_point' => 'Attribution and follow-up are connected',
            'content_pillar' => 'Campaign operating discipline',
            'default_cta' => 'Book a planning call',
            'compliance_term' => 'Human review required',
            'competitor' => 'Disconnected content tool',
            'differentiator' => 'CRM-native manual-first execution',
        ] as $type => $title) {
            $this->marketing->createContextItem([
                'item_type' => $type,
                'title' => 'Phase 102 ' . $title,
                'body' => 'Phase 102 context body for ' . $type . '.',
                'persona_id' => $personaId,
                'status' => 'active',
                'created_by' => $this->userId,
            ]);
        }
        $landingId = $this->marketing->createLandingPage([
            'title' => 'Phase 102 Landing',
            'slug' => 'phase-102-landing-' . uniqid(),
            'headline' => 'Unify marketing context before launch',
            'created_by' => $this->userId,
        ]);
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Phase 102 Brief',
            'objective' => 'Create a reliable AI context baseline.',
            'audience' => 'Marketing operators',
            'offer_text' => 'Workspace brain review',
            'key_message' => 'Use saved context before drafting.',
            'channels' => ['email', 'linkedin'],
            'persona_id' => $personaId,
            'landing_page_id' => $landingId,
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 102 Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'status' => 'review',
            'brand_profile_id' => $brandId,
            'persona_id' => $personaId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingId,
            'objective' => 'Explain the workspace brain.',
            'target_audience' => 'Marketing operators',
            'draft_body' => 'This draft uses brand, persona, offer, proof, and manual-first guardrails.',
            'created_by' => $this->userId,
        ]);
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Phase 102 Hero Image',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/phase-102-hero.png',
            'alt_text' => 'Phase 102 hero image',
            'usage_rights' => 'Internal test rights',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'featured',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'linkedin',
            'planned_copy' => 'Phase 102 distribution copy.',
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createUtmLink([
            'content_item_id' => $contentId,
            'url' => 'https://example.com/phase-102',
            'utm_source' => 'linkedin',
            'utm_medium' => 'social',
            'utm_campaign' => 'phase_102',
            'created_by' => $this->userId,
        ]);
        Database::execute(
            "INSERT INTO campaigns (workspace_id, uuid, name, status, created_by)
             VALUES (?, UUID(), ?, 'active', ?)",
            [$this->workspaceId, 'Phase 102 AI Context Campaign', $this->userId]
        );
        $campaignId = (int) Database::lastInsertId();
        $this->marketing->refreshMarketingCampaignWorkspace($campaignId, $this->userId);
        $this->marketing->createCampaignExecutionActionSnapshot($campaignId, $this->userId);

        $brain = $this->marketing->getMarketingAiWorkspaceBrain($this->userId);
        $this->assertGreaterThan((int) ($emptyBrain['score'] ?? 0), (int) ($brain['score'] ?? 0));
        $this->assertSame('ready', (string) ($brain['coverage']['brand_voice']['status'] ?? ''));
        $this->assertSame('ready', (string) ($brain['coverage']['personas']['status'] ?? ''));
        $this->assertSame('ready', (string) ($brain['coverage']['offer_proof_context']['status'] ?? ''));
        $this->assertSame('ready', (string) ($brain['coverage']['execution_evidence']['status'] ?? ''));
        $this->assertSame(1, (int) ($brain['counts']['execution_action_snapshots'] ?? 0));
        $this->assertContains('brand_voice', (array) ($brain['used_context_keys'] ?? []));
        $this->assertContains('execution_evidence', (array) ($brain['used_context_keys'] ?? []));
        $this->assertArrayHasKey('integration_summary', $brain);
        $this->assertArrayHasKey('automation_summary', $brain);
        $this->assertTrue((bool) ($brain['performance']['summary_counts_are_direct'] ?? false));

        $summary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertArrayHasKey('ai_workspace_brain', $summary);
        $this->assertGreaterThanOrEqual((int) ($brain['score'] ?? 0) - 5, (int) ($summary['ai_workspace_brain']['score'] ?? 0));

        $assistant = $this->marketing->runMarketingAssistant('copywriter', [
            'prompt' => 'Explain which workspace context shaped this draft.',
            'content_item_id' => $contentId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingId,
            'created_by' => $this->userId,
        ]);
        $this->assertSame('completed', (string) ($assistant['status'] ?? ''));
        $run = $this->marketing->listMarketingAssistantRuns(['content_item_id' => $contentId], 1, 0)[0] ?? [];
        $this->assertArrayHasKey('workspace_brain', (array) ($run['context_used_json'] ?? []));
        $this->assertGreaterThan(0, (int) ($run['provider_json']['workspace_brain_score'] ?? 0));
        $this->assertArrayHasKey('recommended_context_inputs', (array) ($run['provider_json'] ?? []));

        $assistantCenter = $this->marketing->getMarketingAssistantCommandCenter($this->userId);
        $this->assertArrayHasKey('workspace_brain', $assistantCenter);
        $this->assertStringContainsString('workspace brain', strtolower(implode(' ', (array) ($assistantCenter['guardrails'] ?? []))));

        $page = $this->runWebEndpoint('public/marketing_assistants.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_assistants.php phase 102');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('AI Workspace Brain', $body);
        $this->assertStringContainsString('AI context quality', $body);

        $dashboard = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($dashboard, 200, 'marketing.php phase 102');
        $this->assertStringContainsString('AI Workspace Brain', (string) ($dashboard['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Phase 102 Workspace Brain Isolation');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherBrain = (new Marketing())->getMarketingAiWorkspaceBrain($this->userId);
        $this->assertSame(0, (int) ($otherBrain['counts']['brand_profiles'] ?? -1));
        $this->assertSame(0, (int) ($otherBrain['counts']['content_items'] ?? -1));
        $this->assertSame(0, (int) ($otherBrain['counts']['execution_action_snapshots'] ?? -1));
        $this->assertNotSame((int) ($brain['counts']['content_items'] ?? 0), (int) ($otherBrain['counts']['content_items'] ?? 0));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseOneHundredEighteenAiContextControlPanelExplainsSelectedRecordsAndGuardrails(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Context Control Campaign');
        $brandId = $this->marketing->createBrandProfile([
            'name' => 'Context Control Brand',
            'voice' => 'Precise and practical.',
            'tone' => 'Operator confident.',
            'value_props' => 'CRM-native campaign execution.',
            'cta_defaults' => 'Book a workflow review',
            'is_default' => 1,
            'created_by' => $this->userId,
        ]);
        $personaId = $this->marketing->createPersona([
            'name' => 'Context Control Persona',
            'segment' => 'Marketing operator',
            'pains' => 'AI output without visible context.',
            'goals' => 'Preview context before drafting.',
            'preferred_channels' => ['email', 'linkedin'],
            'created_by' => $this->userId,
        ]);
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Context Control Brief',
            'objective' => 'Show selected AI context before generation.',
            'audience' => 'Marketing operators',
            'offer_text' => 'AI context preflight',
            'key_message' => 'Inspect selected records and missing inputs.',
            'channels' => ['email', 'linkedin'],
            'campaign_id' => $campaignId,
            'persona_id' => $personaId,
            'created_by' => $this->userId,
        ]);
        $landingId = $this->marketing->createLandingPage([
            'title' => 'Context Control Landing',
            'slug' => 'context-control-' . uniqid(),
            'headline' => 'See AI context before you generate',
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Context Control Content',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'draft',
            'brand_profile_id' => $brandId,
            'persona_id' => $personaId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingId,
            'campaign_id' => $campaignId,
            'objective' => 'Explain the AI context control panel.',
            'target_audience' => 'Marketing operators',
            'draft_body' => 'Context control keeps AI work reviewable and manual-first.',
            'created_by' => $this->userId,
        ]);
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Context Control Hero',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/context-control.png',
            'alt_text' => 'Context control hero',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'featured',
            'created_by' => $this->userId,
        ]);

        $panel = $this->marketing->getMarketingAiContextControlPanel([
            'assistant_type' => 'copywriter',
            'content_item_id' => $contentId,
            'campaign_brief_id' => $briefId,
            'landing_page_id' => $landingId,
        ], $this->userId);
        $this->assertSame('copywriter', (string) ($panel['assistant_type'] ?? ''));
        $this->assertSame('assistant_copywriter', (string) ($panel['prompt_key'] ?? ''));
        $this->assertGreaterThan(0, (int) ($panel['score'] ?? 0));
        $this->assertSame('ready', (string) ($panel['selected_records']['content_item']['status'] ?? ''));
        $this->assertSame('ready', (string) ($panel['selected_records']['brand_profile']['status'] ?? ''));
        $this->assertSame('ready', (string) ($panel['selected_records']['persona']['status'] ?? ''));
        $this->assertSame('ready', (string) ($panel['selected_records']['media']['status'] ?? ''));
        $this->assertContains('content_item', (array) ($panel['linked_record_keys'] ?? []));
        $this->assertContains('content_media', (array) ($panel['linked_record_keys'] ?? []));
        $this->assertFalse((bool) ($panel['safe_usage_policy']['external_send'] ?? true));
        $this->assertFalse((bool) ($panel['safe_usage_policy']['external_publish'] ?? true));
        $this->assertTrue((bool) ($panel['safe_usage_policy']['requires_human_review'] ?? false));

        $page = $this->runWebEndpoint('public/marketing_assistants.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => [
                'assistant_type' => 'copywriter',
                'content_item_id' => $contentId,
                'campaign_brief_id' => $briefId,
                'landing_page_id' => $landingId,
            ],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_assistants.php AI context control panel');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('AI Context Control Panel', $body);
        $this->assertStringContainsString('Context Control Content', $body);
        $this->assertStringContainsString('Draft-side only', $body);
    }

    public function testPhaseOneHundredThreeGuidedRecommendationsTurnGapsIntoActions(): void
    {
        $empty = $this->marketing->getMarketingGuidedRecommendations($this->userId, 12);
        $emptyLabels = array_column((array) ($empty['recommendations'] ?? []), 'label');
        $this->assertContains('Complete AI Workspace Context', $emptyLabels);
        $this->assertContains('Create First Content Item', $emptyLabels);
        $this->assertFalse((bool) ($empty['manual_first_boundary']['external_send'] ?? true));
        $this->assertFalse((bool) ($empty['manual_first_boundary']['external_publish'] ?? true));

        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 103 Approved Content Without CTA',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'status' => 'approved',
            'draft_body' => 'A helpful educational article that explains the offer but does not contain a next step.',
            'created_by' => $this->userId,
        ]);

        $withGaps = $this->marketing->getMarketingGuidedRecommendations($this->userId, 12);
        $labels = array_column((array) ($withGaps['recommendations'] ?? []), 'label');
        $this->assertContains('Add Clear Content CTAs', $labels);
        $this->assertContains('Resolve Media Readiness', $labels);
        $this->assertContains('Create Distribution Variants', $labels);
        $ctaRecommendation = array_values(array_filter(
            (array) ($withGaps['recommendations'] ?? []),
            static fn(array $recommendation): bool => (string) ($recommendation['key'] ?? '') === 'add_clear_content_ctas'
        ))[0] ?? [];
        $this->assertSame('high', (string) ($ctaRecommendation['priority'] ?? ''));
        $this->assertGreaterThanOrEqual(1, (int) ($ctaRecommendation['evidence_count'] ?? 0));

        $summary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertArrayHasKey('guided_recommendations', $summary);
        $summaryLabels = array_column((array) ($summary['guided_recommendations']['recommendations'] ?? []), 'label');
        $this->assertContains('Add Clear Content CTAs', $summaryLabels);

        $nextBest = $this->marketing->getMarketingNextBestActions($this->userId, 12, [
            'guided_recommendations' => $withGaps,
        ]);
        $this->assertContains('Guided Recommendation', array_column((array) ($nextBest['actions'] ?? []), 'source'));

        $dashboard = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($dashboard, 200, 'marketing.php phase 103');
        $this->assertStringNotContainsString('Guided Marketing Recommendations', (string) ($dashboard['body'] ?? ''));
        $this->assertStringNotContainsString('Add Clear Content CTAs', (string) ($dashboard['body'] ?? ''));
        $marketingContext = MarketingPageContextService::contextForPage('marketing.php');
        $this->assertSame('clarity', (string) ($marketingContext['ui_policy']['recommendation_surface'] ?? ''));
        $this->assertContains('guided recommendations', (array) ($marketingContext['clarity_recommendation_context']['sources'] ?? []));

        $setup = $this->runWebEndpoint('public/marketing_onboarding.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($setup, 200, 'marketing_onboarding.php phase 103');
        $this->assertStringContainsString('Recommended Next Steps', (string) ($setup['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Phase 103 Guided Recommendation Isolation');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherRecommendations = (new Marketing())->getMarketingGuidedRecommendations($this->userId, 12);
        $otherLabels = array_column((array) ($otherRecommendations['recommendations'] ?? []), 'label');
        $this->assertContains('Create First Content Item', $otherLabels);
        $this->assertNotContains('Add Clear Content CTAs', $otherLabels);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        $this->assertNotNull($this->marketing->getContentItem($contentId));
    }

    public function testPhaseOneHundredFiveCrmLifecycleProfileConnectsMarketingToCrmRecords(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Phase 105 Lifecycle Campaign');
        $formId = $this->createForm($this->workspaceId, 'Phase 105 Lifecycle Form');
        $formUuid = (string) (Database::queryOne('SELECT uuid FROM forms WHERE id = ?', [$formId])['uuid'] ?? '');
        Database::execute(
            "INSERT INTO companies (workspace_id, uuid, name, industry, created_at, updated_at)
             VALUES (?, UUID(), 'Phase 105 Company', 'SaaS', NOW(), NOW())",
            [$this->workspaceId]
        );
        $companyId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, company_id, lead_source, stage, created_by)
             VALUES (?, UUID(), 'Phase', 'Lifecycle', 'phase105@example.com', ?, 'landing_page', 'qualified', ?)",
            [$this->workspaceId, $companyId, $this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, description, contact_id, company_id, assigned_to, created_by, stage, value, probability, currency, campaign_id)
             VALUES (?, 'Phase 105 Deal', 'Lifecycle integration deal.', ?, ?, ?, ?, 'proposal', 15000, 70, 'USD', ?)",
            [$this->workspaceId, $contactId, $companyId, $this->userId, $this->userId, $campaignId]
        );
        $dealId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO form_submissions (workspace_id, visitor_id, contact_id, form_id, form_definition_id, form_data, page_path)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$this->workspaceId, 'phase-105-visitor', $contactId, $formUuid, $formId, json_encode(['email' => 'phase105@example.com']), '/phase-105']
        );
        Database::execute(
            "INSERT INTO tasks (workspace_id, title, contact_id, assigned_to, created_by, status, priority)
             VALUES (?, 'Phase 105 follow-up', ?, ?, ?, 'pending', 'high')",
            [$this->workspaceId, $contactId, $this->userId, $this->userId]
        );

        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Phase 105 Audience',
            'description' => 'Lifecycle profile audience.',
            'status' => 'active',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 105 Lifecycle Content',
            'content_type' => 'case_study',
            'channel' => 'website',
            'status' => 'approved',
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'target_audience' => 'Qualified SaaS buyers',
            'draft_body' => 'Lifecycle content with a clear CTA to request a demo.',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Phase 105 Landing',
            'slug' => 'phase-105-landing-' . uniqid(),
            'headline' => 'Connect marketing to revenue',
            'body_sections' => [['heading' => 'Lifecycle', 'body' => 'Tie every touch to CRM context.']],
            'cta_blocks' => [['heading' => 'Book', 'body' => 'Request a demo.']],
            'conversion_goal' => 'demo_request',
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'audience_segment_id' => $segmentId,
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createUtmLink([
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'url' => 'https://example.com/phase-105',
            'utm_source' => 'linkedin',
            'utm_medium' => 'social',
            'utm_campaign' => 'phase105',
            'created_by' => $this->userId,
        ]);
        $handoffId = $this->marketing->createLeadHandoff([
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'landing_page_id' => $landingPageId,
            'content_item_id' => $contentId,
            'contact_id' => $contactId,
            'deal_id' => $dealId,
            'assigned_to' => $this->userId,
            'status' => 'assigned',
            'priority' => 'high',
            'source' => 'phase_105_test',
            'handoff_note' => 'Lifecycle profile should see this handoff.',
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $handoffId);
        Database::execute(
            "INSERT INTO marketing_attribution_touchpoints
             (workspace_id, uuid, campaign_id, content_item_id, form_id, contact_id, deal_id, touchpoint_type, attribution_model, source, medium, campaign_name, occurred_at)
             VALUES (?, UUID(), ?, ?, ?, ?, ?, 'conversion', 'linear', 'linkedin', 'social', 'Phase 105 Lifecycle Campaign', NOW())",
            [$this->workspaceId, $campaignId, $contentId, $formId, $contactId, $dealId]
        );

        $profile = $this->marketing->getMarketingCrmLifecycleProfile(['deal_id' => $dealId], 6);
        $this->assertSame('deal', (string) ($profile['scope'] ?? ''));
        $this->assertStringContainsString('Phase 105 Deal', (string) ($profile['scope_label'] ?? ''));
        $this->assertGreaterThanOrEqual(80, (int) ($profile['score'] ?? 0));
        $this->assertSame(1, (int) ($profile['counts']['contacts'] ?? 0));
        $this->assertSame(1, (int) ($profile['counts']['open_deals'] ?? 0));
        $this->assertSame(1, (int) ($profile['counts']['form_submissions'] ?? 0));
        $this->assertSame(1, (int) ($profile['counts']['content_items'] ?? 0));
        $this->assertSame(1, (int) ($profile['counts']['landing_pages'] ?? 0));
        $this->assertSame(1, (int) ($profile['counts']['utm_links'] ?? 0));
        $this->assertSame(1, (int) ($profile['counts']['lead_handoffs_open'] ?? 0));
        $this->assertSame(1, (int) ($profile['counts']['attribution_touchpoints'] ?? 0));
        $this->assertFalse((bool) ($profile['manual_first_boundary']['external_send'] ?? true));
        $this->assertFalse((bool) ($profile['manual_first_boundary']['external_publish'] ?? true));
        $this->assertNotEmpty($profile['linked_records']['lead_handoffs'] ?? []);

        $snapshot = $this->marketing->createMarketingCrmLifecycleInsightSnapshot(['deal_id' => $dealId], $this->userId);
        $this->assertGreaterThan(0, (int) ($snapshot['id'] ?? 0));
        $snapshots = $this->marketing->listMarketingCrmLifecycleInsightSnapshots(['deal_id' => $dealId]);
        $this->assertCount(1, $snapshots);
        $this->assertSame($dealId, (int) ($snapshots[0]['deal_id'] ?? 0));
        $this->assertSame(1, (int) ($snapshots[0]['counts_json']['lead_handoffs_open'] ?? 0));

        $summary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertArrayHasKey('crm_lifecycle_profile', $summary);
        $this->assertArrayHasKey('contacts', (array) ($summary['crm_lifecycle_profile']['counts'] ?? []));

        $dashboard = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($dashboard, 200, 'marketing.php phase 105');
        $this->assertStringContainsString('CRM Lifecycle Integration', (string) ($dashboard['body'] ?? ''));

        $dealPage = $this->runWebEndpoint('public/deal_view.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['id' => $dealId],
        ]);
        $this->assertEndpointHealthy($dealPage, 200, 'deal_view.php phase 105');
        $this->assertStringContainsString('Marketing CRM Integration', (string) ($dealPage['body'] ?? ''));
        $this->assertStringContainsString('Lifecycle signals tied to this deal', (string) ($dealPage['body'] ?? ''));

        $contactPage = $this->runWebEndpoint('public/contact_view.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['id' => $contactId],
        ]);
        $this->assertEndpointHealthy($contactPage, 200, 'contact_view.php phase 105');
        $this->assertStringContainsString('Marketing CRM Integration', (string) ($contactPage['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Phase 105 CRM Lifecycle Isolation');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherProfile = (new Marketing())->getMarketingCrmLifecycleProfile([], 6);
        $this->assertSame(0, (int) ($otherProfile['counts']['contacts'] ?? -1));
        try {
            (new Marketing())->getMarketingCrmLifecycleProfile(['deal_id' => $dealId], 6);
            $this->fail('Cross-workspace deal lifecycle profile lookup should fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseSixtyThreeMarketingRouteRegistryCoversEveryInternalPage(): void
    {
        $root = dirname(__DIR__, 3);
        $allMarketingPages = array_map('basename', glob($root . '/public/marketing*.php') ?: []);
        sort($allMarketingPages);

        $publicEndpoints = [
            'marketing_landing_public.php',
            'marketing_track.php',
        ];
        $expectedInternalPages = array_values(array_diff($allMarketingPages, $publicEndpoints));
        sort($expectedInternalPages);

        $registeredInternalPages = MarketingUi::internalPageFilenames();
        sort($registeredInternalPages);

        $this->assertSame(
            $expectedInternalPages,
            $registeredInternalPages,
            'Every authenticated Marketing page should load the shared operator UI foundation.'
        );
        $this->assertSameSize(
            array_unique($registeredInternalPages),
            $registeredInternalPages,
            'Marketing internal page registry should not contain duplicates.'
        );

        $sectionPages = [];
        foreach (MarketingUi::sectionDefinitions() as $section) {
            foreach ($section['pages'] as $page) {
                $sectionPages[] = $page;
            }
        }
        sort($sectionPages);
        $this->assertSame(
            $registeredInternalPages,
            array_values(array_unique($sectionPages)),
            'Every internal Marketing page should belong to an operator navigation section.'
        );

        $guidePages = array_keys(MarketingPageGuideUi::pageDefinitions());
        sort($guidePages);
        $guideExcludedPages = ['marketing_landing_page_preview.php'];
        $expectedGuidePages = array_values(array_diff($expectedInternalPages, $guideExcludedPages));
        sort($expectedGuidePages);
        $this->assertSame(
            $expectedGuidePages,
            $guidePages,
            'Every eligible internal Marketing page should have a Watch guide page key.'
        );
        $this->assertNotContains('marketing_landing_public.php', $guidePages);
        $this->assertNotContains('marketing_track.php', $guidePages);

        $ownerSession = $this->webSession('owner');
        foreach ([
            'marketing_audience_activation.php',
            'marketing_launch_readiness.php',
            'marketing_launch_control.php',
            'marketing_guided_workflows.php',
            'marketing_task_hub.php',
        ] as $page) {
            $response = $this->runWebEndpoint('public/' . $page, $ownerSession, ['method' => 'GET']);
            $this->assertEndpointHealthy($response, 200, $page);
            $this->assertStringContainsString('marketing-operator-strip', (string) ($response['body'] ?? ''), $page);
        }
    }

    public function testPhaseTwentyNineMediaCrudAndAssetLinking(): void
    {
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Launch Hero Image',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/launch-hero.jpg',
            'alt_text' => 'Team launching a campaign',
            'caption' => 'Launch campaign hero',
            'usage_rights' => 'Owned media',
            'tags' => 'launch,hero',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $media = $this->marketing->getMediaFile($mediaId);

        $this->assertSame('Launch Hero Image', (string) ($media['title'] ?? ''));
        $this->assertSame('image', (string) ($media['media_type'] ?? ''));
        $this->assertSame(['launch', 'hero'], (array) ($media['tags_json'] ?? []));
        $this->assertSame('https://example.com/launch-hero.jpg', $this->marketing->mediaDisplayUrl($media ?? []));

        $this->assertTrue($this->marketing->updateMediaFile($mediaId, ['caption' => 'Updated caption', 'tags' => ['launch', 'updated']]));
        $updated = $this->marketing->getMediaFile($mediaId);
        $this->assertSame('Updated caption', (string) ($updated['caption'] ?? ''));
        $this->assertSame(['launch', 'updated'], (array) ($updated['tags_json'] ?? []));

        $assetId = $this->marketing->createAsset([
            'title' => 'Hero Asset',
            'asset_type' => 'image',
            'media_file_id' => $mediaId,
            'channel' => 'website',
            'created_by' => $this->userId,
        ]);
        $assets = $this->marketing->listAssets(['asset_type' => 'image']);
        $asset = array_values(array_filter($assets, static fn(array $row): bool => (int) ($row['id'] ?? 0) === $assetId))[0] ?? [];
        $this->assertSame($mediaId, (int) ($asset['media_file_id'] ?? 0));
        $this->assertSame('Launch Hero Image', (string) ($asset['media_title'] ?? ''));
    }

    public function testPhaseTwentyNineMediaWorkspaceIsolationAndValidation(): void
    {
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Workspace Media',
            'media_type' => 'video',
            'source_type' => 'url',
            'source_url' => 'https://example.com/video.mp4',
            'created_by' => $this->userId,
        ]);
        $otherWorkspaceId = $this->createWorkspace('Other Media Workspace');

        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getMediaFile($mediaId));
        try {
            $otherMarketing->createAsset([
                'title' => 'Foreign Media Asset',
                'asset_type' => 'video',
                'media_file_id' => $mediaId,
            ]);
            $this->fail('Cross-workspace media should not be linkable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Media URLs must start with http or https.');
        $this->marketing->createMediaFile([
            'title' => 'Unsafe URL Media',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'javascript:alert(1)',
        ]);
    }

    public function testPhaseTwentyNineUploadValidationRejectsUnsupportedOrOversizedFiles(): void
    {
        $badFile = tempnam(sys_get_temp_dir(), 'bad-media-');
        file_put_contents($badFile, '<?php echo "bad";');

        try {
            $this->marketing->createMediaFileFromUpload([
                'name' => 'bad.php',
                'tmp_name' => $badFile,
                'size' => filesize($badFile),
                'error' => UPLOAD_ERR_OK,
                'type' => 'application/x-php',
            ], ['title' => 'Bad Upload', 'created_by' => $this->userId]);
            $this->fail('Unsupported upload should be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unsupported media file type', $e->getMessage());
        } finally {
            if (is_file($badFile)) {
                @unlink($badFile);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('50 MB');
        $this->marketing->createMediaFile([
            'title' => 'Oversized Media',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/oversized.png',
            'file_size' => 52428801,
        ]);
    }

    public function testDesignStudioMediaUploadEndpointCreatesReusableWorkspaceMedia(): void
    {
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Inline Media Upload Landing',
            'headline' => 'Upload media without leaving Design Studio',
            'created_by' => $this->userId,
        ]);
        $sourcePath = tempnam(sys_get_temp_dir(), 'design-media-');
        $this->assertIsString($sourcePath);
        file_put_contents(
            $sourcePath,
            "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"
        );
        $storedPath = '';

        try {
            $response = $this->runWebEndpoint('api/marketing/landing_page_media.php', $this->webSession('owner'), [
                'method' => 'POST',
                'post' => [
                    'csrf_token' => 'csrf-marketing',
                    'page_id' => $landingPageId,
                ],
                'files' => [
                    'media_file' => [
                        'name' => 'inline-hero.gif',
                        'type' => 'image/gif',
                        'tmp_name' => $sourcePath,
                        'error' => UPLOAD_ERR_OK,
                        'size' => filesize($sourcePath),
                    ],
                ],
            ]);
            $payload = json_decode((string) ($response['body'] ?? ''), true);
            $storedPath = (string) ($payload['media']['file_path'] ?? '');

            $this->assertEndpointHealthy($response, 201, 'Design Studio inline media upload');
            $this->assertTrue((bool) ($payload['success'] ?? false), (string) ($response['body'] ?? ''));
            $this->assertSame('Inline Hero', (string) ($payload['media']['title'] ?? ''));
            $this->assertSame('Inline Hero', (string) ($payload['media']['alt_text'] ?? ''));
            $this->assertSame('image', (string) ($payload['media']['media_type'] ?? ''));
            $this->assertStringStartsWith('uploads/marketing/media/' . $this->workspaceId . '/', $storedPath);
            $this->assertFileExists(__DIR__ . '/../../../' . $storedPath);
            $this->assertSame(
                $this->workspaceId,
                (int) (Database::queryOne('SELECT workspace_id FROM marketing_media_files WHERE id = ?', [(int) ($payload['media']['id'] ?? 0)])['workspace_id'] ?? 0)
            );
        } finally {
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }
            if ($storedPath !== '' && is_file(__DIR__ . '/../../../' . $storedPath)) {
                @unlink(__DIR__ . '/../../../' . $storedPath);
            }
        }
    }

    public function testPhaseTwentyNineAssetsPageRendersMediaLibrary(): void
    {
        $this->marketing->createMediaFile([
            'title' => 'Renderable Media',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/renderable.png',
            'alt_text' => 'Renderable media alt text',
            'created_by' => $this->userId,
        ]);

        $response = $this->runWebEndpoint('public/marketing_assets.php', $this->webSession('owner'), ['method' => 'GET']);
        $body = (string) ($response['body'] ?? '');

        $this->assertEndpointHealthy($response, 200, 'marketing_assets.php media library');
        $this->assertStringContainsString('Media Library', $body);
        $this->assertStringContainsString('Renderable Media', $body);
        $this->assertStringContainsString('Renderable media alt text', $body);
        $this->assertStringContainsString('Add Media', $body);
    }

    public function testPhaseThirtyLandingPagesSaveAndRenderMedia(): void
    {
        $heroMediaId = $this->marketing->createMediaFile([
            'title' => 'Landing Hero Visual',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/landing-hero.jpg',
            'alt_text' => 'Campaign hero visual',
            'caption' => 'Hero visual caption',
            'created_by' => $this->userId,
        ]);
        $socialMediaId = $this->marketing->createMediaFile([
            'title' => 'Social Preview Visual',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/social-preview.jpg',
            'alt_text' => 'Social preview thumbnail',
            'created_by' => $this->userId,
        ]);
        $videoMediaId = $this->marketing->createMediaFile([
            'title' => 'Proof Video',
            'media_type' => 'video',
            'source_type' => 'url',
            'source_url' => 'https://example.com/proof-video.mp4',
            'caption' => 'Proof video caption',
            'transcript' => 'Proof video transcript',
            'created_by' => $this->userId,
        ]);

        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Media Rich Landing Page',
            'slug' => 'media-rich-landing-page',
            'headline' => 'Bring the campaign to life visually',
            'meta_description' => 'A landing page with safe image and video media.',
            'body_sections' => "Make it visual\nShow the offer with media.",
            'cta_blocks' => "Start now\nBook the visual campaign review.",
            'proof_blocks' => "Proof\nShow an approved customer result.",
            'conversion_goal' => 'demo_request',
            'status' => 'approved',
            'hero_media_file_id' => $heroMediaId,
            'social_preview_media_file_id' => $socialMediaId,
            'cta_media_file_id' => $heroMediaId,
            'proof_media_file_id' => $videoMediaId,
            'section_media' => 'Make it visual | ' . $heroMediaId . ' | inline | Section media caption',
            'gallery_media_ids' => $heroMediaId . ', ' . $videoMediaId,
            'created_by' => $this->userId,
        ]);

        $page = $this->marketing->getLandingPage($landingPageId);
        $this->assertSame('Landing Hero Visual', (string) ($page['_media']['hero']['title'] ?? ''));
        $this->assertSame('Social Preview Visual', (string) ($page['_media']['social_preview']['title'] ?? ''));
        $this->assertCount(1, (array) ($page['section_media_json'] ?? []));
        $this->assertCount(2, (array) ($page['gallery_media_json'] ?? []));

        $readiness = $this->marketing->getLandingPagePublishingReadiness($landingPageId);
        $this->assertSame(100, (int) ($readiness['score'] ?? 0));
        $this->assertTrue((bool) ($readiness['checks']['hero_media'] ?? false));
        $this->assertTrue((bool) ($readiness['checks']['social_preview_media'] ?? false));
        $this->assertSame([], (array) ($readiness['media_warnings'] ?? []));

        $view = $this->runWebEndpoint('public/marketing_landing_page_view.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['id' => $landingPageId],
        ]);
        $this->assertEndpointHealthy($view, 200, 'marketing_landing_page_view.php media');
        $this->assertStringContainsString('landing-hero.jpg', (string) ($view['body'] ?? ''));
        $this->assertStringContainsString('Section media caption', (string) ($view['body'] ?? ''));

        $preview = $this->runWebEndpoint('public/marketing_landing_page_preview.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['token' => (string) ($page['preview_token'] ?? '')],
        ]);
        $this->assertEndpointHealthy($preview, 200, 'marketing_landing_page_preview.php media');
        $this->assertStringContainsString('landing-hero.jpg', (string) ($preview['body'] ?? ''));
        $this->assertStringContainsString('proof-video.mp4', (string) ($preview['body'] ?? ''));

        $publication = $this->marketing->publishLandingPage($landingPageId, $this->userId);
        $public = $this->runEndpointScript('public/marketing_landing_public.php', [
            'method' => 'GET',
            'query' => ['token' => (string) ($publication['public_token'] ?? '')],
        ]);
        $this->assertEndpointHealthy($public, 200, 'marketing_landing_public.php media');
        $this->assertStringContainsString('og:image', (string) ($public['body'] ?? ''));
        $this->assertStringContainsString('social-preview.jpg', (string) ($public['body'] ?? ''));
        $this->assertStringContainsString('proof-video.mp4', (string) ($public['body'] ?? ''));
    }

    public function testPhaseThirtyLandingPageMediaOwnershipAndTextOnlyFallback(): void
    {
        $otherWorkspaceId = $this->createWorkspace('Other Landing Media Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMediaId = (new Marketing())->createMediaFile([
            'title' => 'Foreign Hero',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/foreign-hero.jpg',
            'created_by' => $this->userId,
        ]);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        try {
            $this->marketing->createLandingPage([
                'title' => 'Bad Foreign Media Page',
                'hero_media_file_id' => $otherMediaId,
            ]);
            $this->fail('Cross-workspace landing page media should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        }

        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Text Only Landing Page',
            'slug' => 'text-only-landing-page',
            'headline' => 'Text only still works',
            'body_sections' => "Message\nThis page can publish without selected media.",
            'cta_blocks' => "CTA\nAsk a human to follow up.",
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);

        $readiness = $this->marketing->getLandingPagePublishingReadiness($landingPageId);
        $this->assertSame(100, (int) ($readiness['score'] ?? 0));
        $this->assertSame([], (array) ($readiness['missing'] ?? []));
        $this->assertContains('hero_media_recommended', (array) ($readiness['media_warnings'] ?? []));

        $publication = $this->marketing->publishLandingPage($landingPageId, $this->userId);
        $public = $this->runEndpointScript('public/marketing_landing_public.php', [
            'method' => 'GET',
            'query' => ['token' => (string) ($publication['public_token'] ?? '')],
        ]);
        $this->assertEndpointHealthy($public, 200, 'marketing_landing_public.php text only');
        $this->assertStringContainsString('Text only still works', (string) ($public['body'] ?? ''));
    }

    public function testPhaseThirtyLandingPageAiContextIncludesMediaRecommendations(): void
    {
        $draft = $this->marketing->generateLandingPageCopyDraft([
            'title' => 'Visual Recommendation Landing',
            'conversion_goal' => 'lead_capture',
        ]);
        $recommendations = (array) ($draft['ai_context']['media_recommendations'] ?? []);
        $this->assertNotEmpty($recommendations);
        $this->assertStringContainsString('hero image', implode(' ', $recommendations));
        $this->assertStringContainsString('social preview image', implode(' ', $recommendations));

        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Captionless Image',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/captionless.jpg',
            'created_by' => $this->userId,
        ]);
        $mediaDraft = $this->marketing->generateLandingPageCopyDraft([
            'title' => 'Selected Visual Landing',
            'hero_media_file_id' => $mediaId,
            'social_preview_media_file_id' => $mediaId,
        ]);
        $this->assertStringContainsString('Add alt text', implode(' ', (array) ($mediaDraft['ai_context']['media_recommendations'] ?? [])));
    }

    public function testPhaseThirtyOneContentMediaAttachDetachReorderAndReadiness(): void
    {
        $imageId = $this->marketing->createMediaFile([
            'title' => 'Blog Feature Image',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/blog-feature.jpg',
            'alt_text' => 'Feature image alt',
            'created_by' => $this->userId,
        ]);
        $videoId = $this->marketing->createMediaFile([
            'title' => 'Reference Video',
            'media_type' => 'video',
            'source_type' => 'url',
            'source_url' => 'https://example.com/reference-video.mp4',
            'caption' => 'Reference video caption',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Media Ready Blog',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'draft_body' => 'Draft body.',
            'created_by' => $this->userId,
        ]);

        $missing = $this->marketing->getContentItem($contentId);
        $this->assertContains('featured_image_recommended', (array) ($missing['_media_readiness']['warnings'] ?? []));

        $firstAttachment = $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $imageId,
            'role' => 'featured',
            'caption' => 'Blog feature caption',
            'placement_notes' => 'Above the intro',
            'crop_guidance' => '16:9',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $videoId,
            'role' => 'reference',
            'caption' => 'Reference clip',
            'sort_order' => 2,
            'created_by' => $this->userId,
        ]);

        $attachments = $this->marketing->listContentMedia($contentId);
        $this->assertCount(2, $attachments);
        $this->assertSame('featured', (string) $attachments[0]['role']);
        $this->assertSame('Blog feature caption', (string) $attachments[0]['effective_caption']);
        $this->assertSame('https://example.com/blog-feature.jpg', (string) $attachments[0]['display_url']);

        $ready = $this->marketing->getContentItem($contentId);
        $this->assertNotContains('featured_image_recommended', (array) ($ready['_media_readiness']['warnings'] ?? []));

        $this->marketing->replaceContentMedia(
            $contentId,
            "reference | {$videoId} | Reordered video | | First reference\nfeatured | {$imageId} | Reordered feature | Feature alt override | Hero slot",
            $this->userId
        );
        $reordered = $this->marketing->listContentMedia($contentId);
        $this->assertSame('reference', (string) $reordered[0]['role']);
        $this->assertSame('featured', (string) $reordered[1]['role']);
        $this->assertSame('Feature alt override', (string) $reordered[1]['effective_alt_text']);

        $this->assertTrue($this->marketing->detachContentMedia((int) $reordered[0]['id']));
        $this->assertCount(1, $this->marketing->listContentMedia($contentId));
        $this->assertNull($this->marketing->getContentMediaAttachment($firstAttachment));
    }

    public function testPhaseThirtyOneContentMediaWorkspacePermissionsAndRendering(): void
    {
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Social Visual',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/social-visual.jpg',
            'alt_text' => 'Social visual alt',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Visual Social Post',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'draft_body' => 'Post copy.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'inline',
            'channel' => 'linkedin',
            'caption' => 'LinkedIn image caption',
            'created_by' => $this->userId,
        ]);

        $response = $this->runWebEndpoint('public/marketing_content_view.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['id' => $contentId],
        ]);
        $body = (string) ($response['body'] ?? '');
        $this->assertEndpointHealthy($response, 200, 'marketing_content_view.php media attachments');
        $this->assertStringContainsString('Attached Media', $body);
        $this->assertStringContainsString('social-visual.jpg', $body);
        $this->assertStringContainsString('LinkedIn image caption', $body);

        $otherWorkspaceId = $this->createWorkspace('Other Content Media Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMediaId = (new Marketing())->createMediaFile([
            'title' => 'Other Workspace Image',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/other-workspace.jpg',
            'created_by' => $this->userId,
        ]);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        try {
            $this->marketing->attachContentMedia($contentId, [
                'media_file_id' => $otherMediaId,
                'role' => 'featured',
            ]);
            $this->fail('Cross-workspace media should not attach to content.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->attachContentMedia($contentId, [
                'media_file_id' => $mediaId,
                'role' => 'thumbnail',
            ]);
            $this->fail('Viewer should not attach content media.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }
    }

    public function testPhaseThirtyOneDistributionBundlesInheritContentMedia(): void
    {
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Distribution Image',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/distribution-image.jpg',
            'alt_text' => 'Distribution image alt',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Distribution Media Source',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'draft_body' => 'Post copy with attached image.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'inline',
            'caption' => 'Distribution caption',
            'created_by' => $this->userId,
        ]);

        $postId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'linkedin',
            'created_by' => $this->userId,
        ]);
        $bundle = $this->marketing->getDistributionExportBundle($postId);
        $this->assertSame('Distribution Image', (string) ($bundle['media_attachments'][0]['title'] ?? ''));
        $this->assertSame('Distribution caption', (string) ($bundle['media_attachments'][0]['caption'] ?? ''));

        $channelBundleId = $this->marketing->createChannelExportBundle($postId, $this->userId);
        $channelBundle = $this->marketing->getChannelExportBundle($channelBundleId);
        $this->assertSame('Distribution Image', (string) ($channelBundle['export_payload_json']['media_attachments'][0]['title'] ?? ''));
    }

    public function testPhaseThirtyTwoAiCreativeActionsStoreRunsWithoutOverwritingRecords(): void
    {
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Creative Source Image',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/creative-source.jpg',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Creative Content',
            'content_type' => 'social_post',
            'channel' => 'instagram',
            'draft_body' => 'Original manual draft.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'inline',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Creative Landing',
            'headline' => 'Original landing headline',
            'body_sections' => 'Section body',
            'cta_blocks' => 'CTA body',
            'created_by' => $this->userId,
        ]);

        foreach (Marketing::AI_CREATIVE_ACTIONS as $action) {
            $run = $this->marketing->runAiCreativeAction($action, [
                'content_item_id' => $contentId,
                'landing_page_id' => $landingPageId,
                'media_file_id' => $mediaId,
                'created_by' => $this->userId,
            ]);
            $this->assertSame($action, (string) $run['action']);
            $this->assertSame('completed', (string) $run['status']);
            $this->assertTrue((bool) ($run['result']['manual_first'] ?? false));
            $this->assertFalse((bool) ($run['result']['records_changed'] ?? true));
            $this->assertFalse((bool) ($run['provider']['external_generation_called'] ?? true));
        }

        $runs = $this->marketing->listAiCreativeRuns(['content_item_id' => $contentId], 20, 0);
        $this->assertCount(count(Marketing::AI_CREATIVE_ACTIONS), $runs);
        $this->assertSame('Original manual draft.', (string) ($this->marketing->getContentItem($contentId)['draft_body'] ?? ''));
        $this->assertSame('Original landing headline', (string) ($this->marketing->getLandingPage($landingPageId)['headline'] ?? ''));
        $this->assertSame('', (string) ($this->marketing->getMediaFile($mediaId)['alt_text'] ?? ''));
    }

    public function testPhaseThirtyTwoAiCreativeFallbackAndWorkspaceValidation(): void
    {
        $ai = new class extends AIService {
            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                throw new \RuntimeException('Creative provider unavailable.');
            }
        };
        $marketing = new Marketing(null, $ai);
        $contentId = $marketing->createContentItem([
            'title' => 'Fallback Creative Content',
            'content_type' => 'ad_copy',
            'channel' => 'ads',
            'created_by' => $this->userId,
        ]);

        $run = $marketing->runAiCreativeAction('image_prompt', [
            'content_item_id' => $contentId,
            'created_by' => $this->userId,
        ]);
        $this->assertSame('completed', (string) $run['status']);
        $this->assertTrue((bool) ($run['provider']['fallback_used'] ?? false));
        $this->assertStringContainsString('Creative provider unavailable', (string) ($run['provider']['message'] ?? ''));
        $this->assertStringContainsString('Create a brand-safe marketing image', (string) ($run['result']['prompt'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Other AI Creative Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMediaId = (new Marketing())->createMediaFile([
            'title' => 'Foreign Creative Media',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/foreign-creative.jpg',
            'created_by' => $this->userId,
        ]);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        try {
            $this->marketing->runAiCreativeAction('alt_caption', [
                'media_file_id' => $otherMediaId,
                'created_by' => $this->userId,
            ]);
            $this->fail('Cross-workspace media should not be available to AI creative tools.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Marketing media file not found', $e->getMessage());
        }
    }

    public function testPhaseThirtyTwoAiCreativePagesAndDiagnosticsRender(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Creative Page Content',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Creative Page Landing',
            'headline' => 'Creative page headline',
            'body_sections' => 'Body',
            'cta_blocks' => 'CTA',
            'created_by' => $this->userId,
        ]);
        $this->marketing->runAiCreativeAction('image_prompt', [
            'content_item_id' => $contentId,
            'created_by' => $this->userId,
        ]);

        foreach ([
            ['public/marketing_content_view.php', ['id' => $contentId], 'AI Creative Runs'],
            ['public/marketing_landing_page_view.php', ['id' => $landingPageId], 'AI Visual Direction'],
            ['public/marketing_assets.php', [], 'Recent AI Creative Runs'],
            ['public/marketing_creative.php', [], 'AI Creative Tool'],
            ['public/marketing_admin.php', [], 'Creative Prompt Registry'],
        ] as [$endpoint, $query, $needle]) {
            $response = $this->runWebEndpoint($endpoint, $this->webSession('owner'), ['method' => 'GET', 'query' => $query]);
            $this->assertEndpointHealthy($response, 200, $endpoint . ' AI creative');
            $this->assertStringContainsString($needle, (string) ($response['body'] ?? ''));
        }

        $diagnostics = $this->marketing->getMarketingDiagnostics();
        $this->assertSame('ready', (string) ($diagnostics['ai_readiness']['creative_status'] ?? ''));
        $this->assertContains('creative_media_readiness', (array) ($diagnostics['ai_readiness']['active_creative_prompt_keys'] ?? []));
    }

    public function testPhaseSeventyOneAiCreativeWorkspaceSummarizesDraftSideTools(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Creative Workspace Social',
            'content_type' => 'social_post',
            'channel' => 'instagram',
            'draft_body' => 'Manual copy stays unchanged.',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Creative Workspace Landing',
            'headline' => 'A landing page that still needs visuals',
            'body_sections' => 'Body copy',
            'cta_blocks' => 'Book now',
            'created_by' => $this->userId,
        ]);
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Creative Workspace Image',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/workspace-image.jpg',
            'created_by' => $this->userId,
        ]);

        $landingRun = $this->marketing->runAiCreativeAction('landing_visual_direction', [
            'landing_page_id' => $landingPageId,
            'created_by' => $this->userId,
        ]);
        $captionRun = $this->marketing->runAiCreativeAction('alt_caption', [
            'media_file_id' => $mediaId,
            'created_by' => $this->userId,
        ]);

        $summary = $this->marketing->getAiCreativeWorkspaceSummary($this->userId);
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['content_needing_media'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['landing_pages_needing_media'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['media_needing_accessibility'] ?? 0));
        $this->assertGreaterThanOrEqual(2, (int) ($summary['counts']['recent_ai_runs'] ?? 0));
        $this->assertTrue((bool) ($summary['guardrails']['manual_first'] ?? false));
        $this->assertFalse((bool) ($summary['guardrails']['external_generation_called'] ?? true));
        $this->assertContains('landing_visual_direction', array_column((array) ($summary['recommendations'] ?? []), 'action'));
        $this->assertContains('alt_caption', array_column((array) ($summary['recommendations'] ?? []), 'action'));
        $this->assertSame('landing_visual_direction', (string) $landingRun['action']);
        $this->assertSame('alt_caption', (string) $captionRun['action']);
        $this->assertSame('Manual copy stays unchanged.', (string) ($this->marketing->getContentItem($contentId)['draft_body'] ?? ''));
        $this->assertSame('', (string) ($this->marketing->getMediaFile($mediaId)['alt_text'] ?? ''));

        $response = $this->runWebEndpoint('public/marketing_creative.php', $this->webSession('owner'));
        $this->assertEndpointHealthy($response, 200, 'marketing_creative.php phase 71');
        $body = (string) ($response['body'] ?? '');
        $this->assertStringContainsString('AI Creative Workspace', $body);
        $this->assertStringContainsString('Landing Visual Direction', $body);
        $this->assertStringContainsString('Alt Text And Caption', $body);
        $this->assertStringContainsString('Manual-first guardrail', $body);
        $this->assertStringContainsString('Saved recommendation preview', $body);
    }

    public function testPhaseEightyEightCreativeProductionPipelinePrioritizesOverdueAndAccessibilityWork(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 88 Creative Pipeline Content',
            'content_type' => 'social_post',
            'channel' => 'instagram',
            'draft_body' => 'Manual creative copy remains untouched.',
            'created_by' => $this->userId,
        ]);
        $briefId = $this->marketing->createCreativeBrief([
            'title' => 'Phase 88 Creative Brief',
            'content_item_id' => $contentId,
            'asset_type' => 'image',
            'channel' => 'instagram',
            'objective' => 'Create launch visuals.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createAssetRequest([
            'title' => 'Phase 88 Overdue Image Request',
            'creative_brief_id' => $briefId,
            'content_item_id' => $contentId,
            'requested_asset_type' => 'image',
            'channel' => 'instagram',
            'due_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'created_by' => $this->userId,
        ]);
        $readyRequestId = $this->marketing->createAssetRequest([
            'title' => 'Phase 88 Ready Thumbnail',
            'creative_brief_id' => $briefId,
            'content_item_id' => $contentId,
            'requested_asset_type' => 'image',
            'channel' => 'youtube',
            'created_by' => $this->userId,
        ]);
        $this->assertTrue($this->marketing->updateAssetRequestStatus($readyRequestId, 'approved'));
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Phase 88 Image Missing Alt',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/phase-88-image.jpg',
            'approval_status' => 'approved',
            'license_status' => 'owned',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createMediaFile([
            'title' => 'Phase 88 Restricted Video',
            'media_type' => 'video',
            'source_type' => 'url',
            'source_url' => 'https://example.com/phase-88-video.mp4',
            'approval_status' => 'blocked',
            'license_status' => 'restricted',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createLandingPage([
            'title' => 'Phase 88 Landing Needs Visuals',
            'headline' => 'Visual direction is still needed',
            'body_sections' => 'Body copy',
            'cta_blocks' => 'Book a demo',
            'created_by' => $this->userId,
        ]);

        $summary = $this->marketing->getAiCreativeWorkspaceSummary($this->userId);

        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['overdue_asset_requests'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['approved_asset_requests'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['approved_media'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['blocked_media'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['media_needing_accessibility'] ?? 0));
        $this->assertContains('Production Due', array_column((array) ($summary['pipeline_stages'] ?? []), 'label'));
        $this->assertContains('Accessibility', array_column((array) ($summary['pipeline_stages'] ?? []), 'label'));
        $this->assertContains('Export Ready', array_column((array) ($summary['pipeline_stages'] ?? []), 'label'));
        $this->assertContains('overdue_asset_request', array_column((array) ($summary['creative_queue'] ?? []), 'type'));
        $this->assertContains('blocked_media', array_column((array) ($summary['creative_queue'] ?? []), 'type'));
        $this->assertContains('media_accessibility_gap', array_column((array) ($summary['creative_queue'] ?? []), 'type'));
        $this->assertFalse((bool) ($summary['guardrails']['external_generation_called'] ?? true));
        $this->assertFalse((bool) ($summary['guardrails']['records_overwritten'] ?? true));
        $this->assertSame('', (string) ($this->marketing->getMediaFile($mediaId)['alt_text'] ?? ''));
        $this->assertSame('Manual creative copy remains untouched.', (string) ($this->marketing->getContentItem($contentId)['draft_body'] ?? ''));

        $response = $this->runWebEndpoint('public/marketing_creative.php', $this->webSession('owner'));
        $this->assertEndpointHealthy($response, 200, 'marketing_creative.php phase 88');
        $body = (string) ($response['body'] ?? '');
        $this->assertStringContainsString('Creative Production Pipeline', $body);
        $this->assertStringContainsString('Production Due', $body);
        $this->assertStringContainsString('Creative Attention Queue', $body);
        $this->assertStringContainsString('Manual-first guardrail', $body);
    }

    public function testPhaseEightyNineReusableTemplatesFeedBrandAndAiContext(): void
    {
        $brandId = $this->marketing->createBrandProfile([
            'name' => 'Phase 89 Brand',
            'voice' => 'direct',
            'tone' => 'confident',
            'cta_defaults' => 'Book a walkthrough',
            'is_default' => 1,
            'created_by' => $this->userId,
        ]);
        $personaId = $this->marketing->createPersona([
            'name' => 'Phase 89 Operator',
            'segment' => 'operations leader',
            'created_by' => $this->userId,
        ]);
        $offerId = $this->marketing->createContextItem([
            'item_type' => 'offer',
            'title' => 'Phase 89 Launch Offer',
            'body' => 'A reusable launch offer.',
            'created_by' => $this->userId,
        ]);
        $templateId = $this->marketing->createReusableTemplate([
            'name' => 'Phase 89 Social Launch Template',
            'template_type' => 'social',
            'channel' => 'instagram',
            'content_type' => 'social_post',
            'title_pattern' => '{campaign} launch social post',
            'objective' => 'Announce the offer with proof and one CTA.',
            'body_template' => "Hook\nProblem\nProof\nCTA",
            'cta_template' => 'Book a walkthrough',
            'checklist' => "Brand tone checked\nCTA included\nMedia attached",
            'brand_profile_id' => $brandId,
            'persona_id' => $personaId,
            'offer_context_item_id' => $offerId,
            'created_by' => $this->userId,
        ]);

        $template = $this->marketing->getReusableTemplate($templateId);
        $this->assertSame('Phase 89 Social Launch Template', (string) ($template['name'] ?? ''));
        $this->assertSame(['Brand tone checked', 'CTA included', 'Media attached'], (array) ($template['checklist_json'] ?? []));
        $this->assertSame('Phase 89 Brand', (string) ($template['brand_profile_name'] ?? ''));
        $this->assertSame('Phase 89 Operator', (string) ($template['persona_name'] ?? ''));

        $recommendations = $this->marketing->recommendReusableTemplatesForDraftContext([
            'content_type' => 'social_post',
            'channel' => 'instagram',
            'brand_profile_id' => $brandId,
            'persona_id' => $personaId,
        ]);
        $this->assertSame($templateId, (int) ($recommendations[0]['id'] ?? 0));

        $draft = $this->marketing->generateDraft([
            'title' => 'Phase 89 Launch Draft',
            'content_type' => 'social_post',
            'channel' => 'instagram',
            'brand_profile_id' => $brandId,
            'persona_id' => $personaId,
            'objective' => 'drive launch calls',
        ]);
        $draftTemplates = (array) ($draft['ai_context']['prompt_inputs']['template_suggestions'] ?? []);
        $this->assertSame('Phase 89 Social Launch Template', (string) ($draftTemplates[0]['name'] ?? ''));
        $this->assertSame('Phase 89 Social Launch Template', (string) ($draft['ai_context']['context_bundle']['blocks']['linked_records']['reusable_templates'][0]['name'] ?? ''));

        $summary = $this->marketing->getMarketingTemplateSystemSummary();
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['templates'] ?? 0));
        $this->assertSame('Phase 89 Brand', (string) ($summary['default_brand']['name'] ?? ''));
        $this->assertTrue((bool) ($summary['guardrails']['manual_first'] ?? false));
        $this->assertFalse((bool) ($summary['guardrails']['external_send'] ?? true));

        $options = $this->marketing->optionData();
        $this->assertContains('Phase 89 Social Launch Template', array_column((array) ($options['reusable_templates'] ?? []), 'name'));

        $otherWorkspaceId = $this->createWorkspace('Phase 89 Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getReusableTemplate($templateId));
        $this->assertSame([], $otherMarketing->recommendReusableTemplatesForDraftContext(['content_type' => 'social_post', 'channel' => 'instagram']));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        $response = $this->runWebEndpoint('public/marketing_brand.php', $this->webSession('owner'));
        $this->assertEndpointHealthy($response, 200, 'marketing_brand.php phase 89');
        $body = (string) ($response['body'] ?? '');
        $this->assertStringContainsString('Template And Brand System', $body);
        $this->assertStringContainsString('Add Reusable Template', $body);
        $this->assertStringContainsString('Manual-first guardrail', $body);
    }

    public function testPhaseNinetyAudienceActivationQualitySurfacesConsentDriftAndFitGaps(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, lead_source, stage, engagement_score, created_by)
             VALUES (?, UUID(), 'Phase90', 'phase90-a@example.com', 'webinar', 'lead', 82, ?)",
            [$this->workspaceId, $this->userId]
        );
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Phase 90 Webinar Audience',
            'status' => 'active',
            'source_scope' => 'contacts',
            'rules' => [[
                'source_type' => 'contact',
                'field_key' => 'lead_source',
                'operator' => 'equals',
                'value_text' => 'webinar',
            ]],
            'created_by' => $this->userId,
        ]);
        $this->marketing->getAudienceSegmentIntelligence($segmentId, true);
        $this->marketing->createAudienceSegmentSnapshot($segmentId, $this->userId);

        for ($i = 0; $i < 6; $i++) {
            Database::execute(
                "INSERT INTO contacts (workspace_id, uuid, first_name, email, lead_source, stage, engagement_score, created_by)
                 VALUES (?, UUID(), ?, ?, 'webinar', 'lead', 78, ?)",
                [$this->workspaceId, 'Phase90-' . $i, 'phase90-extra-' . $i . '@example.com', $this->userId]
            );
        }
        $this->marketing->createAudienceSegmentSnapshot($segmentId, $this->userId);
        Database::execute(
            'UPDATE marketing_segment_snapshots SET contact_count = 12 WHERE workspace_id = ? AND segment_id = ? ORDER BY id DESC LIMIT 1',
            [$this->workspaceId, $segmentId]
        );
        $this->marketing->createConsentPolicy([
            'policy_name' => 'Phase 90 Email Consent',
            'channel' => 'email',
            'consent_basis' => 'explicit_opt_in',
            'requires_unsubscribe' => 1,
            'requires_suppression_check' => 1,
            'created_by' => $this->userId,
        ]);
        $this->marketing->createSuppressionEntry([
            'channel' => 'email',
            'identifier' => 'phase90-blocked@example.com',
            'reason' => 'Manual suppression review required.',
            'created_by' => $this->userId,
        ]);

        $summary = $this->marketing->getAudienceActivationQualitySummary();

        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['segments'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['activation_gaps'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['suppression_entries'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['suppression_warnings'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['snapshot_drift'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['persona_offer_gaps'] ?? 0));
        $this->assertContains('snapshot_drift_detected', (array) ($summary['segments'][0]['warnings'] ?? []));
        $this->assertContains('activation_missing', array_merge(...array_map(static fn(array $item): array => (array) ($item['warnings'] ?? []), (array) ($summary['quality_queue'] ?? []))));
        $this->assertFalse((bool) ($summary['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($summary['guardrails']['external_publish'] ?? true));

        $otherWorkspaceId = $this->createWorkspace('Phase 90 Audience Quality Isolation');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherSummary = (new Marketing())->getAudienceActivationQualitySummary();
        $this->assertSame(0, (int) ($otherSummary['counts']['segments'] ?? 0));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        $response = $this->runWebEndpoint('public/marketing_segments.php', $this->webSession('owner'));
        $this->assertEndpointHealthy($response, 200, 'marketing_segments.php phase 90');
        $body = (string) ($response['body'] ?? '');
        $this->assertStringContainsString('Audience Activation Quality', $body);
        $this->assertStringContainsString('Quality Queue', $body);
        $this->assertStringContainsString('Manual-first', $body);
    }

    public function testPhaseSeventyTwoMediaPlacementGuidesRenderEditorSignals(): void
    {
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Placement Guide Hero',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/placement-guide-hero.jpg',
            'approval_status' => 'pending',
            'license_status' => 'unknown',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Placement Guide Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Placement Guide Landing',
            'headline' => 'Needs visual placement',
            'body_sections' => 'Body',
            'cta_blocks' => 'CTA',
            'created_by' => $this->userId,
        ]);

        $options = $this->marketing->optionData();
        $mediaOption = null;
        foreach ((array) ($options['media_files'] ?? []) as $option) {
            if ((int) ($option['id'] ?? 0) === $mediaId) {
                $mediaOption = $option;
                break;
            }
        }
        $this->assertIsArray($mediaOption);
        $this->assertArrayHasKey('alt_text', $mediaOption);
        $this->assertArrayHasKey('approval_status', $mediaOption);

        $contentResponse = $this->runWebEndpoint('public/marketing_content_edit.php', $this->webSession('owner'), ['query' => ['id' => $contentId]]);
        $this->assertEndpointHealthy($contentResponse, 200, 'marketing_content_edit.php phase 72');
        $contentBody = (string) ($contentResponse['body'] ?? '');
        $this->assertStringContainsString('Media Placement Guide', $contentBody);
        $this->assertStringContainsString('Placement Guide Hero', $contentBody);
        $this->assertStringContainsString('Alt text missing', $contentBody);
        $this->assertStringContainsString('featured | ' . $mediaId, $contentBody);
        $this->assertStringContainsString('data-media-attach-line', $contentBody);
        $this->assertStringContainsString('Add as Featured', $contentBody);

        $landingResponse = $this->runWebEndpoint('public/marketing_landing_page_edit.php', $this->webSession('owner'), ['query' => ['id' => $landingPageId]]);
        $this->assertEndpointHealthy($landingResponse, 200, 'marketing_landing_page_edit.php phase 72');
        $landingBody = (string) ($landingResponse['body'] ?? '');
        $this->assertStringContainsString('Landing Media Placement Guide', $landingBody);
        $this->assertStringContainsString('Placement Guide Hero', $landingBody);
        $this->assertStringContainsString('Suggest Visuals', $landingBody);
        $this->assertStringContainsString('Section heading | ' . $mediaId, $landingBody);
        $this->assertStringContainsString('data-media-select-target="hero_media_file_id"', $landingBody);
        $this->assertStringContainsString('data-media-section-line', $landingBody);
        $this->assertStringContainsString('data-media-gallery-id="' . $mediaId . '"', $landingBody);
    }

    public function testPhaseNinetyFiveMediaPlacementGuidanceBuildsAiReadyContext(): void
    {
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'AI Ready Placement Hero',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/ai-ready-placement-hero.jpg',
            'alt_text' => 'Operator reviewing campaign visuals',
            'caption' => 'Campaign visual planning',
            'approval_status' => 'approved',
            'license_status' => 'owned',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'AI Ready Placement Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'objective' => 'Explain campaign visual planning',
            'target_audience' => 'Marketing operators',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'AI Ready Placement Landing',
            'headline' => 'Plan visual campaign assets',
            'conversion_goal' => 'lead_capture',
            'created_by' => $this->userId,
        ]);

        $contentGuidance = $this->marketing->getContentMediaPlacementGuidance([
            'content_item_id' => $contentId,
            'content_type' => 'blog_post',
            'channel' => 'blog',
        ]);
        $this->assertContains('featured', (array) ($contentGuidance['recommended_roles'] ?? []));
        $this->assertContains('featured_image_recommended', (array) ($contentGuidance['warnings'] ?? []));
        $this->assertGreaterThanOrEqual(1, (int) ($contentGuidance['context']['approved_media_count'] ?? 0));
        $this->assertSame(true, (bool) ($contentGuidance['context']['manual_first'] ?? false));

        $contentWithMedia = $this->marketing->getContentMediaPlacementGuidance([
            'content_item_id' => $contentId,
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'media_attachments' => 'featured | ' . $mediaId . ' | Campaign visual planning | Operator reviewing campaign visuals | Above intro | 16:9 | blog',
        ]);
        $this->assertSame(1, (int) ($contentWithMedia['context']['attachment_count'] ?? 0));
        $this->assertNotContains('featured_image_recommended', (array) ($contentWithMedia['warnings'] ?? []));

        $landingGuidance = $this->marketing->getLandingPageMediaPlacementGuidance([
            'title' => 'AI Ready Placement Landing',
            'headline' => 'Plan visual campaign assets',
            'conversion_goal' => 'lead_capture',
            'hero_media_file_id' => $mediaId,
        ]);
        $this->assertSame(1, (int) ($landingGuidance['context']['selected_media_count'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($landingGuidance['context']['approved_media_count'] ?? 0));
        $this->assertSame(true, (bool) ($landingGuidance['context']['manual_first'] ?? false));
        $this->assertArrayHasKey('social_preview_media_file_id', (array) ($landingGuidance['recommended_slots'] ?? []));

        $contentResponse = $this->runWebEndpoint('public/marketing_content_edit.php', $this->webSession('owner'), ['query' => ['id' => $contentId]]);
        $this->assertEndpointHealthy($contentResponse, 200, 'marketing_content_edit.php phase 95');
        $this->assertStringContainsString('AI-ready media placement guidance', (string) ($contentResponse['body'] ?? ''));

        $landingResponse = $this->runWebEndpoint('public/marketing_landing_page_edit.php', $this->webSession('owner'), ['query' => ['id' => $landingPageId]]);
        $this->assertEndpointHealthy($landingResponse, 200, 'marketing_landing_page_edit.php phase 95');
        $this->assertStringContainsString('AI-ready landing visual guidance', (string) ($landingResponse['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Phase 95 Media Placement Isolation');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        try {
            $otherMarketing->getContentMediaPlacementGuidance(['content_item_id' => $contentId]);
            $this->fail('Cross-workspace content should not produce media placement guidance.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseNinetySixEditorOptionDataIsScopedForPageSpeed(): void
    {
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Scoped Option Media',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/scoped-option-media.jpg',
            'approval_status' => 'approved',
            'license_status' => 'owned',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Scoped Option Content',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Scoped Option Landing',
            'headline' => 'A faster page editor',
            'created_by' => $this->userId,
        ]);

        $contentOptions = $this->marketing->contentEditorOptionData();
        foreach (['campaigns', 'campaign_briefs', 'brand_profiles', 'personas', 'seo_topics', 'landing_pages', 'forms', 'email_templates', 'tasks', 'users', 'media_files'] as $key) {
            $this->assertArrayHasKey($key, $contentOptions);
        }
        $this->assertArrayNotHasKey('guided_workflows', $contentOptions);
        $this->assertArrayNotHasKey('launch_control_records', $contentOptions);
        $this->assertContains($mediaId, array_map(static fn(array $media): int => (int) ($media['id'] ?? 0), (array) ($contentOptions['media_files'] ?? [])));

        $landingOptions = $this->marketing->landingPageEditorOptionData();
        foreach (['campaigns', 'audience_segments', 'conversion_goals', 'forms', 'media_files'] as $key) {
            $this->assertArrayHasKey($key, $landingOptions);
        }
        $this->assertArrayNotHasKey('email_templates', $landingOptions);
        $this->assertArrayNotHasKey('tasks', $landingOptions);

        $contentResponse = $this->runWebEndpoint('public/marketing_content_edit.php', $this->webSession('owner'), ['query' => ['id' => $contentId]]);
        $this->assertEndpointHealthy($contentResponse, 200, 'marketing_content_edit.php phase 96');
        $this->assertStringContainsString('Scoped Option Media', (string) ($contentResponse['body'] ?? ''));

        $landingResponse = $this->runWebEndpoint('public/marketing_landing_page_edit.php', $this->webSession('owner'), ['query' => ['id' => $landingPageId]]);
        $this->assertEndpointHealthy($landingResponse, 200, 'marketing_landing_page_edit.php phase 96');
        $this->assertStringContainsString('Scoped Option Media', (string) ($landingResponse['body'] ?? ''));
    }

    public function testPhaseSeventyFourContentProductionBoardSurfacesMediaAndWorkflowActions(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Board Media Warning Content',
            'content_type' => 'social_post',
            'channel' => 'instagram',
            'status' => 'draft',
            'production_stage' => 'drafting',
            'production_due_at' => date('Y-m-d\TH:i', strtotime('+2 days')),
            'created_by' => $this->userId,
        ]);
        $hiddenContentId = $this->marketing->createContentItem([
            'title' => 'Board Ready Content',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Ready Email Banner',
            'media_type' => 'banner',
            'source_type' => 'url',
            'source_url' => 'https://example.com/banner.jpg',
            'alt_text' => 'Email banner',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($hiddenContentId, [
            'media_file_id' => $mediaId,
            'role' => 'banner',
            'created_by' => $this->userId,
        ]);

        $page = $this->runWebEndpoint('public/marketing_content.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['view_mode' => 'board', 'media_warning' => '1'],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_content.php phase 74');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Production Board Snapshot', $body);
        $this->assertStringContainsString('Media Warnings', $body);
        $this->assertStringContainsString('Board Media Warning Content', $body);
        $this->assertStringNotContainsString('Board Ready Content', $body);
        $this->assertStringContainsString('Attach Media', $body);
        $this->assertStringContainsString('AI Visual Help', $body);
        $this->assertStringContainsString('Build Distribution', $body);
        $this->assertStringContainsString('Launch Checklist', $body);
        $this->assertStringContainsString('marketing_content_edit.php?id=' . $contentId, $body);
    }

    public function testPhaseSeventySixContentStudioUsesBoundedPagination(): void
    {
        for ($index = 1; $index <= 12; $index++) {
            $this->marketing->createContentItem([
                'title' => sprintf('Speed Guard Content %02d', $index),
                'content_type' => 'email',
                'channel' => 'email',
                'status' => 'draft',
                'production_due_at' => date('Y-m-d\TH:i', strtotime('+' . $index . ' days')),
                'created_by' => $this->userId,
            ]);
        }

        $pageOne = $this->runWebEndpoint('public/marketing_content.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['status' => 'draft', 'per_page' => 10],
        ]);
        $this->assertEndpointHealthy($pageOne, 200, 'marketing_content.php pagination page one');
        $pageOneBody = (string) ($pageOne['body'] ?? '');
        $this->assertStringContainsString('Load guard active', $pageOneBody);
        $this->assertStringContainsString('showing 1-10 records', strtolower($pageOneBody));
        $this->assertStringContainsString('10 per page', $pageOneBody);
        $this->assertStringContainsString('Speed Guard Content 01', $pageOneBody);
        $this->assertStringContainsString('Speed Guard Content 10', $pageOneBody);
        $this->assertStringNotContainsString('Speed Guard Content 11', $pageOneBody);
        $this->assertStringContainsString('Next', $pageOneBody);

        $pageTwo = $this->runWebEndpoint('public/marketing_content.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['status' => 'draft', 'per_page' => 10, 'page' => 2],
        ]);
        $this->assertEndpointHealthy($pageTwo, 200, 'marketing_content.php pagination page two');
        $pageTwoBody = (string) ($pageTwo['body'] ?? '');
        $this->assertStringContainsString('Showing 11-12 on page 2', $pageTwoBody);
        $this->assertStringContainsString('Speed Guard Content 11', $pageTwoBody);
        $this->assertStringContainsString('Speed Guard Content 12', $pageTwoBody);
        $this->assertStringNotContainsString('Speed Guard Content 01', $pageTwoBody);
        $this->assertStringContainsString('Previous', $pageTwoBody);
    }

    public function testPhaseThirtyThreeChannelMediaKitCrudAndExportGeneration(): void
    {
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Instagram Launch Visual',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/instagram-launch.jpg',
            'alt_text' => 'Launch product visual',
            'caption' => 'Launch caption',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Instagram Launch Content',
            'content_type' => 'social_post',
            'channel' => 'instagram',
            'draft_body' => 'Launch copy for Instagram.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'inline',
            'caption' => 'Attached launch caption',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Instagram Launch Landing',
            'headline' => 'See the launch',
            'hero_media_file_id' => $mediaId,
            'created_by' => $this->userId,
        ]);
        $utmId = $this->marketing->createUtmLink([
            'url' => 'https://example.com/launch',
            'content_item_id' => $contentId,
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'launch',
            'created_by' => $this->userId,
        ]);
        $postId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'instagram',
            'planned_copy' => 'Launch copy for Instagram.',
            'created_by' => $this->userId,
        ]);

        $kitId = $this->marketing->createChannelMediaKitFromDistribution($postId, [
            'landing_page_id' => $landingPageId,
            'utm_link_id' => $utmId,
            'primary_media_file_id' => $mediaId,
            'created_by' => $this->userId,
        ], $this->userId);
        $kit = $this->marketing->getChannelMediaKit($kitId);

        $this->assertSame('ready', (string) ($kit['status'] ?? ''));
        $this->assertTrue((bool) ($kit['readiness_json']['ready'] ?? false));
        $this->assertSame('1080x1350', (string) ($kit['required_dimensions_json']['feed_portrait'] ?? ''));
        $this->assertSame('https://example.com/instagram-launch.jpg', (string) ($kit['primary_media_display_url'] ?? ''));

        $payload = $this->marketing->generateChannelMediaKitExport($kitId);
        $this->assertTrue((bool) ($payload['no_external_publish'] ?? false));
        $this->assertSame('Instagram Launch Visual', (string) ($payload['media']['primary']['title'] ?? ''));
        $this->assertStringContainsString('utm_source=instagram', (string) ($payload['links']['utm_url'] ?? ''));

        $bundleId = $this->marketing->createChannelExportBundle($postId, $this->userId);
        $bundle = $this->marketing->getChannelExportBundle($bundleId);
        $this->assertGreaterThan(0, (int) ($bundle['media_kit_id'] ?? 0));
        $this->assertSame('Instagram Launch Visual', (string) ($bundle['export_payload_json']['media_attachments'][0]['title'] ?? ''));
        $this->assertSame('ready', (string) ($bundle['export_payload_json']['media_kit']['status'] ?? ''));
    }

    public function testPhaseThirtyThreeRequiredMediaValidationAndWorkspaceIsolation(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'YouTube Without Thumbnail',
            'content_type' => 'video_script',
            'channel' => 'youtube',
            'draft_body' => 'Video script copy.',
            'created_by' => $this->userId,
        ]);
        $postId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'youtube',
            'planned_copy' => 'Video script copy.',
            'created_by' => $this->userId,
        ]);
        $kitId = $this->marketing->createChannelMediaKitFromDistribution($postId, [
            'destination_url' => 'https://example.com/governed-instagram',
        ], $this->userId);
        $kit = $this->marketing->getChannelMediaKit($kitId);
        $this->assertSame('draft', (string) ($kit['status'] ?? ''));
        $this->assertFalse((bool) ($kit['readiness_json']['ready'] ?? true));
        $this->assertContains('youtube_thumbnail', (array) ($kit['readiness_json']['missing'] ?? []));

        try {
            $this->marketing->markChannelMediaKitExported($kitId);
            $this->fail('Incomplete media kits should not be marked exported.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not export-ready', $e->getMessage());
        }

        $otherWorkspaceId = $this->createWorkspace('Other Channel Media Kit Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMediaId = (new Marketing())->createMediaFile([
            'title' => 'Foreign Kit Media',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/foreign-kit.jpg',
            'created_by' => $this->userId,
        ]);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        try {
            $this->marketing->createChannelMediaKit([
                'title' => 'Bad Cross Workspace Kit',
                'channel' => 'instagram',
                'content_item_id' => $contentId,
                'primary_media_file_id' => $otherMediaId,
                'created_by' => $this->userId,
            ]);
            $this->fail('Cross-workspace media should not be accepted in media kits.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        }
    }

    public function testPhaseThirtyThreeChannelExportPageRendersMediaKits(): void
    {
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Email Banner Media',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/email-banner.jpg',
            'alt_text' => 'Email banner alt',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Email Media Kit Content',
            'content_type' => 'newsletter',
            'channel' => 'email',
            'draft_body' => 'Email body copy.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'banner',
            'created_by' => $this->userId,
        ]);
        $postId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'email',
            'planned_copy' => 'Email body copy.',
            'created_by' => $this->userId,
        ]);
        $kitId = $this->marketing->createChannelMediaKitFromDistribution($postId, [
            'destination_url' => 'https://example.com/governed-instagram',
        ], $this->userId);
        $this->assertGreaterThan(0, $kitId);

        $response = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $body = (string) ($response['body'] ?? '');

        $this->assertEndpointHealthy($response, 200, 'marketing_channel_exports.php media kits');
        $this->assertStringContainsString('Channel Media Kits', $body);
        $this->assertStringContainsString('Email Media Kit Content Email Media Kit', $body);
        $this->assertStringContainsString('Create Media Kit', $body);
        $this->assertStringContainsString('email-banner.jpg', $body);
    }

    public function testPhaseThirtyFourMediaGovernanceAuditAndExportBlocking(): void
    {
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Governed Instagram Visual',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/governed-instagram.jpg',
            'alt_text' => 'Governed visual alt',
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMediaGovernance($mediaId, [
            'approval_status' => 'approved',
            'license_status' => 'owned',
            'accessibility_status' => 'ready',
            'expiry_date' => date('Y-m-d', strtotime('+30 days')),
        ], $this->userId);
        $events = $this->marketing->listMediaAuditEvents(['media_file_id' => $mediaId], 20, 0);
        $this->assertContains('upload', array_column($events, 'event_type'));
        $this->assertContains('approve', array_column($events, 'event_type'));

        $contentId = $this->marketing->createContentItem([
            'title' => 'Governed Instagram Content',
            'content_type' => 'social_post',
            'channel' => 'instagram',
            'draft_body' => 'Approved visual copy.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'inline',
            'created_by' => $this->userId,
        ]);
        $postId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'instagram',
            'planned_copy' => 'Approved visual copy.',
            'created_by' => $this->userId,
        ]);
        $kitId = $this->marketing->createChannelMediaKitFromDistribution(
            $postId,
            ['destination_url' => 'https://example.com/governed-instagram'],
            $this->userId
        );
        $initialKit = $this->marketing->getChannelMediaKit($kitId);
        $this->assertTrue((bool) ($initialKit['readiness_json']['ready'] ?? false), json_encode($initialKit['readiness_json'] ?? []));

        $this->marketing->updateMediaGovernance($mediaId, [
            'approval_status' => 'blocked',
            'license_status' => 'restricted',
            'blocked_reason' => 'Rights need review',
        ], $this->userId);
        try {
            $this->marketing->markChannelMediaKitExported($kitId);
            $this->fail('Blocked media should not be export-ready.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not export-ready', $e->getMessage());
        }
        $blockedKit = $this->marketing->getChannelMediaKit($kitId);
        $this->assertContains('media_governance_blocked', (array) ($blockedKit['readiness_json']['missing'] ?? []));
        $this->assertContains('block', array_column($this->marketing->listMediaAuditEvents(['media_file_id' => $mediaId], 20, 0), 'event_type'));
    }

    public function testPhaseThirtyFourMediaDiagnosticsCleanupAndAdminRendering(): void
    {
        $orphanId = $this->marketing->createMediaFile([
            'title' => 'Expired Orphan Media',
            'media_type' => 'image',
            'source_type' => 'upload',
            'file_path' => 'uploads/marketing/media/' . $this->workspaceId . '/expired-orphan.jpg',
            'license_status' => 'expired',
            'expiry_date' => date('Y-m-d', strtotime('-1 day')),
            'created_by' => $this->userId,
        ]);
        $usedId = $this->marketing->createMediaFile([
            'title' => 'Used Accessible Media',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/used-accessible.jpg',
            'alt_text' => 'Used media alt',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Used Media Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $usedId,
            'role' => 'featured',
            'created_by' => $this->userId,
        ]);

        $diagnostics = $this->marketing->getMarketingMediaDiagnostics();
        $this->assertGreaterThanOrEqual(1, (int) ($diagnostics['counts']['missing_alt_text'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($diagnostics['counts']['expired_rights'] ?? 0));
        $this->assertContains($orphanId, array_map(static fn(array $row): int => (int) $row['id'], (array) ($diagnostics['orphaned_media'] ?? [])));

        $preview = $this->marketing->runMarketingMediaCleanup($this->userId, true);
        $this->assertTrue((bool) $preview['dry_run']);
        $this->assertContains($orphanId, array_map(static fn(array $row): int => (int) $row['id'], (array) ($preview['candidates'] ?? [])));
        $this->assertNotSame('archived', (string) ($this->marketing->getMediaFile($orphanId)['approval_status'] ?? ''));

        $cleanup = $this->marketing->runMarketingMediaCleanup($this->userId, false);
        $this->assertContains($orphanId, (array) ($cleanup['archived_ids'] ?? []));
        $this->assertSame('archived', (string) ($this->marketing->getMediaFile($orphanId)['approval_status'] ?? ''));
        $this->assertNotSame('archived', (string) ($this->marketing->getMediaFile($usedId)['approval_status'] ?? ''));

        $response = $this->runWebEndpoint('public/marketing_admin.php', $this->webSession('owner'), ['method' => 'GET']);
        $body = (string) ($response['body'] ?? '');
        $this->assertEndpointHealthy($response, 200, 'marketing_admin.php media governance');
        $this->assertStringContainsString('Media Governance QA', $body);
        $this->assertStringContainsString('Preview Media Cleanup', $body);
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
    }

    public function testPhaseThirtyFourMediaGovernanceRequiresManagePermission(): void
    {
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Permission Governed Media',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/permission-governed.jpg',
            'created_by' => $this->userId,
        ]);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->updateMediaGovernance($mediaId, ['approval_status' => 'approved'], $this->userId);
            $this->fail('Marketing role should not perform manage-only media governance.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        $this->assertTrue($this->marketing->updateMediaGovernance($mediaId, ['approval_status' => 'approved'], $this->userId));
    }

    public function testMarketingOnboardingStatusAndStarterPackAreIdempotent(): void
    {
        $emptyStatus = $this->marketing->getMarketingOnboardingStatus();
        $this->assertLessThan(100, (int) $emptyStatus['score']);
        $this->assertContains('brand_profile', $emptyStatus['missing']);

        $starter = $this->marketing->createMarketingStarterPack($this->userId);
        $this->assertTrue((bool) $starter['created']);
        $this->assertNotEmpty($starter['run_uuid']);
        $this->assertSame(3, (int) ($starter['created_counts']['content_items'] ?? 0));
        $this->assertSame(7, (int) ($starter['created_counts']['context_items'] ?? 0));

        $contentCount = $this->countStarterRows('marketing_content_items');
        $contextCount = $this->countStarterRows('marketing_context_items');
        $this->assertSame(3, $contentCount);
        $this->assertSame(7, $contextCount);

        $again = $this->marketing->createMarketingStarterPack($this->userId);
        $this->assertTrue((bool) ($again['reused'] ?? false));
        $this->assertSame($contentCount, $this->countStarterRows('marketing_content_items'));
        $this->assertSame($contextCount, $this->countStarterRows('marketing_context_items'));

        $status = $this->marketing->getMarketingOnboardingStatus();
        $this->assertGreaterThan((int) $emptyStatus['score'], (int) $status['score']);
        $this->assertTrue((bool) ($status['starter_pack']['created'] ?? false));

        $row = Database::queryOne(
            "SELECT metadata_json FROM marketing_content_items
             WHERE workspace_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source')) = 'marketing_onboarding_starter_pack'
             LIMIT 1",
            [$this->workspaceId]
        );
        $this->assertNotNull($row);
        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true) ?: [];
        $this->assertSame('marketing_onboarding_starter_pack', $metadata['source'] ?? null);
        $this->assertSame($starter['run_uuid'], $metadata['starter_pack_run_uuid'] ?? null);
    }

    public function testMarketingStarterPackIsWorkspaceScoped(): void
    {
        $this->marketing->createMarketingStarterPack($this->userId);
        $otherWorkspaceId = $this->createWorkspace('Other Starter Workspace');

        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $otherStatus = $otherMarketing->getMarketingOnboardingStatus();

        $this->assertFalse((bool) ($otherStatus['starter_pack']['created'] ?? false));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM marketing_content_items
             WHERE workspace_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source')) = 'marketing_onboarding_starter_pack'",
            [$otherWorkspaceId]
        )['count'] ?? 0));
    }

    public function testMarketingStarterPackPermissionsAndArchive(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $this->expectExceptionMessage('You do not have permission to perform this marketing action.');
        $this->marketing->createMarketingStarterPack($this->userId);
    }

    public function testMarketingRoleCanCreateStarterPackButCannotArchive(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $starter = $this->marketing->createMarketingStarterPack($this->userId);
        $this->assertTrue((bool) $starter['created']);

        try {
            $this->marketing->archiveMarketingStarterPack($this->userId);
            $this->fail('Marketing role should not archive starter packs.');
        } catch (\RuntimeException $e) {
            $this->assertSame('You do not have permission to perform this marketing action.', $e->getMessage());
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $archived = $this->marketing->archiveMarketingStarterPack($this->userId);
        $this->assertTrue((bool) ($archived['archived'] ?? false));

        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM marketing_content_items
             WHERE workspace_id = ? AND status <> 'archived'
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source')) = 'marketing_onboarding_starter_pack'",
            [$this->workspaceId]
        )['count'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM marketing_distribution_posts
             WHERE workspace_id = ? AND status <> 'cancelled'",
            [$this->workspaceId]
        )['count'] ?? 0));
    }

    public function testPhaseTwentySixStarterCleanupLifecyclePreservesRealDataAndHidesArchivedDemoContext(): void
    {
        $realBrandId = $this->marketing->createBrandProfile([
            'name' => 'Real Release Brand',
            'voice' => 'Direct and practical',
            'created_by' => $this->userId,
        ]);
        $realPersonaId = $this->marketing->createPersona([
            'name' => 'Real Release Persona',
            'segment' => 'Real operators',
            'created_by' => $this->userId,
        ]);
        $realContentId = $this->marketing->createContentItem([
            'title' => 'Real Release Content',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'draft',
            'brand_profile_id' => $realBrandId,
            'persona_id' => $realPersonaId,
            'created_by' => $this->userId,
        ]);

        $starter = $this->marketing->createMarketingStarterPack($this->userId);
        $this->assertTrue((bool) $starter['created']);
        $diagnosticsBefore = $this->marketing->getMarketingDiagnostics();
        $this->assertGreaterThan(0, (int) ($diagnosticsBefore['starter_pack']['total'] ?? 0));
        $this->assertContains('Starter/demo records are present; use cleanup when the workspace no longer needs demo data.', $diagnosticsBefore['recommendations']);

        $preview = $this->marketing->runMarketingCleanup('starter_pack', $this->userId, true);
        $this->assertTrue((bool) $preview['dry_run']);
        $this->assertFalse((bool) $preview['archived']);
        $this->assertGreaterThan(0, (int) $preview['cleanup_run_id']);
        $this->assertSame(3, $this->countActiveStarterContentRows($this->workspaceId));

        $cleanup = $this->marketing->runMarketingCleanup('starter_pack', $this->userId, false);
        $this->assertFalse((bool) $cleanup['dry_run']);
        $this->assertTrue((bool) $cleanup['archived']);
        $this->assertGreaterThan(0, (int) $cleanup['cleanup_run_id']);
        $this->assertSame(0, $this->countActiveStarterContentRows($this->workspaceId));
        $this->assertSame('draft', (string) $this->marketing->getContentItem($realContentId)['status']);

        $brandNames = array_column($this->marketing->listBrandProfiles(), 'name');
        $personaNames = array_column($this->marketing->listPersonas(), 'name');
        $this->assertContains('Real Release Brand', $brandNames);
        $this->assertContains('Real Release Persona', $personaNames);
        $this->assertNotContains('Starter Brand Voice', $brandNames);
        $this->assertNotContains('Operations-Led Growth Buyer', $personaNames);

        $options = $this->marketing->optionData();
        $this->assertNotContains('Starter Brand Voice', array_column($options['brand_profiles'], 'name'));
        $this->assertNotContains('Operations-Led Growth Buyer', array_column($options['personas'], 'name'));

        $archivedBrand = Database::queryOne(
            "SELECT metadata_json FROM marketing_brand_profiles
             WHERE workspace_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.starter_pack_run_uuid')) = ?
             LIMIT 1",
            [$this->workspaceId, $starter['run_uuid']]
        );
        $brandMetadata = json_decode((string) ($archivedBrand['metadata_json'] ?? '{}'), true) ?: [];
        $this->assertTrue((bool) ($brandMetadata['starter_pack_archived'] ?? false));
        $this->assertSame($starter['run_uuid'], $brandMetadata['starter_pack_run_uuid'] ?? null);

        $diagnosticsAfter = $this->marketing->getMarketingDiagnostics();
        $this->assertTrue((bool) ($diagnosticsAfter['starter_pack']['archived'] ?? false));
        $this->assertNotContains('Starter/demo records are present; use cleanup when the workspace no longer needs demo data.', $diagnosticsAfter['recommendations']);
    }

    public function testPhaseTwentySixStarterCleanupIsWorkspaceIsolated(): void
    {
        $this->marketing->createMarketingStarterPack($this->userId);
        $otherWorkspaceId = $this->createWorkspace('Cleanup Isolation Workspace');

        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $otherMarketing->createMarketingStarterPack($this->userId);
        $otherCleanup = $otherMarketing->runMarketingCleanup('starter_pack', $this->userId, false);
        $this->assertTrue((bool) $otherCleanup['archived']);
        $this->assertSame(0, $this->countActiveStarterContentRows($otherWorkspaceId));

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        $this->marketing = new Marketing();
        $this->assertSame(3, $this->countActiveStarterContentRows($this->workspaceId));
        $this->assertFalse((bool) ($this->marketing->getMarketingDiagnostics()['starter_pack']['archived'] ?? false));
    }

    public function testPhaseTwentySixDiagnosticsReportMissingIntegritySignalsWithoutSecrets(): void
    {
        Database::execute('ALTER TABLE marketing_content_items DROP INDEX idx_marketing_content_workspace_updated');
        Database::execute('DROP TABLE marketing_audit_events');

        $diagnostics = $this->marketing->getMarketingDiagnostics();
        $this->assertFalse((bool) ($diagnostics['tables']['marketing_audit_events']['exists'] ?? true));
        $this->assertFalse((bool) ($diagnostics['indexes']['idx_marketing_content_workspace_updated'] ?? true));
        $this->assertContains('Run pending migrations: missing marketing tables were detected.', $diagnostics['recommendations']);
        $this->assertContains('Review migration 317: one or more hardening indexes are missing.', $diagnostics['recommendations']);

        $encoded = json_encode($diagnostics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringNotContainsString('Stack trace', $encoded);
        $this->assertStringNotContainsString('DB_PASS', $encoded);
        $this->assertStringNotContainsString('password_hash', $encoded);
        $dbPass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : (string) ($_ENV['DB_PASS'] ?? '');
        if ($dbPass !== '') {
            $this->assertStringNotContainsString($dbPass, $encoded);
        }
    }

    public function testContentCrudAndDashboardCounts(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Spring Launch');
        $formId = $this->createForm($this->workspaceId, 'Demo Request');
        $taskId = $this->createTask($this->workspaceId, 'Review launch copy');

        $id = $this->marketing->createContentItem([
            'title' => 'Launch LinkedIn Post',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'status' => 'draft',
            'funnel_stage' => 'awareness',
            'objective' => 'Drive demo requests',
            'target_audience' => 'Operations leaders',
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'task_id' => $taskId,
            'scheduled_at' => date('Y-m-d\TH:i', strtotime('+2 days')),
            'draft_body' => 'Initial draft',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);

        $item = $this->marketing->getContentItem($id);
        $this->assertNotNull($item);
        $this->assertSame('Launch LinkedIn Post', $item['title']);
        $this->assertSame('Spring Launch', $item['campaign_name']);

        $this->marketing->updateContentItem($id, ['status' => 'review', 'draft_body' => 'Updated draft']);
        $updated = $this->marketing->getContentItem($id);
        $this->assertSame('review', $updated['status']);
        $this->assertSame('Updated draft', $updated['draft_body']);

        $summary = $this->marketing->getDashboardSummary();
        $this->assertSame(1, $summary['counts']['in_review']);
        $this->assertNotEmpty($summary['review_items']);

        $this->marketing->deleteContentItem($id);
        $this->assertNull($this->marketing->getContentItem($id));
    }

    public function testValidationRejectsBlankTitleAndInvalidEnums(): void
    {
        $this->expectExceptionMessage('Content title is required.');
        $this->marketing->createContentItem(['title' => '']);
    }

    public function testValidationRejectsInvalidStatus(): void
    {
        $this->expectExceptionMessage('Invalid status.');
        $this->marketing->createContentItem([
            'title' => 'Bad Status',
            'status' => 'queued',
        ]);
    }

    public function testWorkspaceIsolationAndLinkedRecordValidation(): void
    {
        $otherWorkspaceId = $this->createWorkspace('Other Marketing Workspace');
        $otherCampaignId = $this->createCampaign($otherWorkspaceId, 'Other Campaign');

        $id = $this->marketing->createContentItem([
            'title' => 'Workspace One Draft',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'draft',
        ]);

        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getContentItem($id));

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        $this->expectExceptionMessage('Linked record was not found in the active workspace.');
        $this->marketing->createContentItem([
            'title' => 'Bad Link',
            'campaign_id' => $otherCampaignId,
        ]);
    }

    public function testGenerateDraftFallsBackWhenAiRuntimeIsUnavailable(): void
    {
        $result = $this->marketing->generateDraft([
            'title' => 'Launch Offer',
            'content_type' => 'email',
            'channel' => 'email',
            'objective' => 'book a demo',
            'target_audience' => 'founders',
        ]);

        $this->assertNotEmpty($result['body']);
        $this->assertStringContainsString('Launch Offer', $result['body']);
        $this->assertArrayHasKey('ai_context', $result);
    }

    public function testPhaseTwoWorkflowTablesExist(): void
    {
        foreach ([
            'marketing_campaign_briefs',
            'marketing_content_versions',
            'marketing_content_comments',
            'marketing_content_approvals',
            'marketing_calendar_milestones',
        ] as $table) {
            $this->assertTrue(Database::tableExists($table), $table . ' should exist');
        }
    }

    public function testPhaseTwoBriefCalendarCommentsApprovalsAndVersions(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Phase Two Campaign');
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Phase Two Brief',
            'objective' => 'Launch workflow-first marketing',
            'audience' => 'Marketing managers',
            'offer_text' => 'Better campaign control',
            'key_message' => 'Plan, review, and ship from one CRM workspace.',
            'channels' => 'email, linkedin',
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+14 days')),
            'campaign_id' => $campaignId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);

        $brief = $this->marketing->getCampaignBrief($briefId);
        $this->assertSame('Phase Two Brief', $brief['title']);
        $this->assertSame(['email', 'linkedin'], $brief['channels_json']);

        $contentId = $this->marketing->createContentItem([
            'title' => 'Workflow Launch Email',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'draft',
            'campaign_brief_id' => $briefId,
            'campaign_id' => $campaignId,
            'draft_body' => 'First draft',
            'created_by' => $this->userId,
        ]);

        $this->marketing->updateContentItem($contentId, [
            'draft_body' => 'Second draft',
            'status' => 'review',
            'change_summary' => 'Prepared for review',
        ]);
        $versions = $this->marketing->listContentVersions($contentId);
        $this->assertNotEmpty($versions);
        $this->assertSame('Prepared for review', $versions[0]['change_summary']);

        $commentId = $this->marketing->addContentComment($contentId, 'Tighten the call to action.', $this->userId);
        $comments = $this->marketing->listContentComments($contentId, false);
        $this->assertCount(1, $comments);
        $this->marketing->resolveContentComment($commentId, $this->userId);
        $this->assertCount(0, $this->marketing->listContentComments($contentId, false));

        $approvalId = $this->marketing->requestContentReview($contentId, $this->userId);
        $approvals = $this->marketing->listContentApprovals(['status' => 'pending']);
        $this->assertSame($approvalId, (int) $approvals[0]['id']);
        $this->marketing->decideContentApproval($approvalId, 'approved', 'Looks good', $this->userId);
        $this->assertSame('approved', $this->marketing->getContentItem($contentId)['status']);

        $milestoneId = $this->marketing->createCalendarMilestone([
            'title' => 'Workflow launch publish date',
            'milestone_type' => 'publish',
            'milestone_date' => date('Y-m-d', strtotime('+3 days')),
            'campaign_brief_id' => $briefId,
            'content_item_id' => $contentId,
            'created_by' => $this->userId,
        ]);
        $milestones = $this->marketing->listCalendarMilestones([
            'date_from' => date('Y-m-d'),
            'date_to' => date('Y-m-d', strtotime('+7 days')),
        ]);
        $this->assertContains($milestoneId, array_map(static fn(array $row): int => (int) $row['id'], $milestones));
    }

    public function testPhaseTwoWorkspaceIsolation(): void
    {
        $briefId = $this->marketing->createCampaignBrief(['title' => 'Private Brief']);
        $contentId = $this->marketing->createContentItem(['title' => 'Private Content']);
        $otherWorkspaceId = $this->createWorkspace('Other Workflow Workspace');

        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getCampaignBrief($briefId));

        $this->expectExceptionMessage('Linked record was not found in the active workspace.');
        $otherMarketing->createCalendarMilestone([
            'title' => 'Bad cross-workspace milestone',
            'milestone_date' => date('Y-m-d'),
            'content_item_id' => $contentId,
        ]);
    }

    public function testPhaseThreeStrategyTablesExist(): void
    {
        foreach ([
            'marketing_brand_profiles',
            'marketing_personas',
            'marketing_seo_topics',
            'marketing_landing_pages',
        ] as $table) {
            $this->assertTrue(Database::tableExists($table), $table . ' should exist');
        }
    }

    public function testPhaseThreeStrategyCrudLandingSlugAndAiContext(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Strategy Campaign');
        $formId = $this->createForm($this->workspaceId, 'Strategy Form');

        $brandId = $this->marketing->createBrandProfile([
            'name' => 'Trusted CRM',
            'voice' => 'Clear and practical',
            'tone' => 'confident',
            'value_props' => 'One workspace for growth operations',
            'is_default' => 1,
            'created_by' => $this->userId,
        ]);
        $personaId = $this->marketing->createPersona([
            'name' => 'Growth Owner',
            'segment' => 'SMB operators',
            'pains' => 'Scattered marketing tasks',
            'goals' => 'Consistent demand generation',
            'preferred_channels' => 'email, linkedin',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Workflow Launch Page',
            'slug' => 'workflow-launch',
            'headline' => 'Plan and approve marketing faster',
            'body_sections' => "Hero\nOne workspace for campaign execution.",
            'form_id' => $formId,
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ]);
        $seoTopicId = $this->marketing->createSeoTopic([
            'keyword' => 'marketing workflow crm',
            'intent' => 'commercial',
            'priority' => 'high',
            'status' => 'planned',
            'created_by' => $this->userId,
        ]);

        $contentId = $this->marketing->createContentItem([
            'title' => 'Strategy Grounded Blog',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'brand_profile_id' => $brandId,
            'persona_id' => $personaId,
            'seo_topic_id' => $seoTopicId,
            'landing_page_id' => $landingPageId,
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ]);
        $item = $this->marketing->getContentItem($contentId);
        $this->assertSame('Trusted CRM', $item['brand_profile_name']);
        $this->assertSame('Growth Owner', $item['persona_name']);
        $this->assertSame('marketing workflow crm', $item['seo_topic_keyword']);
        $this->assertSame('Workflow Launch Page', $item['landing_page_title']);

        $draft = $this->marketing->generateDraft([
            'title' => 'Strategy Grounded Blog',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'brand_profile_id' => $brandId,
            'persona_id' => $personaId,
            'seo_topic_id' => $seoTopicId,
            'landing_page_id' => $landingPageId,
        ]);
        $this->assertSame('Trusted CRM', $draft['ai_context']['prompt_inputs']['brand_profile']['name']);
        $this->assertSame('Growth Owner', $draft['ai_context']['prompt_inputs']['persona']['name']);
    }

    public function testPhaseThreeLandingPageSlugIsUniquePerWorkspace(): void
    {
        $this->marketing->createLandingPage([
            'title' => 'Demo Page',
            'slug' => 'demo-page',
        ]);

        $this->expectExceptionMessage('Landing page slug must be unique in this workspace.');
        $this->marketing->createLandingPage([
            'title' => 'Demo Page Again',
            'slug' => 'demo-page',
        ]);
    }

    public function testPhaseFourDistributionTablesExist(): void
    {
        foreach ([
            'marketing_assets',
            'marketing_distribution_posts',
            'marketing_utm_links',
        ] as $table) {
            $this->assertTrue(Database::tableExists($table), $table . ' should exist');
        }
    }

    public function testPhaseFourAssetsDistributionUtmAndPerformance(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Distribution Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Distribution Source',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'draft_body' => 'Source draft copy',
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ]);
        $assetId = $this->marketing->createAsset([
            'title' => 'Launch Graphic',
            'asset_type' => 'image',
            'asset_url' => 'https://example.com/launch.png',
            'channel' => 'linkedin',
            'usage_rights' => 'Owned by workspace',
            'created_by' => $this->userId,
        ]);

        $postId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'asset_id' => $assetId,
            'channel' => 'linkedin',
            'scheduled_at' => date('Y-m-d\TH:i', strtotime('+1 day')),
            'created_by' => $this->userId,
        ]);
        $posts = $this->marketing->listDistributionPosts(['open' => true]);
        $this->assertSame($postId, (int) $posts[0]['id']);
        $this->assertSame('Source draft copy', $posts[0]['planned_copy']);

        $this->marketing->exportDistributionPost($postId);
        $exported = $this->marketing->listDistributionPosts(['status' => 'exported']);
        $this->assertSame($postId, (int) $exported[0]['id']);

        $utmId = $this->marketing->createUtmLink([
            'url' => 'https://example.com/landing',
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'utm_source' => 'linkedin',
            'utm_medium' => 'social',
            'utm_campaign' => 'launch',
            'created_by' => $this->userId,
        ]);
        $links = $this->marketing->listUtmLinks();
        $this->assertSame($utmId, (int) $links[0]['id']);
        $this->assertStringContainsString('utm_source=linkedin', $links[0]['generated_url']);

        $summary = $this->marketing->getPerformanceSummary();
        $this->assertSame(1, (int) $summary['utm_links']);
        $this->assertNotEmpty($summary['by_channel']);
    }

    public function testPhaseFourInvalidUtmUrlIsRejected(): void
    {
        $this->expectExceptionMessage('A valid HTTP or HTTPS URL is required.');
        $this->marketing->createUtmLink([
            'url' => 'not-a-url',
            'utm_source' => 'linkedin',
        ]);
    }

    public function testPhaseFiveContextHubCrudCompletenessAndAiContext(): void
    {
        $brandId = $this->marketing->createBrandProfile(['name' => 'Context Brand', 'created_by' => $this->userId]);
        $personaId = $this->marketing->createPersona(['name' => 'Context Persona', 'created_by' => $this->userId]);
        $contextId = $this->marketing->createContextItem([
            'item_type' => 'offer',
            'title' => 'Free CRM Audit',
            'body' => 'A practical audit offer for teams with scattered marketing execution.',
            'persona_id' => $personaId,
            'created_by' => $this->userId,
        ]);

        $items = $this->marketing->listContextItems(['item_type' => 'offer']);
        $this->assertSame($contextId, (int) $items[0]['id']);
        $this->assertSame('Context Persona', $items[0]['persona_name']);

        $score = $this->marketing->getContextCompleteness();
        $this->assertGreaterThan(0, $score['score']);
        $this->assertSame(1, $score['counts']['offer']);

        $draft = $this->marketing->generateDraft([
            'title' => 'Context Driven Email',
            'content_type' => 'email',
            'channel' => 'email',
            'brand_profile_id' => $brandId,
            'persona_id' => $personaId,
        ]);
        $this->assertSame('Free CRM Audit', $draft['ai_context']['prompt_inputs']['marketing_context'][0]['title']);
    }

    public function testPhaseFiveContextWorkspaceIsolation(): void
    {
        $this->marketing->createContextItem(['item_type' => 'proof_point', 'title' => 'Private Proof']);
        $otherWorkspaceId = $this->createWorkspace('Other Context Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertSame([], $otherMarketing->listContextItems(['item_type' => 'proof_point']));
    }

    public function testPhaseSixCampaignStrategyReadinessRoadmapAndMatrix(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Readiness Campaign');
        $personaId = $this->marketing->createPersona(['name' => 'Sales Founder', 'created_by' => $this->userId]);
        $offerId = $this->marketing->createContextItem([
            'item_type' => 'offer',
            'title' => 'Pipeline Audit',
            'body' => 'A high-fit offer for founders who need cleaner pipeline.',
        ]);
        $pillarId = $this->marketing->createContextItem([
            'item_type' => 'content_pillar',
            'title' => 'Revenue Operations',
            'body' => 'Content about keeping sales and marketing aligned.',
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Pipeline Audit Page',
            'slug' => 'pipeline-audit',
            'headline' => 'Find pipeline leaks',
        ]);

        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Pipeline Audit Launch',
            'objective' => 'Generate qualified audits',
            'audience' => 'Founder-led sales teams',
            'offer_text' => 'Free pipeline audit',
            'key_message' => 'Spot and fix revenue leaks before they compound.',
            'channels' => 'email, linkedin',
            'persona_id' => $personaId,
            'offer_context_item_id' => $offerId,
            'content_pillar_context_item_id' => $pillarId,
            'landing_page_id' => $landingPageId,
            'success_metrics' => '10 audit requests and 3 qualified opportunities',
            'budget_estimate' => '2500',
            'channel_plan' => "Email sequence\nLinkedIn founder posts",
            'launch_timeline' => "Brief approved Monday\nLaunch next Friday",
            'campaign_id' => $campaignId,
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+21 days')),
            'created_by' => $this->userId,
        ]);

        $brief = $this->marketing->getCampaignBrief($briefId);
        $this->assertSame('Sales Founder', $brief['persona_name']);
        $this->assertSame('Pipeline Audit', $brief['offer_title']);
        $this->assertSame('Revenue Operations', $brief['content_pillar_title']);
        $this->assertSame('Pipeline Audit Page', $brief['landing_page_title']);
        $this->assertSame(100, (int) $brief['readiness_score']);

        $readiness = $this->marketing->getCampaignReadiness($briefId);
        $this->assertSame(100, $readiness['score']);
        $this->assertSame([], $readiness['missing']);

        $roadmap = $this->marketing->listCampaignRoadmap(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+30 days')));
        $this->assertContains($briefId, array_map(static fn(array $row): int => (int) $row['id'], $roadmap));

        $matrix = $this->marketing->getPersonaOfferMatrix();
        $matchingRows = array_values(array_filter($matrix, static fn(array $row): bool => (int) $row['persona_id'] === $personaId && (int) $row['offer_id'] === $offerId));
        $this->assertSame(1, (int) $matchingRows[0]['brief_count']);
    }

    public function testPhaseSixCampaignStrategyRejectsWrongContextType(): void
    {
        $personaId = $this->marketing->createPersona(['name' => 'Wrong Type Persona']);
        $proofId = $this->marketing->createContextItem(['item_type' => 'proof_point', 'title' => 'Not an offer']);

        $this->expectExceptionMessage('Linked marketing context record has the wrong type.');
        $this->marketing->createCampaignBrief([
            'title' => 'Bad Strategy Brief',
            'objective' => 'Validate context type',
            'audience' => 'Marketing operators',
            'persona_id' => $personaId,
            'offer_context_item_id' => $proofId,
        ]);
    }

    public function testCampaignBriefAiDraftUsesPromptJsonForReviewOnly(): void
    {
        $ai = new class extends AIService {
            public string $promptKey = '';

            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                $this->promptKey = $promptKey;
                return [
                    'surface' => $surface,
                    'prompt_key' => $promptKey,
                    'prompt_version' => 9,
                    'rendered_prompt' => 'brief builder prompt',
                    'context_bundle' => $bundle,
                ];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return json_encode([
                    'objective' => 'Increase demo bookings from campaign-fit operators',
                    'audience' => 'Operations leaders at scaling service firms',
                    'offer_text' => 'Free pipeline operations audit',
                    'key_message' => 'Turn messy follow-up into a measurable operating rhythm.',
                    'channels' => ['email', 'linkedin'],
                    'channel_plan' => ['Email: send the audit invitation', 'LinkedIn: publish the founder proof post'],
                    'launch_timeline' => ['Approve brief', 'Create drafts', 'Review results'],
                    'success_metrics' => 'Demo bookings, reply rate, and approved draft readiness',
                ]);
            }

            public function getLastProviderStatus(): array
            {
                return ['success' => true, 'mode' => 'fake_ai'];
            }
        };
        $marketing = new Marketing(null, $ai);

        $draft = $marketing->generateCampaignBriefDraft([
            'title' => 'AI Brief Builder',
            'channels' => 'email, linkedin',
        ]);

        $this->assertSame('brief_builder', $ai->promptKey);
        $this->assertSame('Increase demo bookings from campaign-fit operators', $draft['fields']['objective']);
        $this->assertSame("Email: send the audit invitation\nLinkedIn: publish the founder proof post", $draft['fields']['channel_plan']);
        $this->assertSame(9, (int) $draft['ai_context']['prompt_version']);
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS count FROM marketing_campaign_briefs WHERE workspace_id = ?", [$this->workspaceId])['count'] ?? 0));
    }

    public function testCampaignBriefEditorGeneratesReviewableDraftWithoutSaving(): void
    {
        $response = $this->runWebEndpoint('public/marketing_brief_edit.php', $this->webSession('owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-marketing',
                'id' => '',
                'action' => 'generate_brief',
                'title' => 'Endpoint AI Brief',
                'status' => 'draft',
                'objective' => '',
                'audience' => 'Marketing operators',
                'offer_text' => '',
                'key_message' => '',
                'channels' => 'email, linkedin',
                'channel_plan' => '',
                'launch_timeline' => '',
                'success_metrics' => '',
            ],
        ]);

        $this->assertEndpointHealthy($response, 200, 'marketing_brief_edit.php generate');
        $this->assertStringContainsString('AI brief draft generated', (string) ($response['body'] ?? ''));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS count FROM marketing_campaign_briefs WHERE workspace_id = ? AND title = 'Endpoint AI Brief'", [$this->workspaceId])['count'] ?? 0));
    }

    public function testPhaseSevenContentToolsStoreRunsAndCreateVariants(): void
    {
        $this->assertTrue(Database::tableExists('marketing_content_tool_runs'));

        $brandId = $this->marketing->createBrandProfile(['name' => 'Tool Brand', 'voice' => 'direct', 'created_by' => $this->userId]);
        $personaId = $this->marketing->createPersona(['name' => 'Tool Persona', 'created_by' => $this->userId]);
        $seoTopicId = $this->marketing->createSeoTopic(['keyword' => 'marketing content tools', 'status' => 'planned']);
        $this->marketing->createContextItem(['item_type' => 'default_cta', 'title' => 'Book a strategy call']);
        $originalDraft = 'Marketing content tools help teams turn context into useful campaign drafts. Book a strategy call to improve your next launch.';
        $contentId = $this->marketing->createContentItem([
            'title' => 'Tool Assisted Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'objective' => 'book a strategy call',
            'target_audience' => 'Marketing teams',
            'draft_body' => $originalDraft,
            'brand_profile_id' => $brandId,
            'persona_id' => $personaId,
            'seo_topic_id' => $seoTopicId,
            'created_by' => $this->userId,
        ]);

        foreach ([
            ['outline', []],
            ['first_draft', []],
            ['rewrite_channel', ['channel' => 'linkedin']],
            ['shorten', []],
            ['expand', []],
            ['change_tone', ['tone' => 'warm and practical']],
            ['add_cta', []],
            ['repurpose', ['channels' => 'linkedin, email']],
            ['seo_score', []],
            ['brand_persona_score', []],
        ] as [$action, $input]) {
            $result = $this->marketing->runContentTool($contentId, $action, $input + ['created_by' => $this->userId]);
            $this->assertSame('completed', $result['status'], $action);
        }

        $runs = $this->marketing->listContentToolRuns(['content_item_id' => $contentId], 20, 0);
        $this->assertGreaterThanOrEqual(10, count($runs));
        $this->assertArrayHasKey('input', $runs[0]['input_json']);
        $this->assertArrayHasKey('mode', $runs[0]['provider_json']);
        $this->assertSame($originalDraft, (string) $this->marketing->getContentItem($contentId)['draft_body']);

        $toneRun = array_values(array_filter($runs, static fn(array $run): bool => (string) $run['action'] === 'change_tone'))[0] ?? null;
        $this->assertNotNull($toneRun);
        $this->assertStringContainsString('Tone: warm and practical', (string) ($toneRun['result_json']['body'] ?? ''));

        $applied = $this->marketing->applyContentToolRun((int) $toneRun['id'], $this->userId);
        $this->assertStringContainsString('Tone: warm and practical', (string) ($applied['content_item']['draft_body'] ?? ''));
        $versions = $this->marketing->listContentVersions($contentId);
        $this->assertSame('Applied content tool suggestion: Change Tone', (string) ($versions[0]['change_summary'] ?? ''));

        $posts = $this->marketing->listDistributionPosts(['content_item_id' => $contentId], 10, 0);
        $this->assertGreaterThanOrEqual(2, count($posts));
        $this->assertSame('draft', $posts[0]['status']);
    }

    public function testPhaseSevenContentToolsRespectWorkspaceIsolation(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Private Tool Content',
            'draft_body' => 'Only the owning workspace should transform this.',
        ]);
        $otherWorkspaceId = $this->createWorkspace('Other Tool Workspace');

        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();

        $this->expectExceptionMessage('Marketing content item not found.');
        $otherMarketing->runContentTool($contentId, 'shorten');
    }

    public function testContentToolSuggestionsMustBeAppliedWithinOwningWorkspace(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Apply Tool Suggestion',
            'draft_body' => 'Original workspace draft.',
        ]);
        $run = $this->marketing->runContentTool($contentId, 'expand', ['created_by' => $this->userId]);
        $this->assertSame('Original workspace draft.', (string) $this->marketing->getContentItem($contentId)['draft_body']);

        $otherWorkspaceId = $this->createWorkspace('Other Tool Apply Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();

        try {
            $otherMarketing->applyContentToolRun((int) $run['id'], $this->userId);
            $this->fail('Cross-workspace tool run application should fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Marketing content tool run not found.', $e->getMessage());
        }

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        $applied = $this->marketing->applyContentToolRun((int) $run['id'], $this->userId);
        $this->assertStringContainsString('Why this matters:', (string) ($applied['content_item']['draft_body'] ?? ''));
    }

    public function testPhaseEightGovernanceQueuesReadinessAndVersionTrail(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Governance Content',
            'content_type' => 'email',
            'channel' => 'email',
            'draft_body' => 'A governance-ready draft with enough context for review.',
            'created_by' => $this->userId,
        ]);
        $item = $this->marketing->getContentItem($contentId);
        $this->assertLessThan(100, (int) $item['readiness_score']);
        $this->assertContains('objective', $item['required_context_warnings_json']);

        $this->marketing->updateContentItem($contentId, [
            'reviewer_user_id' => $this->userId,
            'review_due_at' => date('Y-m-d\TH:i', strtotime('-2 hours')),
            'approval_checklist' => "Claim verified\nCTA checked",
            'blocked_reason' => 'Waiting for legal claim confirmation',
        ]);
        $approvalId = $this->marketing->requestContentReview(
            $contentId,
            $this->userId,
            $this->userId,
            date('Y-m-d\TH:i', strtotime('-1 hour'))
        );

        $overdue = $this->marketing->listContentApprovals(['queue' => 'overdue']);
        $this->assertContains($approvalId, array_map(static fn(array $row): int => (int) $row['id'], $overdue));
        $blocked = $this->marketing->listContentApprovals(['queue' => 'blocked']);
        $this->assertContains($approvalId, array_map(static fn(array $row): int => (int) $row['id'], $blocked));
        $this->assertSame('marketing@example.com', $blocked[0]['reviewer_email']);

        $this->marketing->decideContentApproval($approvalId, 'approved', 'Approved with blocker acknowledged', $this->userId);
        $approved = $this->marketing->listContentApprovals(['queue' => 'approved']);
        $this->assertContains($approvalId, array_map(static fn(array $row): int => (int) $row['id'], $approved));
        $versions = $this->marketing->listContentVersions($contentId);
        $this->assertNotEmpty($versions);
    }

    public function testPhaseNineLandingPageBuilderFieldsAndPreview(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Landing Builder Campaign');
        $formId = $this->createForm($this->workspaceId, 'Landing Builder Form');

        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Builder Ready Page',
            'slug' => 'builder-ready-page',
            'headline' => 'Launch a better landing page',
            'seo_title' => 'Builder Ready Landing Page',
            'meta_description' => 'A safe authenticated landing page preview for campaign planning.',
            'body_sections' => "Hero section\nExplain the offer.\n\nDetails\nShow the value.",
            'cta_blocks' => "Primary CTA\nBook a demo.",
            'proof_blocks' => "Proof\nTrusted by growing teams.",
            'faq_blocks' => "How does it work?\nThe team follows up after form submission.",
            'thank_you_copy' => 'Thanks. We will follow up shortly.',
            'conversion_goal' => 'demo_request',
            'form_id' => $formId,
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ]);

        $page = $this->marketing->getLandingPage($landingPageId);
        $this->assertSame('demo_request', $page['conversion_goal']);
        $this->assertNotEmpty($page['preview_token']);
        $this->assertCount(2, $page['body_sections_json']);
        $this->assertCount(1, $page['cta_blocks_json']);

        $preview = $this->marketing->getLandingPageByPreviewToken((string) $page['preview_token']);
        $this->assertSame('Builder Ready Page', $preview['title']);

        $response = $this->runWebEndpoint(
            'public/marketing_landing_page_preview.php',
            $this->webSession(),
            ['method' => 'GET', 'query' => ['token' => (string) $page['preview_token']]]
        );
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Authenticated preview only', (string) ($response['body'] ?? ''));
    }

    public function testPhaseNineLandingPageRejectsInvalidConversionGoal(): void
    {
        $this->expectExceptionMessage('Invalid conversion goal.');
        $this->marketing->createLandingPage([
            'title' => 'Invalid Goal Page',
            'conversion_goal' => 'external_publish',
        ]);
    }

    public function testPhaseThirtyEightLandingBuilderV2SectionsVersionsAndPreview(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Builder V2 Campaign');
        $formId = $this->createForm($this->workspaceId, 'Builder V2 Form');
        $sectionId = $this->marketing->createLandingPageSection([
            'title' => 'Reusable Proof Section',
            'section_type' => 'proof',
            'content' => ['heading' => 'Proof', 'body' => 'Use approved CRM proof only.'],
            'theme' => ['density' => 'compact'],
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $sectionId);
        $this->assertSame('Reusable Proof Section', (string) $this->marketing->listLandingPageSections(['id' => $sectionId])[0]['title']);

        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Builder V2 Landing',
            'slug' => 'builder-v2-landing',
            'headline' => 'A more visual landing page',
            'seo_title' => 'Builder V2 Landing',
            'meta_description' => 'Landing page with builder-ready theme, CTA, SEO, social and form controls.',
            'body_sections' => [['heading' => 'Problem', 'body' => 'Campaign pages need visual planning.']],
            'cta_blocks' => [['heading' => 'Book a review', 'body' => 'Choose a time with the team.']],
            'theme_settings' => ['style' => 'operator_clean', 'primary_color' => '#2563eb'],
            'cta_variants' => [['label' => 'Book a review', 'destination' => 'form']],
            'form_blocks' => [['placement' => 'after_cta', 'consent' => 'Human follow-up only']],
            'seo_controls' => ['keyword' => 'builder ready landing page', 'indexable' => false],
            'social_preview' => ['title' => 'Builder V2 Landing', 'description' => 'Safe preview copy'],
            'conversion_goal' => 'demo_request',
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'status' => 'approved',
            'builder_status' => 'ready',
            'created_by' => $this->userId,
        ]);

        $page = $this->marketing->getLandingPage($landingPageId);
        $this->assertSame('ready', (string) ($page['builder_status'] ?? ''));
        $this->assertSame('operator_clean', (string) ($page['theme_settings_json']['style'] ?? ''));
        $this->assertSame('Book a review', (string) ($page['cta_variants_json'][0]['label'] ?? ''));
        $this->assertNotEmpty($page['draft_version_id']);
        $this->assertCount(1, $this->marketing->listLandingPageVersions($landingPageId));

        $this->marketing->updateLandingPage($landingPageId, ['headline' => 'Updated visual landing page', 'created_by' => $this->userId]);
        $this->assertCount(2, array_filter(
            $this->marketing->listLandingPageVersions($landingPageId),
            static fn(array $version): bool => (string) $version['version_type'] === 'draft'
        ));

        $preview = $this->runWebEndpoint('public/marketing_landing_page_preview.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['token' => (string) ($this->marketing->getLandingPage($landingPageId)['preview_token'] ?? '')],
        ]);
        $this->assertEndpointHealthy($preview, 200, 'marketing_landing_page_preview.php builder v2');
        $this->assertStringContainsString('ds-form-embed', (string) ($preview['body'] ?? ''));
        $this->assertStringContainsString('form.php?uuid=', (string) ($preview['body'] ?? ''));
        $this->assertStringContainsString('Book a review', (string) ($preview['body'] ?? ''));

        $publication = $this->marketing->publishLandingPage($landingPageId, $this->userId);
        $publishedPage = $this->marketing->getLandingPage($landingPageId);
        $this->assertSame('published', (string) ($publishedPage['builder_status'] ?? ''));
        $this->assertNotEmpty($publishedPage['published_version_id']);
        $this->assertNotEmpty($publication['public_token']);

        $public = $this->runEndpointScript('public/marketing_landing_public.php', [
            'method' => 'GET',
            'query' => ['token' => (string) $publication['public_token']],
        ]);
        $this->assertEndpointHealthy($public, 200, 'marketing_landing_public.php builder v2');
        $this->assertStringContainsString('ds-form-embed', (string) ($public['body'] ?? ''));
        $this->assertStringContainsString('landing_token=', (string) ($public['body'] ?? ''));
        $this->assertStringContainsString('Book a review', (string) ($public['body'] ?? ''));
    }

    public function testPhaseSeventyFiveLandingBuilderReadinessAndPreviewChrome(): void
    {
        $heroMediaId = $this->marketing->createMediaFile([
            'title' => 'Alt Missing Hero',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/alt-missing-hero.jpg',
            'created_by' => $this->userId,
        ]);

        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Builder Polish Landing',
            'slug' => 'builder-polish-landing',
            'headline' => 'A landing page with guided polish',
            'body_sections' => [['heading' => 'Problem', 'body' => 'Teams need a clearer launch checklist.']],
            'cta_blocks' => [['heading' => 'Book a review', 'body' => 'Ask the team to review the page.']],
            'conversion_goal' => 'demo_request',
            'hero_media_file_id' => $heroMediaId,
            'created_by' => $this->userId,
        ]);

        $checklist = $this->marketing->getLandingPageBuilderChecklist($landingPageId);
        $checkKeys = array_column((array) ($checklist['checks'] ?? []), 'key');
        $requiredMissing = array_column((array) ($checklist['required_missing'] ?? []), 'key');
        $recommendedMissing = array_column((array) ($checklist['recommended_missing'] ?? []), 'key');

        $this->assertContains('hero_media', $checkKeys);
        $this->assertContains('social_preview_media', $checkKeys);
        $this->assertContains('media_accessibility', $requiredMissing);
        $this->assertContains('seo_title', $requiredMissing);
        $this->assertContains('form_placement', $requiredMissing);
        $this->assertContains('proof_blocks', $recommendedMissing);
        $this->assertLessThan(100, (int) ($checklist['score'] ?? 100));
        $this->assertStringContainsString('viewport=desktop', (string) ($checklist['preview_links']['desktop'] ?? ''));
        $this->assertStringContainsString('viewport=mobile', (string) ($checklist['preview_links']['mobile'] ?? ''));

        $view = $this->runWebEndpoint('public/marketing_landing_page_view.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['id' => $landingPageId],
        ]);
        $this->assertEndpointHealthy($view, 200, 'marketing_landing_page_view.php builder checklist');
        $viewBody = (string) ($view['body'] ?? '');
        $this->assertStringContainsString('Landing Builder Readiness', $viewBody);
        $this->assertStringContainsString('Media accessibility', $viewBody);
        $this->assertStringContainsString('Desktop Preview', $viewBody);
        $this->assertStringContainsString('Mobile Preview', $viewBody);
        $this->assertStringContainsString('No external hosting, email, social, ad, or SEO API is triggered', $viewBody);

        $page = $this->marketing->getLandingPage($landingPageId);
        $preview = $this->runWebEndpoint('public/marketing_landing_page_preview.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => [
                'token' => (string) ($page['preview_token'] ?? ''),
                'viewport' => 'mobile',
            ],
        ]);
        $this->assertEndpointHealthy($preview, 200, 'marketing_landing_page_preview.php mobile chrome');
        $previewBody = (string) ($preview['body'] ?? '');
        $this->assertStringContainsString('Authenticated preview only - Mobile Preview', $previewBody);
        $this->assertStringContainsString('landing-preview is-mobile', $previewBody);
        $this->assertStringContainsString('Desktop Preview', $previewBody);
        $this->assertStringContainsString('alt-missing-hero.jpg', $previewBody);
    }

    public function testPhaseThirtyNineAiMediaBridgeRequiresExplicitAcceptance(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'AI Media Content',
            'content_type' => 'social_post',
            'channel' => 'linkedin',
            'target_audience' => 'Operations leaders',
            'objective' => 'Book a workflow review',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'AI Media Landing',
            'slug' => 'ai-media-landing',
            'headline' => 'Plan visual proof',
            'created_by' => $this->userId,
        ]);
        $beforeMediaCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM marketing_media_files WHERE workspace_id = ?",
            [$this->workspaceId]
        )['count'] ?? 0);

        $generated = $this->marketing->generateAiMediaRequest([
            'title' => 'Launch hero visual',
            'request_type' => 'image',
            'prompt_text' => 'Create a customer-outcome hero concept for the launch.',
            'content_item_id' => $contentId,
            'landing_page_id' => $landingPageId,
            'created_by' => $this->userId,
        ]);

        $request = $generated['request'];
        $this->assertSame('generated', (string) ($request['status'] ?? ''));
        $this->assertSame('image', (string) ($request['request_type'] ?? ''));
        $this->assertFalse((bool) ($request['provider_json']['external_generation'] ?? true));
        $this->assertTrue((bool) ($generated['no_external_generation'] ?? false));
        $this->assertCount(1, (array) ($generated['outputs'] ?? []));
        $this->assertSame($beforeMediaCount, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM marketing_media_files WHERE workspace_id = ?",
            [$this->workspaceId]
        )['count'] ?? 0));

        $outputId = (int) ($generated['outputs'][0]['id'] ?? 0);
        $mediaId = $this->marketing->acceptAiMediaOutput($outputId, [
            'title' => 'Accepted launch hero concept',
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $mediaId);

        $media = $this->marketing->getMediaFile($mediaId);
        $this->assertSame('Accepted launch hero concept', (string) ($media['title'] ?? ''));
        $this->assertSame('image', (string) ($media['media_type'] ?? ''));
        $this->assertSame('reference', (string) ($media['source_type'] ?? ''));
        $this->assertSame('ai_media_generation_bridge', (string) ($media['metadata_json']['source'] ?? ''));
        $this->assertSame($outputId, (int) ($media['metadata_json']['output_id'] ?? 0));

        $acceptedOutput = $this->marketing->getAiMediaOutput($outputId);
        $acceptedRequest = $this->marketing->getAiMediaRequest((int) ($request['id'] ?? 0));
        $this->assertSame('accepted', (string) ($acceptedOutput['status'] ?? ''));
        $this->assertSame($mediaId, (int) ($acceptedOutput['accepted_media_file_id'] ?? 0));
        $this->assertSame('accepted', (string) ($acceptedRequest['status'] ?? ''));
        $this->assertSame($mediaId, (int) ($acceptedRequest['accepted_media_file_id'] ?? 0));

        $response = $this->runWebEndpoint('public/marketing_assets.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($response, 200, 'marketing_assets.php AI media bridge');
        $this->assertStringContainsString('AI Media Bridge', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Accepted launch hero concept', (string) ($response['body'] ?? ''));
    }

    public function testPhaseThirtyNineAiMediaBridgeWorkspaceAndRoleLimits(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->generateAiMediaRequest([
                'title' => 'Viewer media request',
                'request_type' => 'thumbnail',
                'created_by' => $this->userId,
            ]);
            $this->fail('Viewer should not generate AI media requests.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $generated = $this->marketing->generateAiMediaRequest([
            'title' => 'Marketing thumbnail request',
            'request_type' => 'thumbnail',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('generated', (string) ($generated['request']['status'] ?? ''));
        $this->assertSame('thumbnail_prompt', (string) ($generated['outputs'][0]['output_type'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('AI Media Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getAiMediaRequest((int) ($generated['request']['id'] ?? 0)));
        $this->assertNull($otherMarketing->getAiMediaOutput((int) ($generated['outputs'][0]['id'] ?? 0)));

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
    }

    public function testPhaseFortyChannelConnectorFrameworkDryRunDiagnostics(): void
    {
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'LinkedIn Dry Run Connector',
            'connector_type' => 'linkedin',
            'status' => 'configured',
            'execution_mode' => 'dry_run',
            'capabilities' => ['copy_export', 'media_kit_export', 'dry_run'],
            'config' => [
                'workspace_note' => 'Manual publishing package only.',
                'api_token' => 'super-secret-token',
                'endpoint_url' => 'https://example.com/dry-run',
            ],
            'created_by' => $this->userId,
        ]);
        $connector = $this->marketing->getChannelConnector($connectorId);
        $this->assertSame('LinkedIn Dry Run Connector', (string) ($connector['name'] ?? ''));
        $this->assertSame('linkedin', (string) ($connector['connector_type'] ?? ''));
        $this->assertSame('[configured]', (string) ($connector['config_json']['api_token'] ?? ''));
        $this->assertStringNotContainsString('super-secret-token', json_encode($connector));

        $test = $this->marketing->runChannelConnectorTest($connectorId, [
            'test_mode' => 'dry_run',
            'api_token' => 'another-secret-token',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('passed', (string) ($test['status'] ?? ''));
        $this->assertFalse((bool) ($test['result_json']['external_execution'] ?? true));
        $this->assertTrue((bool) ($test['result_json']['dry_run_only'] ?? false));
        $this->assertSame('[configured]', (string) ($test['input_json']['api_token'] ?? ''));
        $this->assertStringNotContainsString('another-secret-token', json_encode($test));

        $updated = $this->marketing->getChannelConnector($connectorId);
        $this->assertSame('ready', (string) ($updated['status'] ?? ''));
        $this->assertTrue((bool) ($updated['diagnostics_json']['secret_safe'] ?? false));
        $diagnostics = $this->marketing->getChannelConnectorDiagnostics();
        $this->assertSame(1, (int) ($diagnostics['total_connectors'] ?? 0));
        $this->assertFalse((bool) ($diagnostics['live_execution_enabled'] ?? true));

        $response = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($response, 200, 'marketing_channel_exports.php connector framework');
        $this->assertStringContainsString('Channel Connectors', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('LinkedIn Dry Run Connector', (string) ($response['body'] ?? ''));
        $this->assertStringNotContainsString('super-secret-token', (string) ($response['body'] ?? ''));
    }

    public function testPhaseFortyChannelConnectorsWorkspaceAndPermissionLimits(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createChannelConnector([
                'name' => 'Viewer Connector',
                'connector_type' => 'email',
                'created_by' => $this->userId,
            ]);
            $this->fail('Viewer should not create channel connectors.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Email Dry Run Connector',
            'connector_type' => 'email',
            'capabilities' => ['csv_export', 'unsubscribe_check', 'dry_run'],
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $connectorId);
        try {
            $this->marketing->archiveChannelConnector($connectorId);
            $this->fail('Marketing role should not archive channel connectors.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        $otherWorkspaceId = $this->createWorkspace('Connector Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getChannelConnector($connectorId));
        $this->assertSame([], $otherMarketing->listChannelConnectorTestRuns(['connector_id' => $connectorId]));

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $this->assertTrue($this->marketing->archiveChannelConnector($connectorId));
        $this->assertSame('archived', (string) ($this->marketing->getChannelConnector($connectorId)['status'] ?? ''));
    }

    public function testPhaseSixtySixConnectorReadinessSetupScoresAndPageSurface(): void
    {
        $attentionConnectorId = $this->marketing->createChannelConnector([
            'name' => 'Email Needs Setup Connector',
            'connector_type' => 'email',
            'status' => 'configured',
            'capabilities' => ['csv_export'],
            'setup_checklist' => ['consent policy reviewed'],
            'created_by' => $this->userId,
        ]);
        $attentionReview = $this->marketing->evaluateChannelConnectorReadiness($attentionConnectorId, $this->userId);
        $this->assertSame('needs_attention', (string) ($attentionReview['status'] ?? ''));
        $this->assertContains('dry_run_test_passed', (array) ($attentionReview['missing_checks_json'] ?? []));
        $this->assertContains('owner_assigned', (array) ($attentionReview['missing_checks_json'] ?? []));
        $this->assertContains('consent_safety_reviewed', (array) ($attentionReview['missing_checks_json'] ?? []));
        $attentionConnector = $this->marketing->getChannelConnector($attentionConnectorId);
        $this->assertSame('in_progress', (string) ($attentionConnector['setup_status'] ?? ''));
        $this->assertLessThan(90, (int) ($attentionConnector['readiness_score'] ?? 100));

        $readyConnectorId = $this->marketing->createChannelConnector([
            'name' => 'Email Ready Setup Connector',
            'connector_type' => 'email',
            'status' => 'configured',
            'execution_mode' => 'dry_run',
            'capabilities' => ['csv_export', 'unsubscribe_check', 'dry_run'],
            'setup_checklist' => ['consent policy reviewed', 'suppression list reviewed', 'manual export owner named'],
            'owner_user_id' => $this->userId,
            'config' => ['api_token' => 'phase-sixty-six-secret', 'endpoint_url' => 'https://example.com/dry-run'],
            'created_by' => $this->userId,
        ]);
        $this->marketing->runChannelConnectorTest($readyConnectorId, [
            'test_mode' => 'dry_run',
            'api_token' => 'phase-sixty-six-test-secret',
            'created_by' => $this->userId,
        ]);
        $readyReview = $this->marketing->evaluateChannelConnectorReadiness($readyConnectorId, $this->userId);
        $this->assertSame('ready', (string) ($readyReview['status'] ?? ''));
        $this->assertSame(100, (int) ($readyReview['readiness_score'] ?? 0));
        $this->assertSame([], (array) ($readyReview['missing_checks_json'] ?? ['unexpected']));
        $this->assertStringNotContainsString('phase-sixty-six-secret', json_encode($readyReview));
        $this->assertStringNotContainsString('phase-sixty-six-test-secret', json_encode($readyReview));

        $readyConnector = $this->marketing->getChannelConnector($readyConnectorId);
        $this->assertSame('ready', (string) ($readyConnector['setup_status'] ?? ''));
        $this->assertSame(100, (int) ($readyConnector['readiness_score'] ?? 0));
        $this->assertTrue((bool) ($readyConnector['diagnostics_json']['secret_safe'] ?? false));

        $summary = $this->marketing->getChannelConnectorReadinessSummary();
        $this->assertSame(2, (int) ($summary['total_connectors'] ?? 0));
        $this->assertSame(1, (int) ($summary['ready_connectors'] ?? 0));
        $this->assertSame(1, (int) ($summary['needs_review_connectors'] ?? 0));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->evaluateChannelConnectorReadiness($readyConnectorId, $this->userId);
            $this->fail('Viewer should not evaluate connector readiness.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $response = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($response, 200, 'marketing_channel_exports.php connector readiness');
        $this->assertStringContainsString('Review Readiness', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Recent Connector Readiness Reviews', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Email Ready Setup Connector', (string) ($response['body'] ?? ''));
        $this->assertStringNotContainsString('phase-sixty-six-secret', (string) ($response['body'] ?? ''));
    }

    public function testPhaseSixtySevenExecutionControlCenterAggregatesManualExecutionQueues(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $channelBundleId = $this->marketing->createChannelExportBundle($fixture['distribution_post_id'], $this->userId);

        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Execution Center Ready Connector',
            'connector_type' => 'email',
            'status' => 'configured',
            'execution_mode' => 'dry_run',
            'capabilities' => ['csv_export', 'unsubscribe_check', 'dry_run'],
            'setup_checklist' => ['consent policy reviewed', 'suppression list reviewed', 'manual export owner named'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->marketing->evaluateChannelConnectorReadiness($connectorId, $this->userId);

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'email',
            'execution_mode' => 'dry_run',
            'connector_id' => $connectorId,
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'email_run_id' => $fixture['email_run_id'],
            'channel_export_bundle_id' => $channelBundleId,
            'payload' => ['manual_package' => 'ready for operator review'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved for dry-run execution center test.', $this->userId));

        $summary = $this->marketing->getExecutionControlCenterSummary();
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['ready_to_execute'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['manual_exports'] ?? 0));
        $this->assertFalse((bool) ($summary['manual_first_boundary']['external_send'] ?? true));
        $this->assertFalse((bool) ($summary['manual_first_boundary']['external_publish'] ?? true));
        $this->assertNotEmpty($summary['next_actions']);

        $ownerResponse = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($ownerResponse, 200, 'marketing_execution.php owner');
        $this->assertStringContainsString('Marketing Execution Control Center', (string) ($ownerResponse['body'] ?? ''));
        $this->assertStringContainsString('Ready To Execute', (string) ($ownerResponse['body'] ?? ''));
        $this->assertStringContainsString('Manual Export Queue', (string) ($ownerResponse['body'] ?? ''));
        $this->assertStringContainsString('External send', (string) ($ownerResponse['body'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $viewerResponse = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('viewer'), ['method' => 'GET']);
        $this->assertEndpointHealthy($viewerResponse, 200, 'marketing_execution.php viewer');
        $this->assertStringContainsString('Marketing Execution Control Center', (string) ($viewerResponse['body'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
    }

    public function testPhaseSixtyEightCampaignLaunchChecklistsScoreAndRenderManualLaunchReadiness(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $this->marketing->updateContentItem($fixture['content_id'], ['status' => 'approved']);
        $channelBundleId = $this->marketing->createChannelExportBundle($fixture['distribution_post_id'], $this->userId);
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Launch Checklist Ready Connector',
            'connector_type' => 'email',
            'status' => 'configured',
            'execution_mode' => 'dry_run',
            'capabilities' => ['csv_export', 'unsubscribe_check', 'dry_run'],
            'setup_checklist' => ['consent policy reviewed', 'suppression list reviewed', 'manual export owner named'],
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->marketing->evaluateChannelConnectorReadiness($connectorId, $this->userId);

        $checklistId = $this->marketing->createCampaignLaunchChecklist([
            'title' => 'Release QA Launch Checklist',
            'campaign_id' => $fixture['campaign_id'],
            'campaign_brief_id' => $fixture['brief_id'],
            'audience_segment_id' => $fixture['segment_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'content_item_id' => $fixture['content_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'channel_export_bundle_id' => $channelBundleId,
            'connector_id' => $connectorId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ], $this->userId);
        $this->assertGreaterThan(0, $checklistId);
        $checklist = $this->marketing->getCampaignLaunchChecklist($checklistId);
        $this->assertSame('ready', (string) ($checklist['status'] ?? ''));
        $this->assertGreaterThanOrEqual(90, (int) ($checklist['readiness_score'] ?? 0));
        $this->assertSame([], (array) ($checklist['missing_items_json'] ?? ['unexpected']));
        $this->assertFalse((bool) ($checklist['metadata_json']['external_execution'] ?? true));

        $items = $this->marketing->listCampaignLaunchChecklistItems($checklistId);
        $this->assertGreaterThanOrEqual(9, count($items));
        $this->assertContains('brief_ready', array_map(static fn(array $item): string => (string) $item['check_key'], $items));

        $blockedId = $this->marketing->createCampaignLaunchChecklist([
            'title' => 'Incomplete Launch Checklist',
            'created_by' => $this->userId,
        ], $this->userId);
        $blocked = $this->marketing->getCampaignLaunchChecklist($blockedId);
        $this->assertSame('blocked', (string) ($blocked['status'] ?? ''));
        $this->assertContains('brief_ready', (array) ($blocked['missing_items_json'] ?? []));
        $this->assertContains('audience_ready', (array) ($blocked['missing_items_json'] ?? []));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createCampaignLaunchChecklist([
                'title' => 'Viewer Launch Checklist',
                'created_by' => $this->userId,
            ], $this->userId);
            $this->fail('Viewer should not create campaign launch checklists.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $page = $this->runWebEndpoint('public/marketing_launch_checklists.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_launch_checklists.php owner');
        $this->assertStringContainsString('Campaign Launch Checklists', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Release QA Launch Checklist', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Manual publishing and sending still require operator action.', (string) ($page['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Launch Checklist Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getCampaignLaunchChecklist($checklistId));
        $this->assertSame([], $otherMarketing->listCampaignLaunchChecklists([], 10, 0));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseSixtyNineCommandCenterFlowSurfacesNextBestActions(): void
    {
        $initialPage = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($initialPage, 200, 'marketing.php command flow empty');
        $this->assertStringContainsString('Operator Command Flow', (string) ($initialPage['body'] ?? ''));
        $this->assertStringContainsString('Next Best Actions', (string) ($initialPage['body'] ?? ''));
        $this->assertStringContainsString('Run Launch Checklist', (string) ($initialPage['body'] ?? ''));

        $fixture = $this->createMarketingReleaseQaFixture();
        $checklistId = $this->marketing->createCampaignLaunchChecklist([
            'title' => 'Incomplete Command Flow Launch',
            'campaign_id' => $fixture['campaign_id'],
            'created_by' => $this->userId,
        ], $this->userId);
        $this->assertGreaterThan(0, $checklistId);

        $summary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['launch_checklists_blocked'] ?? 0));
        $this->assertNotEmpty($summary['launch_checklists']);
        $this->assertArrayHasKey('command_flow', $summary);
        $stageLabels = array_map(static fn(array $stage): string => (string) $stage['label'], (array) ($summary['command_flow']['stages'] ?? []));
        $this->assertContains('Launch', $stageLabels);
        $this->assertNotEmpty($summary['command_flow']['next_actions']);

        $populatedPage = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($populatedPage, 200, 'marketing.php command flow populated');
        $this->assertStringContainsString('Launch Checklists', (string) ($populatedPage['body'] ?? ''));
        $this->assertStringContainsString('Incomplete Command Flow Launch', (string) ($populatedPage['body'] ?? ''));
        $this->assertStringContainsString('Resolve Blocked Launch Checklist', (string) ($populatedPage['body'] ?? ''));
    }

    public function testPhaseSeventyMediaUsageMapConnectsCampaignSurfaces(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Campaign Hero Media',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/campaign-hero.png',
            'alt_text' => 'Campaign team reviewing launch media',
            'approval_status' => 'approved',
            'license_status' => 'owned',
            'created_by' => $this->userId,
        ]);
        $assetId = $this->marketing->createAsset([
            'title' => 'Campaign Hero Asset',
            'asset_type' => 'image',
            'media_file_id' => $mediaId,
            'channel' => 'linkedin',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($fixture['content_id'], [
            'media_file_id' => $mediaId,
            'role' => 'featured',
            'channel' => 'linkedin',
            'caption' => 'Campaign hero visual.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateLandingPage($fixture['landing_page_id'], [
            'hero_media_file_id' => $mediaId,
            'social_preview_media_file_id' => $mediaId,
        ]);
        $this->marketing->updateDistributionPost($fixture['distribution_post_id'], [
            'asset_id' => $assetId,
        ]);
        $kitId = $this->marketing->createChannelMediaKit([
            'title' => 'Campaign Hero LinkedIn Kit',
            'channel' => 'linkedin',
            'content_item_id' => $fixture['content_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'distribution_post_id' => $fixture['distribution_post_id'],
            'primary_media_file_id' => $mediaId,
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $kitId);

        $usage = $this->marketing->getMediaUsageMap($mediaId);
        $this->assertGreaterThanOrEqual(5, (int) ($usage['usage_count'] ?? 0));
        $usageTypes = array_values(array_unique(array_map(static fn(array $row): string => (string) $row['type'], (array) ($usage['usages'] ?? []))));
        foreach (['asset', 'content', 'landing_page', 'distribution', 'channel_media_kit'] as $expectedType) {
            $this->assertContains($expectedType, $usageTypes);
        }

        $summary = $this->marketing->getMarketingMediaWorkflowSummary();
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['media_files'] ?? 0));
        $this->assertArrayHasKey($mediaId, (array) ($summary['usage_maps'] ?? []));
        $this->assertNotEmpty($summary['recommendations']);

        $page = $this->runWebEndpoint('public/marketing_assets.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_assets.php media usage map');
        $this->assertStringContainsString('Media Usage Map', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Campaign Hero Media', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Recommended Media Actions', (string) ($page['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Media Workflow Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->getMediaUsageMap($mediaId);
            $this->fail('Cross-workspace media usage map should not be readable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseEightyMediaOperationsSummarySurfacesSafeArchiveAndAccessibility(): void
    {
        $unusedImageId = $this->marketing->createMediaFile([
            'title' => 'Unused Missing Alt Creative',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/unused-missing-alt.png',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Video Source Content',
            'content_type' => 'video_script',
            'channel' => 'youtube',
            'created_by' => $this->userId,
        ]);
        $blockedVideoId = $this->marketing->createMediaFile([
            'title' => 'Restricted Video Source',
            'media_type' => 'video',
            'source_type' => 'url',
            'source_url' => 'https://example.com/restricted-video.mp4',
            'approval_status' => 'blocked',
            'license_status' => 'restricted',
            'blocked_reason' => 'Usage rights need legal review.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $blockedVideoId,
            'role' => 'video_source',
            'created_by' => $this->userId,
        ]);

        $summary = $this->marketing->getMarketingMediaOperationsSummary();
        $this->assertGreaterThanOrEqual(2, (int) ($summary['counts']['total'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['safe_to_archive'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['needs_accessibility'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['rights_attention'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['export_blocked'] ?? 0));
        $operationsById = [];
        foreach ((array) ($summary['operations'] ?? []) as $operation) {
            $operationsById[(int) ($operation['media_id'] ?? 0)] = $operation;
        }

        $this->assertArrayHasKey($unusedImageId, $operationsById);
        $this->assertTrue((bool) ($operationsById[$unusedImageId]['safe_to_archive'] ?? false));
        $this->assertTrue((bool) ($operationsById[$unusedImageId]['needs_accessibility'] ?? false));
        $this->assertContains('missing_alt_text', (array) ($operationsById[$unusedImageId]['accessibility_issues'] ?? []));

        $this->assertArrayHasKey($blockedVideoId, $operationsById);
        $this->assertFalse((bool) ($operationsById[$blockedVideoId]['safe_to_archive'] ?? true));
        $this->assertTrue((bool) ($operationsById[$blockedVideoId]['export_blocked'] ?? false));
        $this->assertTrue((bool) ($operationsById[$blockedVideoId]['rights_attention'] ?? false));
        $this->assertContains('missing_caption_or_transcript', (array) ($operationsById[$blockedVideoId]['accessibility_issues'] ?? []));

        $page = $this->runWebEndpoint('public/marketing_assets.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_assets.php media operations board');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Media Operations Board', $body);
        $this->assertStringContainsString('Safe To Archive', $body);
        $this->assertStringContainsString('Needs Accessibility', $body);
        $this->assertStringContainsString('Export Blocked', $body);
        $this->assertStringContainsString('Unused Missing Alt Creative', $body);
        $this->assertStringContainsString('Restricted Video Source', $body);

        $dashboardSummary = $this->marketing->getDashboardSummary($this->marketing->getMarketingOnboardingStatus());
        $this->assertArrayHasKey('creative_readiness', $dashboardSummary);
        $this->assertGreaterThanOrEqual(1, (int) ($dashboardSummary['creative_readiness']['counts']['blocked_media'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($dashboardSummary['creative_readiness']['counts']['media_needing_accessibility'] ?? 0));
        $dashboard = $this->runWebEndpoint('public/marketing.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($dashboard, 200, 'marketing.php creative media readiness');
        $dashboardBody = (string) ($dashboard['body'] ?? '');
        $this->assertStringContainsString('Creative And Media Readiness', $dashboardBody);
        $this->assertStringContainsString('Blocked Media', $dashboardBody);
        $this->assertStringContainsString('AI creative remains advisory', $dashboardBody);
    }

    public function testPhaseEightyOneQaConsoleSurfacesReleaseReadinessGapsWithoutSecrets(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Stuck QA Review Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'status' => 'review',
            'blocked_reason' => 'Waiting on final claim review.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->requestContentReview($contentId, $this->userId, $this->userId, date('Y-m-d\TH:i', strtotime('-2 days')));
        Database::execute(
            "UPDATE marketing_content_items
             SET status = 'review', blocked_reason = 'Waiting on final claim review.', updated_at = DATE_SUB(NOW(), INTERVAL 20 DAY)
             WHERE id = ? AND workspace_id = ?",
            [$contentId, $this->workspaceId]
        );

        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Incomplete QA Landing',
            'slug' => 'incomplete-qa-landing-' . uniqid(),
            'created_by' => $this->userId,
        ]);
        Database::execute(
            "UPDATE marketing_landing_pages
             SET headline = '', seo_title = NULL, meta_description = NULL, form_id = NULL, hero_media_file_id = NULL, social_preview_media_file_id = NULL
             WHERE id = ? AND workspace_id = ?",
            [$landingPageId, $this->workspaceId]
        );

        $distributionPostId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'instagram',
            'planned_copy' => '',
            'created_by' => $this->userId,
        ]);
        $bundleId = $this->marketing->createChannelExportBundle($distributionPostId, $this->userId);
        $this->assertGreaterThan(0, $bundleId);

        $this->marketing->createMediaFile([
            'title' => 'QA Console Missing Alt',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/qa-console-missing-alt.jpg',
            'created_by' => $this->userId,
        ]);

        $console = $this->marketing->getMarketingQaConsole();
        $this->assertContains((string) ($console['status'] ?? ''), ['warning', 'blocked']);
        $checksByKey = [];
        foreach ((array) ($console['checks'] ?? []) as $check) {
            $checksByKey[(string) ($check['key'] ?? '')] = $check;
        }
        foreach (['schema', 'rbac', 'page_guides', 'media_operations', 'workflow_gaps', 'live_worker_health', 'starter_cleanup', 'no_secret_diagnostics'] as $expectedKey) {
            $this->assertArrayHasKey($expectedKey, $checksByKey);
        }
        $this->assertSame('ready', (string) ($checksByKey['rbac']['status'] ?? ''));
        $this->assertGreaterThanOrEqual(1, (int) ($console['sections']['workflow']['counts']['overdue_reviews'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($console['sections']['workflow']['counts']['stuck_review_content'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($console['sections']['workflow']['counts']['blocked_content'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($console['sections']['workflow']['counts']['landing_pages_missing_required'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($console['sections']['workflow']['counts']['draft_export_bundles'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($console['sections']['media_operations']['counts']['needs_accessibility'] ?? 0));
        $this->assertStringNotContainsString('DB_PASSWORD', json_encode($console, JSON_UNESCAPED_SLASHES) ?: '');

        $page = $this->runWebEndpoint('public/marketing_admin.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_admin.php QA console');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Marketing QA Console', $body);
        $this->assertStringContainsString('Workflow and conversion gaps', $body);
        $this->assertStringContainsString('No-secret diagnostics', $body);
        $this->assertStringNotContainsString('DB_PASSWORD', $body);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->getMarketingQaConsole();
            $this->fail('Marketing writer should not access manage-only QA console.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }
    }

    public function testPhaseFortyOneExecutionQueueDryRunApprovalAndBlockedLive(): void
    {
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Email Ready Connector',
            'connector_type' => 'email',
            'status' => 'configured',
            'capabilities' => ['csv_export', 'unsubscribe_check', 'dry_run'],
            'created_by' => $this->userId,
        ]);
        $this->marketing->runChannelConnectorTest($connectorId, ['created_by' => $this->userId]);
        $this->assertSame('ready', (string) ($this->marketing->getChannelConnector($connectorId)['status'] ?? ''));

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'email',
            'execution_mode' => 'dry_run',
            'connector_id' => $connectorId,
            'payload' => ['subject' => 'Manual export dry run', 'api_token' => 'queue-secret-token'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $queue = $this->marketing->getExecutionQueueItem($queueId);
        $this->assertSame('email', (string) ($queue['execution_type'] ?? ''));
        $this->assertSame('[configured]', (string) ($queue['payload_json']['api_token'] ?? ''));
        $this->assertStringNotContainsString('queue-secret-token', json_encode($queue));

        $attempt = $this->marketing->runExecutionQueueItem($queueId, [
            'attempt_mode' => 'dry_run',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('dry_run', (string) ($attempt['status'] ?? ''));
        $this->assertFalse((bool) ($attempt['result_json']['external_execution'] ?? true));
        $this->assertSame('succeeded', (string) ($this->marketing->getExecutionQueueItem($queueId)['status'] ?? ''));

        $liveQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'email',
            'execution_mode' => 'live',
            'connector_id' => $connectorId,
            'payload' => ['subject' => 'Approved but no adapter'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($liveQueueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved for controlled test.', $this->userId));
        $liveAttempt = $this->marketing->runExecutionQueueItem($liveQueueId, [
            'attempt_mode' => 'live',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('blocked', (string) ($liveAttempt['status'] ?? ''));
        $this->assertContains('live_adapter_not_attached', (array) ($liveAttempt['result_json']['blocked_reasons'] ?? []));
        $this->assertFalse((bool) ($liveAttempt['result_json']['external_execution'] ?? true));

        $response = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($response, 200, 'marketing_channel_exports.php execution queue');
        $this->assertStringContainsString('Controlled Execution Queue', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Email Ready Connector', (string) ($response['body'] ?? ''));
    }

    public function testPhaseFortyOneExecutionQueueWorkspaceAndPermissionLimits(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createExecutionQueueItem([
                'execution_type' => 'sms',
                'created_by' => $this->userId,
            ]);
            $this->fail('Viewer should not create execution queue items.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'sms',
            'execution_mode' => 'dry_run',
            'created_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        try {
            $this->marketing->decideExecutionApproval($approvalId, 'approved', 'Marketing cannot approve.', $this->userId);
            $this->fail('Marketing role should not approve execution queue items.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        $otherWorkspaceId = $this->createWorkspace('Execution Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getExecutionQueueItem($queueId));
        $this->assertSame([], $otherMarketing->listExecutionAttempts(['queue_id' => $queueId]));

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Owner approved.', $this->userId));
        $this->assertSame('approved', (string) ($this->marketing->getExecutionQueueItem($queueId)['status'] ?? ''));
    }

    public function testPhaseFortyTwoBudgetSpendAndRoiPlanning(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Budgeted Campaign');
        $budgetId = $this->marketing->createCampaignBudget([
            'title' => 'Q3 Demand Budget',
            'campaign_id' => $campaignId,
            'budget_period' => 'quarterly',
            'planned_budget' => 5000,
            'committed_budget' => 3000,
            'currency' => 'usd',
            'status' => 'active',
            'created_by' => $this->userId,
            'owner_user_id' => $this->userId,
        ]);
        $spendId = $this->marketing->createChannelSpend([
            'budget_id' => $budgetId,
            'channel' => 'linkedin',
            'spend_date' => date('Y-m-d'),
            'amount' => 1250.50,
            'vendor' => 'Manual ad platform export',
            'created_by' => $this->userId,
        ]);
        $targetId = $this->marketing->createRoiTarget([
            'budget_id' => $budgetId,
            'title' => 'Pipeline ROI Target',
            'target_revenue' => 15000,
            'target_roi_percent' => 200,
            'target_leads' => 120,
            'target_conversions' => 12,
            'status' => 'active',
            'created_by' => $this->userId,
        ]);

        $this->assertGreaterThan(0, $spendId);
        $this->assertGreaterThan(0, $targetId);
        $budget = $this->marketing->getCampaignBudget($budgetId);
        $this->assertSame('Q3 Demand Budget', (string) ($budget['title'] ?? ''));
        $this->assertSame('USD', (string) ($budget['currency'] ?? ''));
        $this->assertSame(1250.50, (float) ($budget['actual_spend'] ?? 0));

        $summary = $this->marketing->getBudgetRoiSummary();
        $this->assertSame(5000.0, (float) ($summary['planned_budget'] ?? 0));
        $this->assertSame(1250.50, (float) ($summary['actual_spend'] ?? 0));
        $this->assertSame(15000.0, (float) ($summary['target_revenue'] ?? 0));
        $this->assertTrue((bool) ($summary['manual_planning_only'] ?? false));
        $this->assertSame('linkedin', (string) ($summary['by_channel'][0]['channel'] ?? ''));

        $dashboard = $this->marketing->getDashboardSummary();
        $this->assertSame(1, (int) ($dashboard['counts']['active_budgets'] ?? 0));
        $this->assertSame(1, (int) ($dashboard['counts']['roi_targets'] ?? 0));

        $response = $this->runWebEndpoint('public/marketing_performance.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($response, 200, 'marketing_performance.php budget ROI');
        $this->assertStringContainsString('Budget And ROI Planning', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Q3 Demand Budget', (string) ($response['body'] ?? ''));
    }

    public function testPhaseFortyTwoBudgetWorkspaceAndPermissionLimits(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createCampaignBudget([
                'title' => 'Viewer Budget',
                'planned_budget' => 100,
                'created_by' => $this->userId,
            ]);
            $this->fail('Viewer should not create campaign budgets.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $budgetId = $this->marketing->createCampaignBudget([
            'title' => 'Workspace Budget',
            'planned_budget' => 100,
            'created_by' => $this->userId,
        ]);
        $otherWorkspaceId = $this->createWorkspace('Budget Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getCampaignBudget($budgetId));
        try {
            $otherMarketing->createChannelSpend([
                'budget_id' => $budgetId,
                'channel' => 'linkedin',
                'spend_date' => date('Y-m-d'),
                'amount' => 10,
                'created_by' => $this->userId,
            ]);
            $this->fail('Cross-workspace budget spend should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Linked record', $e->getMessage());
        }

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseFortyThreeExperimentsVariantsResultsAndWinnerRecommendation(): void
    {
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Experiment Landing',
            'slug' => 'experiment-landing',
            'headline' => 'Test a sharper CTA',
            'created_by' => $this->userId,
        ]);
        $experimentId = $this->marketing->createExperiment([
            'title' => 'Hero CTA Test',
            'experiment_type' => 'landing_page',
            'hypothesis' => 'A direct booking CTA will improve conversion.',
            'success_metric' => 'conversion_rate',
            'landing_page_id' => $landingPageId,
            'status' => 'running',
            'created_by' => $this->userId,
            'owner_user_id' => $this->userId,
        ]);
        $variantA = $this->marketing->createExperimentVariant([
            'experiment_id' => $experimentId,
            'variant_key' => 'a',
            'name' => 'Book a demo',
            'payload' => ['cta' => 'Book a demo'],
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $variantB = $this->marketing->createExperimentVariant([
            'experiment_id' => $experimentId,
            'variant_key' => 'b',
            'name' => 'Get the checklist',
            'payload' => ['cta' => 'Get the checklist'],
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $this->marketing->recordExperimentResult([
            'experiment_id' => $experimentId,
            'variant_id' => $variantA,
            'metric_name' => 'conversion_rate',
            'metric_value' => 0.08,
            'sample_size' => 100,
            'created_by' => $this->userId,
        ]);
        $this->marketing->recordExperimentResult([
            'experiment_id' => $experimentId,
            'variant_id' => $variantB,
            'metric_name' => 'conversion_rate',
            'metric_value' => 0.13,
            'sample_size' => 90,
            'created_by' => $this->userId,
        ]);

        $recommendation = $this->marketing->recommendExperimentWinner($experimentId);
        $this->assertSame($variantB, (int) ($recommendation['winner']['variant_id'] ?? 0));
        $this->assertTrue((bool) ($recommendation['manual_recommendation_only'] ?? false));
        $experiment = $this->marketing->getExperiment($experimentId);
        $this->assertSame('completed', (string) ($experiment['status'] ?? ''));
        $this->assertSame('Get the checklist', (string) ($experiment['winner_variant_name'] ?? ''));

        $dashboard = $this->marketing->getDashboardSummary();
        $this->assertSame(0, (int) ($dashboard['counts']['running_experiments'] ?? 0));

        $response = $this->runWebEndpoint('public/marketing_performance.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($response, 200, 'marketing_performance.php experiments');
        $this->assertStringContainsString('Experiments', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Hero CTA Test', (string) ($response['body'] ?? ''));
    }

    public function testPhaseFortyThreeExperimentsWorkspaceAndPermissionLimits(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createExperiment([
                'title' => 'Viewer Experiment',
                'experiment_type' => 'cta',
                'created_by' => $this->userId,
            ]);
            $this->fail('Viewer should not create experiments.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $experimentId = $this->marketing->createExperiment([
            'title' => 'Workspace Experiment',
            'experiment_type' => 'offer',
            'created_by' => $this->userId,
        ]);
        $otherWorkspaceId = $this->createWorkspace('Experiment Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertNull($otherMarketing->getExperiment($experimentId));
        try {
            $otherMarketing->createExperimentVariant([
                'experiment_id' => $experimentId,
                'variant_key' => 'x',
                'name' => 'Cross workspace variant',
                'created_by' => $this->userId,
            ]);
            $this->fail('Cross-workspace experiment variant should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Linked record', $e->getMessage());
        }

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseFortyFourOperatorReportingExportsReadinessAndAdminPage(): void
    {
        $readiness = $this->marketing->getMarketingOperatorReadiness();
        $this->assertContains((string) ($readiness['status'] ?? ''), ['ready', 'warning']);
        $this->assertNotEmpty($readiness['checks']);
        $this->assertFalse((bool) ($readiness['manual_first_boundary']['external_publish'] ?? true));
        $this->assertStringNotContainsString('super-secret', strtolower(json_encode($readiness)));
        $this->assertGreaterThanOrEqual(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM marketing_operator_readiness_checks WHERE workspace_id = ?",
            [$this->workspaceId]
        )['count'] ?? 0));

        $exportId = $this->marketing->createMarketingReportExport('operator', [
            'report_format' => 'json',
            'period_start' => date('Y-m-d', strtotime('-7 days')),
            'period_end' => date('Y-m-d'),
        ], $this->userId);
        $export = $this->marketing->listMarketingReportExports(['id' => $exportId], 1, 0)[0] ?? [];
        $this->assertSame('operator', (string) ($export['report_type'] ?? ''));
        $this->assertFalse((bool) ($export['payload_json']['manual_first_boundary']['external_send'] ?? true));
        $this->assertStringNotContainsString('secret-token', json_encode($export));

        $draftId = $this->marketing->createScheduledReportDraft([
            'title' => 'Weekly Operator Report',
            'report_type' => 'weekly',
            'cadence' => 'weekly',
            'next_run_at' => date('Y-m-d\TH:i', strtotime('+1 week')),
            'recipients' => 'ops@example.com, marketing@example.com',
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);
        $draft = $this->marketing->listScheduledReportDrafts(['id' => $draftId], 1, 0)[0] ?? [];
        $this->assertSame('Weekly Operator Report', (string) ($draft['title'] ?? ''));
        $this->assertSame(['ops@example.com', 'marketing@example.com'], (array) ($draft['recipients_json'] ?? []));

        $response = $this->runWebEndpoint('public/marketing_admin.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($response, 200, 'marketing_admin.php operator reporting');
        $this->assertStringContainsString('Operator Readiness', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Report Exports', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Scheduled Report Drafts', (string) ($response['body'] ?? ''));
    }

    public function testPhaseFortyFourOperatorReportingWorkspaceAndRoleLimits(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createMarketingReportExport('operator', [], $this->userId);
            $this->fail('Viewer should not create marketing report exports.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->createScheduledReportDraft([
                'title' => 'Marketing role draft',
                'report_type' => 'weekly',
                'created_by' => $this->userId,
            ]);
            $this->fail('Marketing role should not create operator scheduled report drafts.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $exportId = $this->marketing->createMarketingReportExport('budget', [], $this->userId);
        $draftId = $this->marketing->createScheduledReportDraft([
            'title' => 'Workspace Isolated Report',
            'report_type' => 'monthly',
            'cadence' => 'monthly',
            'created_by' => $this->userId,
        ]);
        $this->marketing->getMarketingOperatorReadiness();

        $otherWorkspaceId = $this->createWorkspace('Operator Reporting Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertSame([], $otherMarketing->listMarketingReportExports(['id' => $exportId], 1, 0));
        $this->assertSame([], $otherMarketing->listScheduledReportDrafts(['id' => $draftId], 1, 0));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM marketing_operator_readiness_checks WHERE workspace_id = ?",
            [$otherWorkspaceId]
        )['count'] ?? 0));

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseFortySixConversionTrackingRecordsViewsClicksAndConversions(): void
    {
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Tracked Landing Page',
            'slug' => 'tracked-landing-page-' . uniqid(),
            'headline' => 'Track every useful action',
            'meta_description' => 'A landing page with CRM-native conversion tracking.',
            'body_sections' => [['heading' => 'Plan', 'body' => 'Capture views and conversion intent.']],
            'cta_blocks' => [['heading' => 'Book a review', 'body' => 'Choose a time with the team.']],
            'cta_variants' => [['label' => 'Book a review', 'destination' => 'form']],
            'conversion_goal' => 'demo_request',
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);
        $publication = $this->marketing->publishLandingPage($landingPageId, $this->userId);
        $token = (string) ($publication['public_token'] ?? '');
        $this->assertNotEmpty($token);

        $view = $this->marketing->recordMarketingTrackingEvent([
            'token' => $token,
            'session_key' => 'phase46-session',
            'event_type' => 'page_view',
            'page_url' => 'https://example.com/tracked?utm_source=linkedin&utm_medium=social&utm_campaign=launch',
            'referrer' => 'https://linkedin.com/feed',
        ]);
        $click = $this->marketing->recordMarketingTrackingEvent([
            'token' => $token,
            'session_key' => 'phase46-session',
            'event_type' => 'cta_click',
            'cta_label' => 'Book a review',
            'cta_destination' => 'https://example.com/book',
        ]);
        $conversion = $this->marketing->recordMarketingTrackingEvent([
            'token' => $token,
            'session_key' => 'phase46-session',
            'event_type' => 'conversion',
            'conversion_type' => 'demo_request',
            'conversion_value' => '125.50',
        ]);

        $this->assertSame('page_view', (string) ($view['event_type'] ?? ''));
        $this->assertSame((int) $view['visitor_session_id'], (int) $click['visitor_session_id']);
        $this->assertSame((int) $view['visitor_session_id'], (int) $conversion['visitor_session_id']);
        $summary = $this->marketing->getMarketingTrackingSummary($landingPageId);
        $this->assertSame(1, (int) ($summary['page_views'] ?? 0));
        $this->assertSame(1, (int) ($summary['cta_clicks'] ?? 0));
        $this->assertSame(1, (int) ($summary['conversions'] ?? 0));
        $this->assertSame(100.0, (float) ($summary['conversion_rate'] ?? 0));
        $this->assertContains('linkedin', array_map(static fn(array $row): string => (string) ($row['source'] ?? ''), (array) ($summary['by_source'] ?? [])));
        $this->assertFalse((bool) ($summary['manual_first_boundary']['external_send'] ?? true));

        $public = $this->runEndpointScript('public/marketing_landing_public.php', [
            'method' => 'GET',
            'query' => ['token' => $token],
        ]);
        $this->assertEndpointHealthy($public, 200, 'marketing_landing_public.php tracking');
        $this->assertStringContainsString('marketing_track.php', (string) ($public['body'] ?? ''));
        $this->assertStringContainsString('data-marketing-cta', (string) ($public['body'] ?? ''));

        $endpoint = $this->runEndpointScript('public/marketing_track.php', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'token' => $token,
                'session_key' => 'endpoint-session',
                'event_type' => 'page_view',
                'page_url' => 'https://example.com/tracked?utm_source=email',
            ]),
        ]);
        $this->assertEndpointHealthy($endpoint, 200, 'marketing_track.php');
        $endpointBody = json_decode((string) ($endpoint['body'] ?? ''), true);
        $this->assertTrue((bool) ($endpointBody['ok'] ?? false));
    }

    public function testPhaseFortySixConversionTrackingRejectsInvalidTokensAndStaysWorkspaceScoped(): void
    {
        try {
            $this->marketing->recordMarketingTrackingEvent([
                'token' => 'missing-token',
                'event_type' => 'page_view',
            ]);
            $this->fail('Invalid public tracking token should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', strtolower($e->getMessage()));
        }

        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Workspace Scoped Tracking',
            'slug' => 'workspace-scoped-tracking-' . uniqid(),
            'headline' => 'Keep tracking tenant-safe',
            'body_sections' => [['heading' => 'Scope', 'body' => 'Events remain in the active workspace.']],
            'cta_blocks' => [['heading' => 'Continue', 'body' => 'Review the scope.']],
            'conversion_goal' => 'lead_capture',
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);
        $publication = $this->marketing->publishLandingPage($landingPageId, $this->userId);
        $this->marketing->recordMarketingTrackingEvent([
            'token' => (string) $publication['public_token'],
            'session_key' => 'scoped-session',
            'event_type' => 'page_view',
        ]);
        $this->assertSame(1, (int) ($this->marketing->getMarketingTrackingSummary()['page_views'] ?? 0));

        $otherWorkspaceId = $this->createWorkspace('Tracking Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertSame(0, (int) ($otherMarketing->getMarketingTrackingSummary()['page_views'] ?? 0));

        $invalidEndpoint = $this->runEndpointScript('public/marketing_track.php', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode(['token' => 'bad-token', 'event_type' => 'page_view']),
        ]);
        $this->assertEndpointHealthy($invalidEndpoint, 404, 'marketing_track.php invalid token');
        $this->assertStringNotContainsString('Stack trace', (string) ($invalidEndpoint['body'] ?? ''));

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseFortySevenAttributionPipelineLinksTrackingToRevenueAndCrmRecords(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Attribution Pipeline Campaign');
        $formId = $this->createForm($this->workspaceId, 'Attribution Form');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Attribution Explainer',
            'content_type' => 'blog_post',
            'channel' => 'website',
            'status' => 'approved',
            'campaign_id' => $campaignId,
            'draft_body' => 'A CRM-native conversion path for attribution testing.',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Attribution Landing Page',
            'slug' => 'attribution-landing-page-' . uniqid(),
            'headline' => 'Connect visits to revenue',
            'body_sections' => [['heading' => 'Attribution', 'body' => 'Track influence across the funnel.']],
            'cta_blocks' => [['heading' => 'Request a demo', 'body' => 'Send a tracked conversion.']],
            'cta_variants' => [['label' => 'Request a demo', 'destination' => 'form']],
            'conversion_goal' => 'demo_request',
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);
        $publication = $this->marketing->publishLandingPage($landingPageId, $this->userId);
        $token = (string) ($publication['public_token'] ?? '');

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, lead_source, stage, assigned_to, created_by)
             VALUES (?, UUID(), 'Phase', 'phase47@example.com', 'form', 'qualified', ?, ?)",
            [$this->workspaceId, $this->userId, $this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, description, contact_id, assigned_to, created_by, stage, value, probability, currency, campaign_id)
             VALUES (?, 'Phase 47 Deal', '', ?, ?, ?, 'proposal', 875.25, 70, 'USD', ?)",
            [$this->workspaceId, $contactId, $this->userId, $this->userId, $campaignId]
        );
        $dealId = (int) Database::lastInsertId();

        $this->marketing->recordMarketingTrackingEvent([
            'token' => $token,
            'session_key' => 'phase47-session',
            'event_type' => 'page_view',
            'content_item_id' => $contentId,
            'page_url' => 'https://example.com/attribution?utm_source=google&utm_medium=cpc&utm_campaign=phase47',
        ]);
        $this->marketing->recordMarketingTrackingEvent([
            'token' => $token,
            'session_key' => 'phase47-session',
            'event_type' => 'cta_click',
            'content_item_id' => $contentId,
            'cta_label' => 'Request a demo',
            'cta_destination' => 'https://example.com/demo',
        ]);
        $this->marketing->recordMarketingTrackingEvent([
            'token' => $token,
            'session_key' => 'phase47-session',
            'event_type' => 'conversion',
            'conversion_type' => 'demo_request',
            'content_item_id' => $contentId,
            'contact_id' => $contactId,
            'deal_id' => $dealId,
        ]);

        $summary = $this->marketing->getMarketingAttributionSummary($landingPageId);
        $this->assertSame(4, (int) ($summary['touchpoints'] ?? 0));
        $this->assertSame(1, (int) ($summary['influenced_leads'] ?? 0));
        $this->assertSame(1, (int) ($summary['influenced_deals'] ?? 0));
        $this->assertSame(875.25, (float) ($summary['influenced_revenue'] ?? 0));
        $this->assertSame(1, (int) ($summary['conversion_touchpoints'] ?? 0));
        $this->assertSame(1, (int) ($summary['revenue_touchpoints'] ?? 0));
        $this->assertContains('Attribution Pipeline Campaign', array_map(static fn(array $row): string => (string) ($row['campaign_name'] ?? ''), (array) ($summary['by_campaign'] ?? [])));
        $this->assertContains('Attribution Explainer', array_map(static fn(array $row): string => (string) ($row['content_title'] ?? ''), (array) ($summary['by_content'] ?? [])));
        $this->assertContains('google', array_map(static fn(array $row): string => (string) ($row['source'] ?? ''), (array) ($summary['by_source'] ?? [])));

        $firstTouch = Database::queryOne(
            "SELECT contact_id, deal_id
             FROM marketing_attribution_touchpoints
             WHERE workspace_id = ? AND visitor_session_id = ? AND touchpoint_type = 'first_touch'
             LIMIT 1",
            [$this->workspaceId, (int) ($summary['recent_touchpoints'][0]['visitor_session_id'] ?? 0)]
        );
        $this->assertSame($contactId, (int) ($firstTouch['contact_id'] ?? 0));
        $this->assertSame($dealId, (int) ($firstTouch['deal_id'] ?? 0));

        $performance = $this->runWebEndpoint('public/marketing_performance.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($performance, 200, 'marketing_performance.php attribution');
        $this->assertStringContainsString('Attribution Pipeline', (string) ($performance['body'] ?? ''));
        $this->assertStringContainsString('Influenced Leads', (string) ($performance['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Attribution Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherSummary = (new Marketing())->getMarketingAttributionSummary();
        $this->assertSame(0, (int) ($otherSummary['touchpoints'] ?? 0));
        $this->assertSame(0.0, (float) ($otherSummary['influenced_revenue'] ?? 0));

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseFortyNineLeadHandoffCreatesSalesTaskFromConversion(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (UUID(), 'handoff-sales@example.com', ?, 'sales', NOW())",
            [password_hash('password', PASSWORD_DEFAULT)]
        );
        $salesUserId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'sales', 'active', 0, NOW(), ?)",
            [$this->workspaceId, $salesUserId, $this->userId]
        );
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $salesUserId, 'sales', $this->userId);

        $campaignId = $this->createCampaign($this->workspaceId, 'Handoff Campaign');
        $formId = $this->createForm($this->workspaceId, 'Handoff Form');
        $goalId = $this->marketing->createConversionGoal([
            'title' => 'Sales demo handoff',
            'goal_type' => 'demo_request',
            'success_metric' => 'qualified_handoff',
            'tracking_source' => 'landing_page',
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Handoff Content',
            'content_type' => 'ad_copy',
            'channel' => 'linkedin',
            'status' => 'approved',
            'campaign_id' => $campaignId,
            'conversion_goal_id' => $goalId,
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Handoff Landing',
            'slug' => 'handoff-landing-' . uniqid(),
            'headline' => 'Book a sales review',
            'meta_description' => 'Capture a conversion and hand it to sales.',
            'body_sections' => [['heading' => 'Review', 'body' => 'Connect the interested lead to sales.']],
            'cta_blocks' => [['heading' => 'Book', 'body' => 'Request a demo.']],
            'cta_variants' => [['label' => 'Request demo', 'destination' => 'form']],
            'conversion_goal' => 'demo_request',
            'conversion_goal_id' => $goalId,
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);
        $ruleId = $this->marketing->createHandoffRule([
            'name' => 'Demo requests to sales',
            'status' => 'active',
            'source_type' => 'conversion_goal',
            'conversion_goal_id' => $goalId,
            'assigned_to' => $salesUserId,
            'priority' => 'urgent',
            'sla_minutes' => 60,
            'task_title_template' => 'Follow up {lead} for {goal}',
            'created_by' => $this->userId,
        ]);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, lead_source, stage, created_by)
             VALUES (?, UUID(), 'Sales', 'handoff-lead@example.com', 'landing_page', 'new', ?)",
            [$this->workspaceId, $this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, description, contact_id, created_by, stage, value, probability, currency, campaign_id)
             VALUES (?, 'Handoff Deal', '', ?, ?, 'new', 1500, 25, 'USD', ?)",
            [$this->workspaceId, $contactId, $this->userId, $campaignId]
        );
        $dealId = (int) Database::lastInsertId();

        $publication = $this->marketing->publishLandingPage($landingPageId, $this->userId);
        $conversion = $this->marketing->recordMarketingTrackingEvent([
            'token' => (string) $publication['public_token'],
            'session_key' => 'phase49-handoff-session',
            'event_type' => 'conversion',
            'conversion_type' => 'demo_request',
            'content_item_id' => $contentId,
            'contact_id' => $contactId,
            'deal_id' => $dealId,
            'conversion_goal_id' => $goalId,
            'page_url' => 'https://example.com/handoff?utm_source=linkedin&utm_medium=paid&utm_campaign=handoff',
        ]);

        $conversionEventId = (int) ($conversion['conversion_event_id'] ?? 0);
        $this->assertGreaterThan(0, $conversionEventId);
        $handoff = $this->marketing->createLeadHandoffFromConversionEvent($conversionEventId);
        $this->assertNotNull($handoff);
        $this->assertSame($ruleId, (int) ($handoff['handoff_rule_id'] ?? 0));
        $this->assertSame('assigned', (string) ($handoff['status'] ?? ''));
        $this->assertSame('urgent', (string) ($handoff['priority'] ?? ''));
        $this->assertSame($salesUserId, (int) ($handoff['assigned_to'] ?? 0));
        $this->assertGreaterThan(0, (int) ($handoff['task_id'] ?? 0));

        $task = Database::queryOne(
            "SELECT title, contact_id, assigned_to, priority, due_date, metadata_json
             FROM tasks
             WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, (int) $handoff['task_id']]
        );
        $this->assertNotNull($task);
        $this->assertStringContainsString('handoff-lead@example.com', (string) ($task['title'] ?? ''));
        $this->assertSame($contactId, (int) ($task['contact_id'] ?? 0));
        $this->assertSame($salesUserId, (int) ($task['assigned_to'] ?? 0));
        $this->assertSame('urgent', (string) ($task['priority'] ?? ''));
        $this->assertNotEmpty($task['due_date']);

        $contact = Database::queryOne("SELECT assigned_to FROM contacts WHERE workspace_id = ? AND id = ?", [$this->workspaceId, $contactId]);
        $deal = Database::queryOne("SELECT assigned_to FROM deals WHERE workspace_id = ? AND id = ?", [$this->workspaceId, $dealId]);
        $this->assertSame($salesUserId, (int) ($contact['assigned_to'] ?? 0));
        $this->assertSame($salesUserId, (int) ($deal['assigned_to'] ?? 0));

        $summary = $this->marketing->getLeadHandoffSummary();
        $this->assertSame(1, (int) ($summary['open'] ?? 0));
        $this->assertSame(1, (int) ($summary['assigned'] ?? 0));
        $this->assertSame(0, (int) ($summary['overdue'] ?? 0));

        $duplicate = $this->marketing->createLeadHandoffFromConversionEvent($conversionEventId);
        $this->assertSame((int) ($handoff['id'] ?? 0), (int) ($duplicate['id'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM marketing_lead_handoffs WHERE workspace_id = ? AND conversion_event_id = ?",
            [$this->workspaceId, $conversionEventId]
        )['count'] ?? 0));

        $response = $this->runWebEndpoint('public/marketing_handoffs.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($response, 200, 'marketing_handoffs.php');
        $this->assertStringContainsString('Lead Handoffs', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('handoff-lead@example.com', (string) ($response['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Handoff Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertSame(0, (int) ($otherMarketing->getLeadHandoffSummary()['open'] ?? 0));
        $this->assertNull($otherMarketing->getLeadHandoff((int) ($handoff['id'] ?? 0)));

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseFiftyLeadHandoffSyncsCrmTimelineAndNotifications(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (UUID(), 'handoff-sync-sales@example.com', ?, 'sales', NOW())",
            [password_hash('password', PASSWORD_DEFAULT)]
        );
        $salesUserId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'sales', 'active', 0, NOW(), ?)",
            [$this->workspaceId, $salesUserId, $this->userId]
        );
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $salesUserId, 'sales', $this->userId);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, lead_source, stage, created_by)
             VALUES (?, UUID(), 'Sync', 'Lead', 'sync-lead@example.com', 'marketing', 'new', ?)",
            [$this->workspaceId, $this->userId]
        );
        $contactId = (int) Database::lastInsertId();

        $handoffId = $this->marketing->createLeadHandoff([
            'contact_id' => $contactId,
            'assigned_to' => $salesUserId,
            'status' => 'new',
            'priority' => 'high',
            'source' => 'manual_marketing_review',
            'handoff_note' => 'Follow up from a marketing-qualified inquiry.',
            'created_by' => $this->userId,
        ]);
        $handoff = $this->marketing->getLeadHandoff($handoffId);
        $this->assertNotNull($handoff);
        $this->assertSame('synced', (string) ($handoff['crm_sync_status'] ?? ''));
        $this->assertGreaterThan(0, (int) ($handoff['crm_activity_id'] ?? 0));
        $this->assertGreaterThan(0, (int) ($handoff['notification_id'] ?? 0));

        $activity = Database::queryOne(
            "SELECT activity_type, description, metadata
             FROM activities
             WHERE workspace_id = ? AND id = ? AND contact_id = ?",
            [$this->workspaceId, (int) $handoff['crm_activity_id'], $contactId]
        );
        $this->assertNotNull($activity);
        $this->assertSame('marketing_handoff', (string) ($activity['activity_type'] ?? ''));
        $this->assertStringContainsString('Marketing handoff', (string) ($activity['description'] ?? ''));
        $activityMetadata = json_decode((string) ($activity['metadata'] ?? '{}'), true);
        $this->assertSame($handoffId, (int) ($activityMetadata['marketing_lead_handoff_id'] ?? 0));

        $notification = Database::queryOne(
            "SELECT type, user_id, title, link
             FROM notifications
             WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, (int) $handoff['notification_id']]
        );
        $this->assertNotNull($notification);
        $this->assertSame('marketing_handoff', (string) ($notification['type'] ?? ''));
        $this->assertSame($salesUserId, (int) ($notification['user_id'] ?? 0));
        $this->assertStringContainsString('marketing_handoffs.php', (string) ($notification['link'] ?? ''));

        $events = $this->marketing->listLeadHandoffCrmSyncEvents(['lead_handoff_id' => $handoffId]);
        $this->assertCount(2, $events);
        $this->assertContains('activity', array_map(static fn(array $event): string => (string) $event['sync_type'], $events));
        $this->assertContains('notification', array_map(static fn(array $event): string => (string) $event['sync_type'], $events));

        $resync = $this->marketing->syncLeadHandoffToCrm($handoffId);
        $this->assertSame('synced', (string) ($resync['status'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM activities WHERE workspace_id = ? AND contact_id = ? AND activity_type = 'marketing_handoff'",
            [$this->workspaceId, $contactId]
        )['count'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM notifications WHERE workspace_id = ? AND user_id = ? AND type = 'marketing_handoff'",
            [$this->workspaceId, $salesUserId]
        )['count'] ?? 0));

        $response = $this->runWebEndpoint('public/marketing_handoffs.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($response, 200, 'marketing_handoffs.php CRM sync');
        $this->assertStringContainsString('CRM sync', (string) ($response['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Handoff Sync Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertSame([], $otherMarketing->listLeadHandoffCrmSyncEvents(['lead_handoff_id' => $handoffId]));

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseFiftyOneCrmRecordPagesShowMarketingHandoffs(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'CRM Visibility Campaign');
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, lead_source, stage, created_by)
             VALUES (?, UUID(), 'CRM', 'Visibility', 'crm-visibility@example.com', 'landing_page', 'qualified', ?)",
            [$this->workspaceId, $this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, description, contact_id, assigned_to, created_by, stage, value, probability, currency, campaign_id)
             VALUES (?, 'CRM Visibility Deal', 'Marketing handoff visibility deal.', ?, ?, ?, 'proposal', 2400.00, 65, 'USD', ?)",
            [$this->workspaceId, $contactId, $this->userId, $this->userId, $campaignId]
        );
        $dealId = (int) Database::lastInsertId();

        $handoffId = $this->marketing->createLeadHandoff([
            'campaign_id' => $campaignId,
            'contact_id' => $contactId,
            'deal_id' => $dealId,
            'status' => 'assigned',
            'priority' => 'high',
            'assigned_to' => $this->userId,
            'source' => 'landing_page',
            'handoff_note' => 'Sales should see this marketing handoff from the CRM record.',
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $handoffId);

        $contactHandoffs = $this->marketing->listLeadHandoffsForCrmRecord('contact', $contactId);
        $dealHandoffs = $this->marketing->listLeadHandoffsForCrmRecord('deal', $dealId);
        $this->assertCount(1, $contactHandoffs);
        $this->assertCount(1, $dealHandoffs);
        $this->assertSame($handoffId, (int) ($contactHandoffs[0]['id'] ?? 0));
        $this->assertSame($handoffId, (int) ($dealHandoffs[0]['id'] ?? 0));

        $contactSummary = $this->marketing->getLeadHandoffCrmRecordSummary('contact', $contactId);
        $dealSummary = $this->marketing->getLeadHandoffCrmRecordSummary('deal', $dealId);
        $this->assertSame(1, (int) ($contactSummary['total'] ?? 0));
        $this->assertSame(1, (int) ($contactSummary['assigned'] ?? 0));
        $this->assertSame(1, (int) ($dealSummary['total'] ?? 0));

        $contactPage = $this->runWebEndpoint('public/contact_view.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['id' => $contactId],
        ]);
        $this->assertEndpointHealthy($contactPage, 200, 'contact_view.php marketing handoffs');
        $this->assertStringContainsString('Marketing Handoffs', (string) ($contactPage['body'] ?? ''));
        $this->assertStringContainsString('CRM Visibility Campaign', (string) ($contactPage['body'] ?? ''));

        $dealPage = $this->runWebEndpoint('public/deal_view.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['id' => $dealId],
        ]);
        $this->assertEndpointHealthy($dealPage, 200, 'deal_view.php marketing handoffs');
        $this->assertStringContainsString('Marketing Handoffs', (string) ($dealPage['body'] ?? ''));
        $this->assertStringContainsString('CRM Visibility Campaign', (string) ($dealPage['body'] ?? ''));

        $filteredHandoffs = $this->runWebEndpoint('public/marketing_handoffs.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['contact_id' => $contactId],
        ]);
        $this->assertEndpointHealthy($filteredHandoffs, 200, 'marketing_handoffs.php contact filter');
        $this->assertStringContainsString('CRM record filter: contact #' . $contactId, (string) ($filteredHandoffs['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('CRM Handoff Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->listLeadHandoffsForCrmRecord('contact', $contactId);
            $this->fail('Cross-workspace contact handoff lookup should fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', $e->getMessage());
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }
    }

    public function testPhaseFiftyTwoSalesFeedbackUpdatesHandoffQualityLoop(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (UUID(), 'phase52-sales@example.com', ?, 'sales', NOW())",
            [password_hash('password', PASSWORD_DEFAULT)]
        );
        $salesUserId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'sales', 'active', 0, NOW(), ?)",
            [$this->workspaceId, $salesUserId, $this->userId]
        );
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $salesUserId, 'sales', $this->userId);

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (UUID(), 'phase52-other-sales@example.com', ?, 'sales', NOW())",
            [password_hash('password', PASSWORD_DEFAULT)]
        );
        $otherSalesUserId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'sales', 'active', 0, NOW(), ?)",
            [$this->workspaceId, $otherSalesUserId, $this->userId]
        );
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $otherSalesUserId, 'sales', $this->userId);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, lead_source, stage, created_by)
             VALUES (?, UUID(), 'Feedback', 'Lead', 'phase52-lead@example.com', 'marketing', 'new', ?)",
            [$this->workspaceId, $this->userId]
        );
        $contactId = (int) Database::lastInsertId();

        $handoffId = $this->marketing->createLeadHandoff([
            'contact_id' => $contactId,
            'assigned_to' => $salesUserId,
            'status' => 'assigned',
            'priority' => 'high',
            'source' => 'manual_marketing_review',
            'handoff_note' => 'Phase 52 sales feedback loop lead.',
            'created_by' => $this->userId,
        ]);

        Session::set('user_id', $salesUserId);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $salesUserId, 'sales');
        $salesMarketing = new Marketing();
        $accepted = $salesMarketing->recordLeadHandoffFeedback($handoffId, 'accepted', [
            'feedback_reason' => 'Good-fit inquiry',
            'feedback_note' => 'Sales accepted the lead for same-day follow-up.',
        ]);
        $this->assertSame('accepted', (string) ($accepted['status'] ?? ''));
        $this->assertSame('accepted', (string) ($accepted['sales_outcome'] ?? ''));
        $this->assertSame('Good-fit inquiry', (string) ($accepted['feedback_reason'] ?? ''));
        $this->assertSame($salesUserId, (int) ($accepted['feedback_by'] ?? 0));
        $this->assertNotEmpty($accepted['accepted_at'] ?? null);
        $this->assertNotEmpty($accepted['feedback_at'] ?? null);

        $lost = $salesMarketing->recordLeadHandoffFeedback($handoffId, 'lost', [
            'feedback_reason' => 'No budget this quarter',
        ]);
        $this->assertSame('lost', (string) ($lost['status'] ?? ''));
        $this->assertSame('lost', (string) ($lost['sales_outcome'] ?? ''));
        $this->assertSame('No budget this quarter', (string) ($lost['feedback_reason'] ?? ''));
        $this->assertNotEmpty($lost['lost_at'] ?? null);

        $events = $salesMarketing->listLeadHandoffFeedbackEvents(['lead_handoff_id' => $handoffId]);
        $this->assertCount(2, $events);
        $this->assertSame(['lost', 'accepted'], array_map(static fn(array $event): string => (string) $event['outcome'], $events));

        Session::set('user_id', $otherSalesUserId);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $otherSalesUserId, 'sales');
        try {
            (new Marketing())->recordLeadHandoffFeedback($handoffId, 'qualified', ['feedback_reason' => 'Not my handoff']);
            $this->fail('Unassigned sales users should not update handoff feedback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Session::set('user_id', $this->userId);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        $summary = (new Marketing())->getLeadHandoffSummary();
        $this->assertSame(1, (int) ($summary['lost'] ?? 0));
        $this->assertSame(1, (int) ($summary['feedback_total'] ?? 0));

        $response = $this->runWebEndpoint('public/marketing_handoffs.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['status' => 'lost'],
        ]);
        $this->assertEndpointHealthy($response, 200, 'marketing_handoffs.php feedback loop');
        $this->assertStringContainsString('Sales Feedback', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('No budget this quarter', (string) ($response['body'] ?? ''));
    }

    public function testPhaseFortyEightConversionGoalsManageReadinessLinksAndLandingAssociations(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Goal Campaign');
        $formId = $this->createForm($this->workspaceId, 'Goal Form');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Goal Content',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'draft',
            'objective' => 'Drive booked consultations.',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Goal Landing',
            'slug' => 'goal-landing-' . uniqid(),
            'headline' => 'Set a goal before publishing',
            'body_sections' => [['heading' => 'Goal', 'body' => 'Define success before measuring it.']],
            'cta_blocks' => [['heading' => 'Book', 'body' => 'Request a consultation.']],
            'conversion_goal' => 'booking',
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);

        $goalId = $this->marketing->createConversionGoal([
            'title' => 'Booked consultations',
            'goal_type' => 'booking',
            'success_metric' => 'booked_consultations',
            'target_count' => '12',
            'target_value' => '6000',
            'tracking_source' => 'landing_page',
            'status' => 'active',
            'starts_at' => date('Y-m-d'),
            'campaign_id' => $campaignId,
            'landing_page_id' => $landingPageId,
            'form_id' => $formId,
            'content_item_id' => $contentId,
            'created_by' => $this->userId,
        ]);

        $goal = $this->marketing->getConversionGoal($goalId);
        $this->assertSame('Booked consultations', (string) ($goal['title'] ?? ''));
        $this->assertSame('booking', (string) ($goal['goal_type'] ?? ''));
        $this->assertGreaterThanOrEqual(75, (int) ($goal['readiness_score'] ?? 0));
        $this->assertCount(4, (array) ($goal['links'] ?? []));
        $this->assertSame($goalId, (int) (Database::queryOne(
            "SELECT conversion_goal_id FROM marketing_landing_pages WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $landingPageId]
        )['conversion_goal_id'] ?? 0));
        $this->assertSame($goalId, (int) (Database::queryOne(
            "SELECT conversion_goal_id FROM marketing_content_items WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $contentId]
        )['conversion_goal_id'] ?? 0));

        $summary = $this->marketing->getConversionGoalSummary();
        $this->assertSame(1, (int) ($summary['active'] ?? 0));
        $this->assertSame(0, (int) ($summary['missing_links'] ?? 0));
        $this->assertContains('Booked consultations', array_map(static fn(array $row): string => (string) ($row['title'] ?? ''), (array) ($summary['goals'] ?? [])));

        $this->marketing->updateLandingPage($landingPageId, [
            'conversion_goal_id' => $goalId,
            'created_by' => $this->userId,
        ]);
        $page = $this->marketing->getLandingPage($landingPageId);
        $this->assertSame($goalId, (int) ($page['conversion_goal_id'] ?? 0));
        $this->assertSame('Booked consultations', (string) ($page['conversion_goal_title'] ?? ''));
        $readiness = $this->marketing->getLandingPagePublishingReadiness($landingPageId);
        $this->assertContains('hero_media_recommended', (array) ($readiness['media_warnings'] ?? []));
        $this->assertNotContains('conversion_goal_record_recommended', (array) ($readiness['builder_warnings'] ?? []));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createConversionGoal(['title' => 'Viewer Goal']);
            $this->fail('Viewer should not create conversion goals.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);

        $otherWorkspaceId = $this->createWorkspace('Goal Other Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertSame(0, (int) ($otherMarketing->getConversionGoalSummary()['total'] ?? 0));
        try {
            $otherMarketing->linkConversionGoal($goalId, 'landing_page', $landingPageId, $this->userId);
            $this->fail('Cross-workspace conversion goal linking should fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', strtolower($e->getMessage()));
        }

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        $performance = $this->runWebEndpoint('public/marketing_performance.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($performance, 200, 'marketing_performance.php conversion goals');
        $this->assertStringContainsString('Conversion Goals', (string) ($performance['body'] ?? ''));
        $this->assertStringContainsString('Add Conversion Goal', (string) ($performance['body'] ?? ''));
    }

    public function testLandingPageAiCopyDraftUsesPromptJsonForReviewOnly(): void
    {
        $ai = new class extends AIService {
            public string $promptKey = '';

            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                $this->promptKey = $promptKey;
                return [
                    'surface' => $surface,
                    'prompt_key' => $promptKey,
                    'prompt_version' => 11,
                    'rendered_prompt' => 'landing page prompt',
                    'context_bundle' => $bundle,
                ];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return json_encode([
                    'headline' => 'Turn follow-up into booked demos',
                    'seo_title' => 'Booked Demo Landing Page',
                    'meta_description' => 'A landing page draft for operations teams that need cleaner follow-up.',
                    'body_sections' => [
                        ['heading' => 'Fix the handoff', 'body' => 'Show the current gap and the practical path forward.'],
                    ],
                    'cta_blocks' => [
                        ['heading' => 'Book the audit', 'body' => 'Choose a demo slot that works for your team.'],
                    ],
                    'proof_blocks' => [
                        ['heading' => 'Operational proof', 'body' => 'Use only approved proof points from the CRM.'],
                    ],
                    'faq_blocks' => [
                        ['question' => 'What happens next?', 'answer' => 'A human reviews the request and follows up.'],
                    ],
                    'thank_you_copy' => 'Thanks. We will review your request and reply shortly.',
                ]);
            }

            public function getLastProviderStatus(): array
            {
                return ['success' => true, 'mode' => 'fake_ai'];
            }
        };
        $marketing = new Marketing(null, $ai);

        $draft = $marketing->generateLandingPageCopyDraft([
            'title' => 'AI Landing',
            'conversion_goal' => 'demo_request',
        ]);

        $this->assertSame('landing_page_copy', $ai->promptKey);
        $this->assertSame('Turn follow-up into booked demos', $draft['fields']['headline']);
        $this->assertStringContainsString('Fix the handoff', $draft['fields']['body_sections']);
        $this->assertSame('What happens next?', $draft['structured_fields']['faq_blocks'][0]['heading']);
        $this->assertSame(11, (int) $draft['ai_context']['prompt_version']);
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS count FROM marketing_landing_pages WHERE workspace_id = ?", [$this->workspaceId])['count'] ?? 0));
    }

    public function testLandingPageEditorGeneratesReviewableCopyWithoutSaving(): void
    {
        $response = $this->runWebEndpoint('public/marketing_landing_page_edit.php', $this->webSession('owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-marketing',
                'id' => '',
                'action' => 'generate_landing_copy',
                'title' => 'Endpoint AI Landing',
                'slug' => '',
                'headline' => '',
                'seo_title' => '',
                'meta_description' => '',
                'body_sections' => '',
                'cta_blocks' => '',
                'proof_blocks' => '',
                'faq_blocks' => '',
                'thank_you_copy' => '',
                'conversion_goal' => 'demo_request',
                'status' => 'draft',
            ],
        ]);

        $this->assertEndpointHealthy($response, 200, 'marketing_landing_page_edit.php generate');
        $this->assertStringContainsString('AI landing page copy generated', (string) ($response['body'] ?? ''));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS count FROM marketing_landing_pages WHERE workspace_id = ? AND title = 'Endpoint AI Landing'", [$this->workspaceId])['count'] ?? 0));
    }

    public function testPhaseSixteenLandingPagePublishAndUnpublishWorkflow(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Public Landing Campaign');
        $formId = $this->createForm($this->workspaceId, 'Public Landing Form');
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Publishable Landing Page',
            'slug' => 'publishable-landing-page',
            'headline' => 'Launch a better workflow',
            'meta_description' => 'A self-hosted tokenized landing page.',
            'body_sections' => [['heading' => 'Why now', 'body' => 'This page is ready for a manual campaign launch.']],
            'cta_blocks' => [['heading' => 'Book a demo', 'body' => 'Choose a time that works for your team.']],
            'conversion_goal' => 'demo_request',
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);

        $readiness = $this->marketing->getLandingPagePublishingReadiness($landingPageId);
        $this->assertSame(100, (int) $readiness['score']);
        $this->assertSame([], $readiness['missing']);

        $publication = $this->marketing->publishLandingPage($landingPageId, $this->userId);
        $this->assertSame('published', $publication['status']);
        $this->assertSame('demo_request', $publication['conversion_goal']);
        $this->assertNotEmpty($publication['public_token']);
        $this->assertStringContainsString('marketing_landing_public.php?token=', $publication['public_url']);

        $publicPage = $this->marketing->getPublishedLandingPageByToken((string) $publication['public_token']);
        $this->assertNotNull($publicPage);
        $this->assertSame('Publishable Landing Page', $publicPage['title']);

        $response = $this->runEndpointScript('public/marketing_landing_public.php', [
            'method' => 'GET',
            'query' => ['token' => (string) $publication['public_token']],
        ]);
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Launch a better workflow', (string) ($response['body'] ?? ''));

        $this->assertTrue($this->marketing->unpublishLandingPage($landingPageId, $this->userId));
        $this->assertNull($this->marketing->getPublishedLandingPageByToken((string) $publication['public_token']));
    }

    public function testDesignStudioUsesOptimisticDraftsImmutablePublishedSnapshotsAndRollback(): void
    {
        $formId = $this->createForm($this->workspaceId, 'Design Studio Conversion Form');
        $design = new LandingPageDesignService();
        $document = $design->templates()[0]['document'];
        foreach ($document['blocks'] as &$block) {
            if (($block['type'] ?? '') === 'crm_form') {
                $block['props']['form_id'] = $formId;
            }
        }
        unset($block);
        $document['blocks'][] = [
            'id' => 'cta_checkpoint',
            'type' => 'cta',
            'props' => ['heading' => 'Ready to continue?', 'body' => 'Send the brief.', 'button_label' => 'Contact us', 'button_href' => '#contact'],
            'style' => ['alignment' => 'center', 'padding' => 'normal', 'background' => 'transparent', 'max_width' => 1180],
            'visibility' => ['hide_desktop' => false, 'hide_tablet' => false, 'hide_mobile' => false],
        ];

        $pageId = $this->marketing->createLandingPage([
            'title' => 'Design Snapshot Page',
            'slug' => 'design-snapshot-page',
            'seo_title' => 'Design Snapshot Page',
            'meta_description' => 'A production Design Studio publication test.',
            'form_id' => $formId,
            'conversion_goal' => 'lead_capture',
            'status' => 'draft',
            'design_document' => $document,
            'created_by' => $this->userId,
        ]);

        $draftReadiness = $this->marketing->getLandingPagePublishingReadiness($pageId);
        $this->assertContains('approved_status', (array) ($draftReadiness['missing'] ?? []));

        $editor = $this->marketing->getLandingPageDesignEditorData($pageId);
        $document = $editor['document'];
        $document['blocks'][0]['props']['heading'] = 'Published conversion headline';
        $saved = $this->marketing->saveLandingPageDesign($pageId, $document, ['title' => 'Design Snapshot Page'], (int) $editor['revision'], $this->userId);
        $publication = $this->marketing->publishLandingPage($pageId, $this->userId);
        $publishedPageRecord = $this->marketing->getLandingPage($pageId);
        $publishedVersionId = (int) ($publishedPageRecord['published_version_id'] ?? 0);
        $this->assertSame('approved', (string) ($publishedPageRecord['status'] ?? ''));
        $this->assertGreaterThan(0, $publishedVersionId);

        $draft = $saved['document'];
        $draft['blocks'][0]['props']['heading'] = 'Unpublished draft headline';
        $draftSaved = $this->marketing->saveLandingPageDesign($pageId, $draft, [], (int) $saved['revision'], $this->userId);
        $this->assertSame('Unpublished draft headline', $draftSaved['document']['blocks'][0]['props']['heading']);

        $published = $this->marketing->getPublishedLandingPageByToken((string) $publication['public_token']);
        $this->assertSame('Published conversion headline', $published['design_document_json']['blocks'][0]['props']['heading']);
        $this->assertSame('Unpublished draft headline', $this->marketing->getLandingPage($pageId)['design_document_json']['blocks'][0]['props']['heading']);

        try {
            $this->marketing->saveLandingPageDesign($pageId, $draft, [], (int) $saved['revision'], $this->userId);
            $this->fail('A stale Design Studio revision should not overwrite a newer draft.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('changed in another session', $e->getMessage());
        }

        $restored = $this->marketing->restoreLandingPageDesignVersion(
            $pageId,
            $publishedVersionId,
            (int) $draftSaved['revision'],
            $this->userId
        );
        $this->assertSame('Published conversion headline', $restored['document']['blocks'][0]['props']['heading']);
        $this->assertGreaterThan((int) $draftSaved['revision'], (int) $restored['revision']);
    }

    public function testPhaseSixteenLandingPublishingRequiresReadinessAndManagePermission(): void
    {
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Not Ready Page',
            'slug' => 'not-ready-page',
            'headline' => 'Not ready yet',
            'status' => 'draft',
        ]);

        try {
            $this->marketing->publishLandingPage($landingPageId, $this->userId);
            $this->fail('Draft landing page should not publish.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not ready to publish', $e->getMessage());
        }

        $readyId = $this->marketing->createLandingPage([
            'title' => 'Manage Only Publish',
            'slug' => 'manage-only-publish',
            'headline' => 'Ready headline',
            'body_sections' => [['heading' => 'Section', 'body' => 'Body']],
            'cta_blocks' => [['heading' => 'CTA', 'body' => 'Act now']],
            'conversion_goal' => 'lead_capture',
            'status' => 'approved',
        ]);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $this->expectExceptionMessage('You do not have permission to perform this design action.');
        $this->marketing->publishLandingPage($readyId, $this->userId);
    }

    public function testPhaseSeventeenManualEmailCampaignRunExport(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Email Run Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Email Run Source',
            'content_type' => 'email',
            'channel' => 'email',
            'draft_body' => 'Email body from content studio.',
            'created_by' => $this->userId,
        ]);

        $runId = $this->marketing->createEmailCampaignRun([
            'name' => 'May Product Email',
            'content_item_id' => $contentId,
            'campaign_id' => $campaignId,
            'subject' => 'See the new workflow',
            'preview_text' => 'A short practical update.',
            'segment_name' => 'Trial users',
            'segment_criteria' => 'Contacts tagged trial',
            'recipient_count' => 42,
            'send_checklist' => "Proofread\nConfirm suppression list",
            'status' => 'ready',
            'created_by' => $this->userId,
        ]);

        $run = $this->marketing->getEmailCampaignRun($runId);
        $this->assertSame('ready', $run['status']);
        $this->assertSame('Email body from content studio.', $run['body_snapshot']);
        $this->assertSame('manual', $run['segment_snapshot_json']['source']);
        $this->assertSame(['Proofread', 'Confirm suppression list'], $run['send_checklist_json']);

        $bundle = $this->marketing->getEmailCampaignExportBundle($runId);
        $this->assertTrue((bool) $bundle['no_external_send']);
        $this->assertSame(42, $bundle['recipient_count']);

        $exported = $this->marketing->markEmailCampaignRunExported($runId);
        $this->assertSame('exported', $exported['status']);
        $this->assertTrue((bool) $exported['export_bundle_json']['no_external_send']);
        $this->assertTrue((bool) $exported['performance_json']['manual_exported']);
    }

    public function testPhaseSeventeenEmailRunsValidateWorkspaceLinksAndPageRenders(): void
    {
        $contentId = $this->marketing->createContentItem(['title' => 'Local Email Source']);
        $otherWorkspaceId = $this->createWorkspace('Other Email Run Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();

        try {
            $otherMarketing->createEmailCampaignRun([
                'name' => 'Invalid Linked Run',
                'content_item_id' => $contentId,
            ]);
            $this->fail('Cross-workspace content should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Linked record was not found in the active workspace.', $e->getMessage());
        }

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        $response = $this->runWebEndpoint('public/marketing_email_runs.php', $this->webSession(), ['method' => 'GET']);
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Marketing Email Runs', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('No marketing email runs match this view', (string) ($response['body'] ?? ''));
    }

    public function testPhaseThirtySixManualEmailReadinessAndCsvExportPlan(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, lead_source, stage, engagement_score)
             VALUES (?, UUID(), 'Cora', 'Customer', 'cora@example.com', 'form', 'qualified', 88)",
            [$this->workspaceId]
        );
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Email Export Audience',
            'status' => 'active',
            'rules' => [
                ['source_type' => 'contact', 'field_key' => 'email', 'operator' => 'contains', 'value_text' => '@example.com'],
            ],
            'created_by' => $this->userId,
        ]);
        $snapshot = $this->marketing->createAudienceSegmentSnapshot($segmentId, $this->userId);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Manual Email Content',
            'content_type' => 'email',
            'channel' => 'email',
            'draft_body' => 'Email body with a clear call to action.',
            'created_by' => $this->userId,
        ]);
        $consentPolicyId = $this->marketing->createConsentPolicy([
            'policy_name' => 'Email Opt-In Policy',
            'channel' => 'email',
            'consent_basis' => 'explicit_opt_in',
            'requires_unsubscribe' => 1,
            'requires_suppression_check' => 1,
            'created_by' => $this->userId,
        ]);

        $runId = $this->marketing->createEmailCampaignRun([
            'name' => 'Readiness Email Run',
            'content_item_id' => $contentId,
            'audience_segment_id' => $segmentId,
            'segment_snapshot_id' => (int) $snapshot['id'],
            'consent_policy_id' => $consentPolicyId,
            'suppression_list_checked' => 1,
            'subject' => 'Your next best step',
            'preheader_text' => 'A short supporting line',
            'cta_label' => 'Book a session',
            'cta_url' => 'https://example.com/book',
            'unsubscribe_text' => 'Unsubscribe placeholder for manual send.',
            'suppression_notes' => 'Suppress unsubscribed and current customer records.',
            'send_checklist' => "Proofread\nConfirm audience snapshot\nExport CSV manually",
            'approval_status' => 'approved',
            'status' => 'ready',
            'created_by' => $this->userId,
        ]);
        $run = $this->marketing->getEmailCampaignRun($runId);
        $this->assertSame(100, (int) ($run['readiness_score'] ?? 0));
        $this->assertEmpty((array) ($run['readiness_warnings_json'] ?? []));
        $this->assertSame(1, (int) ($run['recipient_count'] ?? 0));

        $bundle = $this->marketing->getEmailCampaignExportBundle($runId);
        $this->assertTrue((bool) ($bundle['csv_export']['manual_export_only'] ?? false));
        $this->assertSame(['email', 'first_name', 'last_name', 'subject', 'preheader', 'body', 'cta_label', 'cta_url', 'unsubscribe_text'], $bundle['csv_export']['headers']);
        $this->assertSame('cora@example.com', (string) ($bundle['csv_export']['sample_rows'][0]['email'] ?? ''));
        $this->assertFalse((bool) ($bundle['csv_export']['external_send'] ?? true));

        $exported = $this->marketing->markEmailCampaignRunExported($runId);
        $this->assertSame('exported', (string) $exported['status']);
        $this->assertNotEmpty($exported['csv_export_json']);
        $this->assertTrue((bool) ($exported['performance_json']['manual_exported'] ?? false));

        $incompleteId = $this->marketing->createEmailCampaignRun([
            'name' => 'Incomplete Readiness Email',
            'created_by' => $this->userId,
        ]);
        $incomplete = $this->marketing->getEmailCampaignRun($incompleteId);
        $this->assertContains('missing_audience', (array) ($incomplete['readiness_warnings_json'] ?? []));
        $this->assertContains('approval_not_approved', (array) ($incomplete['readiness_warnings_json'] ?? []));
        $this->assertContains('missing_unsubscribe_text', (array) ($incomplete['readiness_warnings_json'] ?? []));
    }

    public function testPhaseSixtyFiveConsentPoliciesAndSuppressionWarningsProtectManualEmailExports(): void
    {
        $policyId = $this->marketing->createConsentPolicy([
            'policy_name' => 'Newsletter Explicit Opt-In',
            'channel' => 'email',
            'consent_basis' => 'explicit_opt_in',
            'requires_unsubscribe' => 1,
            'requires_suppression_check' => 1,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $suppressionId = $this->marketing->createSuppressionEntry([
            'channel' => 'email',
            'identifier' => 'blocked@example.com',
            'reason' => 'Unsubscribed',
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $policyId);
        $this->assertGreaterThan(0, $suppressionId);
        $this->assertCount(1, $this->marketing->listConsentPolicies(['channel' => 'email', 'active' => true], 10, 0));
        $this->assertCount(1, $this->marketing->listSuppressionEntries(['channel' => 'email', 'active' => true], 10, 0));

        $unsafeRunId = $this->marketing->createEmailCampaignRun([
            'name' => 'Unsafe Manual Export',
            'subject' => 'Please read',
            'preheader_text' => 'A note',
            'body_snapshot' => 'Body',
            'recipient_count' => 10,
            'segment_name' => 'Manual list',
            'cta_label' => 'Open',
            'cta_url' => 'https://example.com',
            'approval_status' => 'approved',
            'send_checklist' => "Proofread\nExport manually",
            'created_by' => $this->userId,
        ]);
        $unsafe = $this->marketing->getEmailCampaignRun($unsafeRunId);
        $this->assertSame('needs_review', (string) ($unsafe['consent_status'] ?? ''));
        $this->assertContains('missing_consent_policy', (array) ($unsafe['safety_warnings_json'] ?? []));
        $this->assertContains('audience_safety:missing_consent_policy', (array) ($unsafe['readiness_warnings_json'] ?? []));

        $safeRunId = $this->marketing->createEmailCampaignRun([
            'name' => 'Safe Manual Export',
            'subject' => 'Please read',
            'preheader_text' => 'A note',
            'body_snapshot' => 'Body',
            'recipient_count' => 10,
            'segment_name' => 'Manual list',
            'consent_policy_id' => $policyId,
            'suppression_list_checked' => 1,
            'suppression_notes' => 'Suppressed unsubscribed records.',
            'unsubscribe_text' => 'Unsubscribe at any time.',
            'cta_label' => 'Open',
            'cta_url' => 'https://example.com',
            'approval_status' => 'approved',
            'send_checklist' => "Proofread\nExport manually",
            'created_by' => $this->userId,
        ]);
        $safe = $this->marketing->getEmailCampaignRun($safeRunId);
        $this->assertSame('approved', (string) ($safe['consent_status'] ?? ''));
        $this->assertEmpty((array) ($safe['safety_warnings_json'] ?? []));
        $this->assertSame('Newsletter Explicit Opt-In', (string) ($safe['consent_policy_name'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createConsentPolicy(['policy_name' => 'Viewer Policy']);
            $this->fail('Viewer should not create consent policies.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $otherWorkspaceId = $this->createWorkspace('Consent Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        try {
            (new Marketing())->createEmailCampaignRun([
                'name' => 'Cross Workspace Consent',
                'consent_policy_id' => $policyId,
                'created_by' => $this->userId,
            ]);
            $this->fail('Cross-workspace consent policies should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active workspace', strtolower($e->getMessage()));
        } finally {
            WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        }

        $page = $this->runWebEndpoint('public/marketing_email_runs.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_email_runs.php consent');
        $this->assertStringContainsString('Consent Policy', (string) ($page['body'] ?? ''));
        $this->assertStringContainsString('Audience safety', (string) ($page['body'] ?? ''));
    }

    public function testPhaseThirtySevenJourneyPlannerCrudDraftsAndPages(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Journey Campaign');
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Journey Audience',
            'status' => 'active',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Journey Email Content',
            'content_type' => 'email',
            'channel' => 'email',
            'draft_body' => 'Journey email body.',
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ]);
        $emailRunId = $this->marketing->createEmailCampaignRun([
            'name' => 'Journey Manual Email Run',
            'content_item_id' => $contentId,
            'campaign_id' => $campaignId,
            'audience_segment_id' => $segmentId,
            'subject' => 'Start the journey',
            'created_by' => $this->userId,
        ]);
        $taskId = $this->createTask($this->workspaceId, 'Journey sales follow-up');

        $journeyId = $this->marketing->createJourney([
            'name' => 'Trial To Demo Journey',
            'journey_goal' => 'Move active trial contacts to demo requests.',
            'description' => 'Manual-first journey plan.',
            'status' => 'draft',
            'audience_segment_id' => $segmentId,
            'campaign_id' => $campaignId,
            'owner_user_id' => $this->userId,
            'steps' => [
                ['step_order' => 1, 'step_type' => 'email', 'title' => 'Send intro email', 'content_item_id' => $contentId, 'email_run_id' => $emailRunId, 'instructions' => 'Export manually.'],
                ['step_order' => 2, 'step_type' => 'wait', 'title' => 'Wait three days', 'wait_days' => 3],
                ['step_order' => 3, 'step_type' => 'task', 'title' => 'Create sales follow-up', 'task_id' => $taskId],
                ['step_order' => 4, 'step_type' => 'condition', 'title' => 'Check intent', 'condition_json' => ['field' => 'reply', 'operator' => 'exists']],
            ],
            'created_by' => $this->userId,
        ]);

        $journey = $this->marketing->getJourney($journeyId);
        $this->assertSame('Trial To Demo Journey', (string) ($journey['name'] ?? ''));
        $this->assertSame('Journey Audience', (string) ($journey['audience_segment_name'] ?? ''));
        $this->assertCount(4, (array) ($journey['steps'] ?? []));
        $this->assertSame(['email', 'wait', 'task', 'condition'], array_column((array) $journey['steps'], 'step_type'));

        $this->assertTrue($this->marketing->updateJourney($journeyId, ['status' => 'active']));
        $this->assertSame('active', (string) ($this->marketing->getJourney($journeyId)['status'] ?? ''));

        $draft = $this->marketing->generateJourneyDraft([
            'journey_id' => $journeyId,
            'prompt' => 'Make this journey safer for manual execution.',
            'created_by' => $this->userId,
        ]);
        $this->assertTrue((bool) ($draft['manual_execution_only'] ?? false));
        $this->assertFalse((bool) ($draft['external_execution'] ?? true));
        $this->assertTrue((bool) ($draft['provider']['fallback_used'] ?? false));
        $drafts = $this->marketing->listJourneyDrafts(['journey_id' => $journeyId], 10, 0);
        $this->assertCount(1, $drafts);
        $this->assertTrue((bool) ($drafts[0]['draft_json']['manual_execution_only'] ?? false));

        $listPage = $this->runWebEndpoint('public/marketing_journeys.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($listPage, 200, 'marketing_journeys.php');
        $this->assertStringContainsString('Trial To Demo Journey', (string) ($listPage['body'] ?? ''));
        $editPage = $this->runWebEndpoint('public/marketing_journey_edit.php', $this->webSession('owner'), ['method' => 'GET', 'query' => ['id' => $journeyId]]);
        $this->assertEndpointHealthy($editPage, 200, 'marketing_journey_edit.php');
        $viewPage = $this->runWebEndpoint('public/marketing_journey_view.php', $this->webSession('owner'), ['method' => 'GET', 'query' => ['id' => $journeyId]]);
        $this->assertEndpointHealthy($viewPage, 200, 'marketing_journey_view.php');
        $this->assertStringContainsString('External Execution', (string) ($viewPage['body'] ?? ''));
    }

    public function testPhaseEightyThreeJourneyReadinessFlagsManualSequenceGaps(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Readiness Journey Campaign');
        $journeyId = $this->marketing->createJourney([
            'name' => 'Gapped Manual Journey',
            'journey_goal' => 'Move leads through a safer manual sequence.',
            'campaign_id' => $campaignId,
            'steps' => [
                ['step_type' => 'email', 'title' => 'Email without copy'],
                ['step_type' => 'wait', 'title' => 'Wait without days', 'wait_days' => 0],
                ['step_type' => 'condition', 'title' => 'Condition without rule'],
            ],
            'created_by' => $this->userId,
        ]);

        $readiness = $this->marketing->getJourneyReadiness($journeyId);
        $this->assertSame('blocked', (string) ($readiness['status'] ?? ''));
        $this->assertGreaterThanOrEqual(1, (int) ($readiness['counts']['missing_audience'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($readiness['counts']['missing_step_copy'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($readiness['counts']['invalid_wait_steps'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($readiness['counts']['condition_gaps'] ?? 0));
        $this->assertFalse((bool) ($readiness['manual_first_boundary']['external_send'] ?? true));
        $this->assertFalse((bool) ($readiness['manual_first_boundary']['external_execution'] ?? true));
        $this->assertSame('draft_side_only', (string) ($readiness['ai_context']['recommendation_mode'] ?? ''));
        $issues = array_merge(...array_map(static fn(array $check): array => (array) ($check['issues'] ?? []), (array) ($readiness['checks'] ?? [])));
        $this->assertContains('missing_copy_or_linked_content', $issues);
        $this->assertContains('wait_duration_required', $issues);
        $this->assertContains('condition_rule_required', $issues);

        $page = $this->runWebEndpoint('public/marketing_journey_view.php', $this->webSession('owner'), [
            'method' => 'GET',
            'query' => ['id' => $journeyId],
        ]);
        $this->assertEndpointHealthy($page, 200, 'marketing_journey_view.php phase 83');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Journey Readiness', $body);
        $this->assertStringContainsString('AI Recommendations', $body);
        $this->assertStringContainsString('Email without copy', $body);
        $this->assertStringContainsString('Manual-first guardrail', $body);
    }

    public function testPhaseOneHundredTwentyFiveAudienceJourneyUsabilityCenterGuidesSegmentsAndJourneys(): void
    {
        $empty = $this->marketing->getAudienceJourneyUsabilityCenter($this->userId, 8);
        $this->assertArrayHasKey('rule_starters', $empty);
        $this->assertArrayHasKey('journey_starters', $empty);
        $this->assertNotEmpty((array) ($empty['rule_starters'] ?? []));
        $this->assertNotEmpty((array) ($empty['journey_starters'] ?? []));
        $this->assertFalse((bool) ($empty['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($empty['guardrails']['external_execution'] ?? true));
        $this->assertTrue((bool) ($empty['guardrails']['templates_do_not_create_records'] ?? false));

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, lead_source, stage, engagement_score, created_by)
             VALUES (?, UUID(), 'Phase', 'Seventy Four', 'phase74@example.com', 'form', 'qualified', 82, ?)",
            [$this->workspaceId, $this->userId]
        );

        $campaignId = $this->createCampaign($this->workspaceId, 'Phase 74 Audience Journey Campaign');
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Phase 74 Form Audience',
            'description' => 'Qualified form leads for a manual-first journey.',
            'status' => 'active',
            'source_scope' => 'contacts',
            'rule_logic' => 'all',
            'rules' => [
                ['source_type' => 'contact', 'field_key' => 'lead_source', 'operator' => 'equals', 'value_text' => 'form'],
                ['source_type' => 'contact', 'field_key' => 'stage', 'operator' => 'equals', 'value_text' => 'qualified'],
            ],
            'created_by' => $this->userId,
        ]);
        $snapshot = $this->marketing->createAudienceSegmentSnapshot($segmentId, $this->userId);
        $this->assertGreaterThanOrEqual(1, (int) ($snapshot['contact_count'] ?? 0));

        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 74 Journey Email',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'approved',
            'draft_body' => 'Manual journey email with a clear review CTA.',
            'campaign_id' => $campaignId,
            'target_audience' => 'Qualified form leads',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createAudienceActivation([
            'activation_name' => 'Phase 74 Audience Activation',
            'activation_type' => 'launch',
            'status' => 'active',
            'audience_segment_id' => $segmentId,
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'created_by' => $this->userId,
        ]);
        $journeyId = $this->marketing->createJourney([
            'name' => 'Phase 74 Manual Journey',
            'journey_goal' => 'Move qualified form leads through manual follow-up.',
            'status' => 'active',
            'audience_segment_id' => $segmentId,
            'campaign_id' => $campaignId,
            'owner_user_id' => $this->userId,
            'steps' => [
                ['step_type' => 'email', 'title' => 'Send approved note', 'content_item_id' => $contentId, 'instructions' => 'Export and send manually after approval.'],
                ['step_type' => 'wait', 'title' => 'Wait two days', 'wait_days' => 2],
                ['step_type' => 'task', 'title' => 'Sales follow-up', 'instructions' => 'Call qualified leads with the campaign context.'],
            ],
            'created_by' => $this->userId,
        ]);

        $center = $this->marketing->getAudienceJourneyUsabilityCenter($this->userId, 8);
        $this->assertGreaterThanOrEqual(1, (int) ($center['counts']['segments']['total'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($center['counts']['journeys']['total'] ?? 0));
        $this->assertNotEmpty((array) ($center['segments'] ?? []));
        $this->assertNotEmpty((array) ($center['journeys'] ?? []));
        $this->assertContains('Phase 74 Form Audience', array_column((array) ($center['segments'] ?? []), 'name'));
        $this->assertContains('Phase 74 Manual Journey', array_column((array) ($center['journeys'] ?? []), 'name'));
        $this->assertSame('ready', (string) ($this->marketing->getJourneyReadiness($journeyId)['status'] ?? ''));

        $segmentsPage = $this->runWebEndpoint('public/marketing_segments.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($segmentsPage, 200, 'marketing_segments.php audience journey cockpit');
        $segmentsBody = (string) ($segmentsPage['body'] ?? '');
        $this->assertStringContainsString('Audience Journey Cockpit', $segmentsBody);
        $this->assertStringContainsString('Qualified form submitters', $segmentsBody);
        $this->assertStringContainsString('Manual-first', $segmentsBody);

        $journeysPage = $this->runWebEndpoint('public/marketing_journeys.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($journeysPage, 200, 'marketing_journeys.php audience journey cockpit');
        $journeysBody = (string) ($journeysPage['body'] ?? '');
        $this->assertStringContainsString('Audience Journey Cockpit', $journeysBody);
        $this->assertStringContainsString('New lead to sales handoff', $journeysBody);
        $this->assertStringContainsString('Phase 74 Manual Journey', $journeysBody);

        $viewerPage = $this->runWebEndpoint('public/marketing_journeys.php', $this->webSession('viewer'), ['method' => 'GET']);
        $this->assertEndpointHealthy($viewerPage, 200, 'marketing_journeys.php viewer cockpit');
        $this->assertStringContainsString('Audience Journey Cockpit', (string) ($viewerPage['body'] ?? ''));

        $otherWorkspaceId = $this->createWorkspace('Phase 125 Audience Journey Isolation');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherCenter = (new Marketing())->getAudienceJourneyUsabilityCenter($this->userId, 8);
        $this->assertSame(0, (int) ($otherCenter['counts']['segments']['total'] ?? -1));
        $this->assertSame(0, (int) ($otherCenter['counts']['journeys']['total'] ?? -1));
        $this->assertFalse((bool) ($otherCenter['guardrails']['external_publish'] ?? true));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseOneHundredTwentySixWorkflowNextStepsGuideCoreMarketingPages(): void
    {
        $setup = $this->marketing->getMarketingWorkflowNextStepCenter($this->userId, 'setup', 6);
        $this->assertSame('setup', (string) ($setup['surface'] ?? ''));
        $this->assertSame('Setup next steps', (string) ($setup['title'] ?? ''));
        $this->assertFalse((bool) ($setup['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($setup['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($setup['guardrails']['external_api_calls'] ?? true));
        $this->assertContains('Complete Setup', array_column((array) ($setup['actions'] ?? []), 'label'));
        $this->assertContains('Define Brand Voice', array_column((array) ($setup['advanced_actions'] ?? []), 'label'));

        $audiences = $this->marketing->getMarketingWorkflowNextStepCenter($this->userId, 'audiences', 6);
        $this->assertSame('audiences', (string) ($audiences['surface'] ?? ''));
        $this->assertContains('Create Or Review Audience', array_column((array) ($audiences['actions'] ?? []), 'label'));

        $campaigns = $this->marketing->getMarketingWorkflowNextStepCenter($this->userId, 'briefs', 6);
        $this->assertSame('campaigns', (string) ($campaigns['surface'] ?? ''));
        $this->assertSame('briefs', (string) ($campaigns['requested_surface'] ?? ''));
        $this->assertContains('Create Campaign Brief', array_column((array) ($campaigns['actions'] ?? []), 'label'));

        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 75 Workflow Content',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'review',
            'draft_body' => 'Workflow next steps should point operators to reviews and calendar work.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createCalendarMilestone([
            'title' => 'Phase 75 Review Date',
            'milestone_type' => 'review',
            'milestone_date' => date('Y-m-d', strtotime('+2 days')),
            'content_item_id' => $contentId,
            'status' => 'planned',
            'created_by' => $this->userId,
        ]);

        $content = $this->marketing->getMarketingWorkflowNextStepCenter($this->userId, 'content', 6);
        $this->assertSame('content', (string) ($content['surface'] ?? ''));
        $this->assertGreaterThanOrEqual(1, (int) ($content['counts']['content_items'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($content['counts']['calendar'] ?? 0));
        $this->assertContains('Create Content Item', array_column((array) ($content['actions'] ?? []), 'label'));
        $this->assertContains('Open Reviews', array_column((array) ($content['advanced_actions'] ?? []), 'label'));

        $landing = $this->marketing->getMarketingWorkflowNextStepCenter($this->userId, 'landing', 6);
        $this->assertSame('landing', (string) ($landing['surface'] ?? ''));
        $this->assertContains('Create Landing Page', array_column((array) ($landing['actions'] ?? []), 'label'));

        $otherWorkspaceId = $this->createWorkspace('Phase 126 Workflow Next Steps Isolation');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherContent = (new Marketing())->getMarketingWorkflowNextStepCenter($this->userId, 'content', 6);
        $this->assertSame(0, (int) ($otherContent['counts']['content_items'] ?? -1));
        $this->assertSame(0, (int) ($otherContent['counts']['calendar'] ?? -1));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');

        $files = [
            'public/marketing_onboarding.php',
            'public/marketing_content.php',
            'public/marketing_briefs.php',
            'public/marketing_landing_pages.php',
            'public/marketing_calendar.php',
            'public/marketing_reviews.php',
            'public/marketing_distribution.php',
            'public/marketing_performance.php',
        ];
        foreach ($files as $file) {
            $body = (string) file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $file);
            $this->assertStringContainsString('MarketingWorkflowNextStepsUi', $body, $file . ' should render workflow next steps.');
        }

        foreach ([
            ['public/marketing_onboarding.php', 'Setup next steps'],
            ['public/marketing_content.php', 'Content next steps'],
            ['public/marketing_landing_pages.php', 'Landing page next steps'],
            ['public/marketing_reviews.php', 'Review next steps'],
            ['public/marketing_distribution.php', 'Send / Export next steps'],
            ['public/marketing_performance.php', 'Results next steps'],
        ] as [$endpoint, $expected]) {
            $page = $this->runWebEndpoint($endpoint, $this->webSession('owner'), ['method' => 'GET']);
            $this->assertEndpointHealthy($page, 200, $endpoint . ' workflow next steps');
            $this->assertStringContainsString($expected, (string) ($page['body'] ?? ''));
            $this->assertStringContainsString('Advanced Marketing Tools', (string) ($page['body'] ?? ''));
            $this->assertStringContainsString('No external send, publish, or channel API call', (string) ($page['body'] ?? ''));
        }
    }

    public function testPhaseOneHundredTwentySevenMediaOperationalReadinessSurfacesLaunchGates(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 76 Media Gap Content',
            'content_type' => 'social_post',
            'channel' => 'instagram',
            'status' => 'scheduled',
            'draft_body' => 'This draft needs channel-ready media before export.',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Phase 76 Visual Gap Landing',
            'slug' => 'phase-76-visual-gap-' . uniqid(),
            'headline' => 'A launch page without visual proof yet',
            'created_by' => $this->userId,
        ]);
        $distributionPostId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'instagram',
            'planned_copy' => 'Manual export package needs media.',
            'created_by' => $this->userId,
        ]);
        $blockedMediaId = $this->marketing->createMediaFile([
            'title' => 'Phase 76 Blocked Creative',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/phase-76-blocked.png',
            'approval_status' => 'blocked',
            'license_status' => 'restricted',
            'blocked_reason' => 'Rights review is incomplete.',
            'created_by' => $this->userId,
        ]);
        $kitId = $this->marketing->createChannelMediaKit([
            'title' => 'Phase 76 Instagram Media Kit',
            'channel' => 'instagram',
            'content_item_id' => $contentId,
            'landing_page_id' => $landingPageId,
            'distribution_post_id' => $distributionPostId,
            'created_by' => $this->userId,
        ]);

        $readiness = $this->marketing->getMarketingMediaOperationalReadiness($this->userId, 6);
        $this->assertSame('Media Operational Readiness', (string) ($readiness['title'] ?? ''));
        $this->assertGreaterThanOrEqual(1, (int) ($readiness['counts']['needs_accessibility'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($readiness['counts']['blocked_or_restricted'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($readiness['counts']['content_without_media'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($readiness['counts']['landing_media_issues'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($readiness['counts']['distribution_without_media'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($readiness['counts']['channel_kits_blocked'] ?? 0));
        $this->assertFalse((bool) ($readiness['guardrails']['external_publish'] ?? true));
        $this->assertFalse((bool) ($readiness['guardrails']['external_send'] ?? true));
        $this->assertFalse((bool) ($readiness['guardrails']['external_media_generation'] ?? true));
        $this->assertContains('Resolve blocked media', array_column((array) ($readiness['actions'] ?? []), 'label'));
        $this->assertNotEmpty((array) ($readiness['queues']['accessibility'] ?? []));
        $this->assertNotEmpty((array) ($readiness['queues']['governance'] ?? []));
        $this->assertNotEmpty((array) ($readiness['queues']['content'] ?? []));
        $this->assertNotEmpty((array) ($readiness['queues']['landing_pages'] ?? []));
        $this->assertNotEmpty((array) ($readiness['queues']['distribution'] ?? []));
        $this->assertNotEmpty((array) ($readiness['queues']['channel_kits'] ?? []));

        $operationsById = [];
        foreach ((array) ($readiness['queues']['governance'] ?? []) as $operation) {
            $operationsById[(int) ($operation['media_id'] ?? 0)] = $operation;
        }
        $this->assertArrayHasKey($blockedMediaId, $operationsById);
        $kitIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($readiness['queues']['channel_kits'] ?? []));
        $this->assertContains($kitId, $kitIds);

        foreach ([
            'public/marketing_assets.php',
            'public/marketing_content.php',
            'public/marketing_landing_pages.php',
            'public/marketing_distribution.php',
            'public/marketing_channel_exports.php',
        ] as $file) {
            $body = (string) file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $file);
            $this->assertStringContainsString('MarketingMediaReadinessUi', $body, $file . ' should render media operational readiness.');
        }

        foreach ([
            'public/marketing_assets.php',
            'public/marketing_content.php',
            'public/marketing_landing_pages.php',
            'public/marketing_distribution.php',
            'public/marketing_channel_exports.php',
        ] as $endpoint) {
            $page = $this->runWebEndpoint($endpoint, $this->webSession('owner'), ['method' => 'GET']);
            $this->assertEndpointHealthy($page, 200, $endpoint . ' media operational readiness');
            $this->assertStringContainsString('Media Operational Readiness', (string) ($page['body'] ?? ''));
            $this->assertStringContainsString('No external publish/send', (string) ($page['body'] ?? ''));
        }

        $otherWorkspaceId = $this->createWorkspace('Phase 127 Media Readiness Isolation');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherReadiness = (new Marketing())->getMarketingMediaOperationalReadiness($this->userId, 6);
        $this->assertSame(0, (int) ($otherReadiness['counts']['total_media'] ?? -1));
        $this->assertSame(0, (int) ($otherReadiness['counts']['content_without_media'] ?? -1));
        $this->assertSame(0, (int) ($otherReadiness['counts']['distribution_without_media'] ?? -1));
        $this->assertFalse((bool) ($otherReadiness['guardrails']['external_publish'] ?? true));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
    }

    public function testPhaseOneHundredTwentyEightReleaseQaHarnessCoversMarketingPagesAndRoles(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $playbookId = $this->marketing->createCampaignPlaybook([
            'name' => 'Release QA Playbook',
            'playbook_type' => 'product_launch',
            'status' => 'active',
            'campaign_goal' => 'Validate every Marketing operator route before release.',
            'target_audience' => 'Marketing operators',
            'audience_segment_id' => $fixture['segment_id'],
            'persona_id' => $fixture['persona_id'],
            'offer_context_item_id' => $fixture['offer_id'],
            'landing_page_id' => $fixture['landing_page_id'],
            'created_by' => $this->userId,
        ]);
        $operatorPackId = $this->marketing->createOperatorExportPack((int) $fixture['campaign_id'], $this->userId);
        $checklistId = $this->marketing->createCampaignLaunchChecklist([
            'title' => 'Release QA Harness Checklist',
            'campaign_id' => $fixture['campaign_id'],
            'created_by' => $this->userId,
        ], $this->userId);
        $this->assertGreaterThan(0, $playbookId);
        $this->assertGreaterThan(0, $operatorPackId);
        $this->assertGreaterThan(0, $checklistId);
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Release QA Governance Media',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/release-qa-governance.jpg',
            'alt_text' => 'Release QA media governance sample',
            'approval_status' => 'pending',
            'license_status' => 'owned',
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $mediaId);

        $routes = $this->marketingReleaseQaRoutes($fixture + [
            'playbook_id' => $playbookId,
            'operator_pack_id' => $operatorPackId,
            'checklist_id' => $checklistId,
        ]);
        $expectedPages = MarketingUi::internalPageFilenames();
        sort($expectedPages);
        $actualPages = array_keys($routes);
        sort($actualPages);
        $this->assertSame($expectedPages, $actualPages, 'Release QA routes should cover every authenticated Marketing page.');

        foreach ($routes as $page => $query) {
            $response = $this->runWebEndpoint('public/' . $page, $this->webSession('owner'), [
                'method' => 'GET',
                'query' => $query,
            ]);
            $this->assertEndpointHealthy($response, 200, $page . ' owner render');
            $body = (string) ($response['body'] ?? '');
            if ($page === 'marketing_landing_page_preview.php') {
                $this->assertStringContainsString('preview-banner', $body, $page . ' should keep authenticated preview controls.');
            } else {
                $this->assertStringContainsString('Marketing Workspace', $body, $page . ' should keep authenticated Marketing chrome.');
            }
        }

        foreach ([
            'marketing.php',
            'marketing_onboarding.php',
            'marketing_content.php',
            'marketing_assets.php',
            'marketing_distribution.php',
            'marketing_admin.php',
        ] as $page) {
            $response = $this->runWebEndpoint('public/' . $page, [], ['method' => 'GET']);
            $body = (string) ($response['body'] ?? '');
            $headers = implode("\n", (array) ($response['headers'] ?? []));
            $this->assertStringNotContainsString('Fatal error', $body . $headers, $page . ' unauthenticated guard');
            $this->assertStringNotContainsString('Marketing Workspace', $body, $page . ' should not render authenticated Marketing chrome without a session.');
            $this->assertTrue(
                $body === '' || str_contains($body, 'Welcome Back') || str_contains($headers, 'login.php'),
                $page . ' should redirect or show the login screen for unauthenticated users.'
            );
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $viewerContent = $this->runWebEndpoint('public/marketing_content.php', $this->webSession('viewer'), ['method' => 'GET']);
        $this->assertEndpointHealthy($viewerContent, 200, 'marketing_content.php viewer read-only');
        $viewerBody = (string) ($viewerContent['body'] ?? '');
        $this->assertStringContainsString('Content Studio', $viewerBody);
        $this->assertStringNotContainsString('name="action" value="bulk_update"', $viewerBody);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $marketingAssets = $this->runWebEndpoint('public/marketing_assets.php', $this->webSession('marketing'), ['method' => 'GET']);
        $this->assertEndpointHealthy($marketingAssets, 200, 'marketing_assets.php marketing writer');
        $marketingBody = (string) ($marketingAssets['body'] ?? '');
        $this->assertStringContainsString('name="action" value="create_media"', $marketingBody);
        $this->assertStringNotContainsString('Update Governance', $marketingBody);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $ownerAssets = $this->runWebEndpoint('public/marketing_assets.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($ownerAssets, 200, 'marketing_assets.php owner manage');
        $this->assertStringContainsString('Update Governance', (string) ($ownerAssets['body'] ?? ''));
    }

    public function testPhaseOneHundredTwentyNineMarketingPageQualityMatrixRendersAdminDiagnostics(): void
    {
        $matrix = MarketingPageQualityMatrix::build(__DIR__ . '/../../../public');
        $this->assertSame('ready', $matrix['status'], json_encode($matrix['pages'], JSON_PRETTY_PRINT));
        $this->assertSame(count(MarketingUi::internalPageFilenames()), (int) ($matrix['counts']['total'] ?? 0));
        $this->assertSame(0, (int) ($matrix['counts']['blocked'] ?? 0));
        $this->assertSame([], $matrix['missing_registrations']);

        $response = $this->runWebEndpoint('public/marketing_admin.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($response, 200, 'marketing_admin.php page quality matrix');
        $body = (string) ($response['body'] ?? '');
        $this->assertStringContainsString('Marketing Page Quality Matrix', $body);
        $this->assertStringContainsString('Internal Pages Covered', $body);
        $this->assertStringContainsString('Sanitized page matrix payload', $body);
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $body);
        $this->assertStringNotContainsString('Fatal error', $body);
    }

    public function testPhaseOneHundredThirtyControlledLiveExecutionPolicyAllowsInternalAdapter(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();

        $defaultPolicy = $this->marketing->getMarketingLiveExecutionPolicy();
        $this->assertFalse((bool) ($defaultPolicy['live_execution_enabled'] ?? true));

        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Release QA Live Webhook',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_internal_webhook',
            'secret_reference' => 'vault://marketing/release-qa-webhook',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => $fixture['landing_page_id'],
            'payload' => ['event' => 'release_qa_live_internal', 'landing_page_id' => $fixture['landing_page_id']],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);

        $blockedAttempt = $this->marketing->runExecutionQueueItem($queueId, ['attempt_mode' => 'live', 'created_by' => $this->userId]);
        $this->assertSame('blocked', $blockedAttempt['status']);
        $this->assertContains('live_policy_disabled', (array) ($blockedAttempt['result_json']['blocked_reasons'] ?? []));

        $policy = $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_internal_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['owner enabled policy', 'connector verified', 'approval required'],
        ], $this->userId);
        $this->assertTrue((bool) $policy['live_execution_enabled']);
        $dryRun = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($dryRun['status'] ?? ''));
        $preflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($preflight['status'] ?? ''));

        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Release QA live internal adapter approved.', $this->userId));

        $readiness = $this->marketing->getMarketingLiveExecutionReadiness($queueId);
        $this->assertSame('live_ready', $readiness['status']);
        $this->assertSame(1, (int) ($readiness['counts']['ready_live_queue_items'] ?? 0));
        $this->assertSame(0, (int) ($readiness['counts']['blocked_live_queue_items'] ?? 0));

        $liveAttempt = $this->marketing->runExecutionQueueItem($queueId, ['attempt_mode' => 'live', 'live_confirmation' => 'RUN LIVE', 'created_by' => $this->userId]);
        $this->assertSame('success', $liveAttempt['status']);
        $this->assertTrue((bool) ($liveAttempt['result_json']['live_execution'] ?? false));
        $this->assertFalse((bool) ($liveAttempt['result_json']['adapter_result']['external_api_called'] ?? true));
        $this->assertSame('crm_internal_webhook', (string) ($liveAttempt['result_json']['adapter_result']['adapter_key'] ?? ''));

        $queue = $this->marketing->getExecutionQueueItem($queueId);
        $this->assertSame('succeeded', (string) ($queue['status'] ?? ''));
        $this->assertNotEmpty($queue['live_policy_snapshot_json']);

        $events = $this->marketing->listLiveExecutionEvents(['queue_id' => $queueId], 10, 0);
        $this->assertContains('live_succeeded', array_column($events, 'event_type'));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->expectException(RuntimeException::class);
            $this->marketing->updateMarketingLiveExecutionPolicy(['status' => 'disabled'], $this->userId);
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }
    }

    public function testPhaseOneHundredThirtySevenLiveExecutionRequiresConfirmationAndBlocksDuplicateSuccess(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Confirmed Live Internal Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_internal_webhook',
            'secret_reference' => 'vault://marketing/confirmed-live',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_internal_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'final live confirmation required'],
        ], $this->userId);
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId])['status'] ?? ''));
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId])['status'] ?? ''));

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => (int) $fixture['landing_page_id'],
            'payload' => ['event' => 'confirmed_live_execution'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved confirmed live execution.', $this->userId));
        $queue = $this->marketing->getExecutionQueueItem($queueId);
        $this->assertNotEmpty($queue['live_idempotency_key'] ?? '');
        $this->assertSame('block_after_success', (string) ($queue['live_rerun_policy'] ?? ''));

        $missingConfirmation = $this->marketing->runExecutionQueueItem($queueId, ['attempt_mode' => 'live', 'created_by' => $this->userId]);
        $this->assertSame('blocked', (string) ($missingConfirmation['status'] ?? ''));
        $this->assertContains('live_confirmation_required', (array) ($missingConfirmation['result_json']['blocked_reasons'] ?? []));
        $this->assertSame([], (array) ($missingConfirmation['result_json']['adapter_result'] ?? []));
        $blockedEvidence = Database::queryOne(
            "SELECT adapter_key, connector_id, failure_class, request_evidence_json, response_evidence_json, adapter_contract_json
             FROM marketing_execution_attempts
             WHERE workspace_id = ? AND queue_id = ? AND status = 'blocked'
             ORDER BY id DESC
             LIMIT 1",
            [$this->workspaceId, $queueId]
        );
        $this->assertSame('crm_internal_webhook', (string) ($blockedEvidence['adapter_key'] ?? ''));
        $this->assertSame($connectorId, (int) ($blockedEvidence['connector_id'] ?? 0));
        $this->assertSame('blocked_confirmation', (string) ($blockedEvidence['failure_class'] ?? ''));
        $blockedRequest = json_decode((string) ($blockedEvidence['request_evidence_json'] ?? '{}'), true);
        $blockedContract = json_decode((string) ($blockedEvidence['adapter_contract_json'] ?? '{}'), true);
        $this->assertFalse((bool) ($blockedRequest['live_confirmation_provided'] ?? true));
        $this->assertTrue((bool) ($blockedContract['requires_final_confirmation'] ?? false));
        $this->assertFalse((bool) ($blockedContract['raw_secret_values_visible'] ?? true));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->runExecutionQueueItem($queueId, [
                'attempt_mode' => 'live',
                'live_confirmation' => 'RUN LIVE',
                'created_by' => $this->userId,
            ]);
            $this->fail('Marketing writer should not run a direct live execution attempt.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $writerPage = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('marketing'), ['method' => 'GET']);
            $this->assertEndpointHealthy($writerPage, 200, 'marketing_channel_exports.php writer live run controls');
            $writerBody = (string) ($writerPage['body'] ?? '');
            $this->assertStringNotContainsString('aria-label="Type RUN LIVE to execute live"', $writerBody);
            $this->assertStringContainsString('Approved live item is ready for a manager to run or confirm dispatch.', $writerBody);

            $writerPost = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('marketing'), [
                'method' => 'POST',
                'post' => [
                    'csrf_token' => 'csrf-marketing',
                    'action' => 'run_execution',
                    'queue_id' => $queueId,
                    'attempt_mode' => 'live',
                    'live_confirmation' => 'RUN LIVE',
                ],
            ]);
            $this->assertEndpointHealthy($writerPost, 200, 'marketing_channel_exports.php writer blocked live run');
            $this->assertStringContainsString('permission to run live execution attempts', (string) ($writerPost['body'] ?? ''));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        $confirmed = $this->marketing->runExecutionQueueItem($queueId, [
            'attempt_mode' => 'live',
            'live_confirmation' => 'RUN LIVE',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('success', (string) ($confirmed['status'] ?? ''));
        $this->assertSame('RUN LIVE', (string) ($confirmed['live_confirmation_text'] ?? ''));
        $this->assertSame((string) ($queue['live_idempotency_key'] ?? ''), (string) ($confirmed['idempotency_key'] ?? ''));
        $this->assertSame((string) ($queue['live_idempotency_key'] ?? ''), (string) ($confirmed['result_json']['adapter_result']['idempotency_key'] ?? ''));
        $successEvidence = Database::queryOne(
            "SELECT adapter_key, connector_id, provider_reference, failure_class, duration_ms,
                    request_evidence_json, response_evidence_json, adapter_contract_json
             FROM marketing_execution_attempts
             WHERE workspace_id = ? AND queue_id = ? AND status = 'success'
             ORDER BY id DESC
             LIMIT 1",
            [$this->workspaceId, $queueId]
        );
        $this->assertSame('crm_internal_webhook', (string) ($successEvidence['adapter_key'] ?? ''));
        $this->assertSame($connectorId, (int) ($successEvidence['connector_id'] ?? 0));
        $this->assertSame('', (string) ($successEvidence['failure_class'] ?? ''));
        $this->assertGreaterThanOrEqual(0, (int) ($successEvidence['duration_ms'] ?? -1));
        $requestEvidence = json_decode((string) ($successEvidence['request_evidence_json'] ?? '{}'), true);
        $responseEvidence = json_decode((string) ($successEvidence['response_evidence_json'] ?? '{}'), true);
        $contractEvidence = json_decode((string) ($successEvidence['adapter_contract_json'] ?? '{}'), true);
        $this->assertSame((string) ($queue['live_idempotency_key'] ?? ''), (string) ($requestEvidence['idempotency_key'] ?? ''));
        $this->assertTrue((bool) ($requestEvidence['live_confirmation_provided'] ?? false));
        $this->assertSame('success', (string) ($responseEvidence['status'] ?? ''));
        $this->assertTrue((bool) ($contractEvidence['requires_manager_permission'] ?? false));
        $this->assertFalse((bool) ($contractEvidence['raw_secret_values_visible'] ?? true));
        $this->assertStringNotContainsString('vault://', json_encode($responseEvidence));

        $confirmedQueue = $this->marketing->getExecutionQueueItem($queueId);
        $this->assertSame('succeeded', (string) ($confirmedQueue['status'] ?? ''));
        $this->assertNotEmpty($confirmedQueue['live_last_confirmed_at'] ?? null);
        $this->assertSame($this->userId, (int) ($confirmedQueue['live_last_confirmed_by'] ?? 0));

        $duplicate = $this->marketing->runExecutionQueueItem($queueId, [
            'attempt_mode' => 'live',
            'live_confirmation' => 'RUN LIVE',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('blocked', (string) ($duplicate['status'] ?? ''));
        $this->assertContains('live_success_already_recorded', (array) ($duplicate['result_json']['blocked_reasons'] ?? []));

        $page = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_channel_exports.php live run confirmation');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('RUN LIVE', $body);
        $this->assertStringContainsString('Run Live', $body);
    }

    public function testPhaseOneHundredThirtyEightLiveExecutionEmergencyStopBlocksAndResumesQueue(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Emergency Stop Live Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_internal_webhook',
            'secret_reference' => 'vault://marketing/emergency-stop',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_internal_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'emergency stop clear'],
        ], $this->userId);
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId])['status'] ?? ''));
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId])['status'] ?? ''));

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => (int) $fixture['landing_page_id'],
            'payload' => ['event' => 'emergency_stop_probe'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved before emergency stop.', $this->userId));
        $ready = $this->marketing->getMarketingLiveExecutionReadiness($queueId);
        $this->assertSame(1, (int) ($ready['counts']['ready_live_queue_items'] ?? 0), json_encode($ready, JSON_PRETTY_PRINT));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->pauseMarketingLiveExecution($this->userId, 'Marketing cannot pause live execution.');
            $this->fail('Marketing writer should not pause live execution globally.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        $policy = $this->marketing->pauseMarketingLiveExecution($this->userId, 'Provider incident validation.');
        $this->assertTrue((bool) ($policy['emergency_paused'] ?? false));
        $this->assertStringContainsString('Provider incident', (string) ($policy['emergency_pause_reason'] ?? ''));

        $blocked = $this->marketing->getMarketingLiveExecutionReadiness($queueId);
        $this->assertSame('blocked', (string) ($blocked['status'] ?? ''));
        $this->assertSame(1, (int) ($blocked['counts']['blocked_live_queue_items'] ?? 0), json_encode($blocked, JSON_PRETTY_PRINT));
        $this->assertContains('live_execution_emergency_paused', (array) ($blocked['blocked_queue_items'][0]['readiness']['blocked_reasons'] ?? []));

        $attempt = $this->marketing->runExecutionQueueItem($queueId, [
            'attempt_mode' => 'live',
            'live_confirmation' => 'RUN LIVE',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('blocked', (string) ($attempt['status'] ?? ''));
        $this->assertContains('live_execution_emergency_paused', (array) ($attempt['result_json']['blocked_reasons'] ?? []));
        $this->assertSame([], (array) ($attempt['result_json']['adapter_result'] ?? []));

        $page = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_channel_exports.php emergency stop');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Emergency stop active', $body);
        $this->assertStringContainsString('Resume Live Execution', $body);
        $this->assertStringContainsString('Provider incident validation', $body);

        $resumed = $this->marketing->resumeMarketingLiveExecution($this->userId);
        $this->assertFalse((bool) ($resumed['emergency_paused'] ?? true));
        $readyAgain = $this->marketing->getMarketingLiveExecutionReadiness($queueId);
        $this->assertSame(1, (int) ($readyAgain['counts']['ready_live_queue_items'] ?? 0), json_encode($readyAgain, JSON_PRETTY_PRINT));
    }

    public function testPhaseFortySixLiveSetupWizardTracksSafeReadinessPath(): void
    {
        $emptyWizard = $this->marketing->getMarketingLiveSetupWizard($this->userId);
        $this->assertSame('not_started', (string) ($emptyWizard['status'] ?? ''));
        $this->assertSame(0, (int) ($emptyWizard['score'] ?? -1));
        $this->assertCount(8, (array) ($emptyWizard['steps'] ?? []));
        $this->assertSame('policy_enabled', (string) ($emptyWizard['next_step']['key'] ?? ''));
        $this->assertTrue((bool) ($emptyWizard['secret_safe'] ?? false));
        $this->assertFalse((bool) ($emptyWizard['raw_secret_values_visible'] ?? true));
        $this->assertSame(8, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM marketing_live_setup_steps WHERE workspace_id = ?",
            [$this->workspaceId]
        )['count'] ?? 0));

        $fixture = $this->createMarketingReleaseQaFixture();
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Live Setup Wizard Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_internal_webhook',
            'secret_reference' => 'vault://marketing/live-setup-wizard',
            'secret_status' => 'verified',
            'live_daily_cap' => 10,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_internal_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'setup wizard reviewed'],
        ], $this->userId);
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId])['status'] ?? ''));
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId])['status'] ?? ''));

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => (int) $fixture['landing_page_id'],
            'payload' => ['event' => 'live_setup_wizard'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved setup wizard validation.', $this->userId));
        $this->marketing->updateLiveOrchestratorSchedule([
            'status' => 'active',
            'expected_interval_minutes' => 5,
            'max_stale_minutes' => 30,
            'max_queue_items_per_run' => 25,
        ], $this->userId);

        $readyWizard = $this->marketing->getMarketingLiveSetupWizard($this->userId);
        $this->assertSame('ready', (string) ($readyWizard['status'] ?? ''), json_encode($readyWizard, JSON_PRETTY_PRINT));
        $this->assertSame(100, (int) ($readyWizard['score'] ?? 0), json_encode($readyWizard, JSON_PRETTY_PRINT));
        $this->assertSame(8, (int) ($readyWizard['ready_count'] ?? 0));
        $this->assertNull($readyWizard['next_step'] ?? null);
        $this->assertStringNotContainsString('vault://marketing/live-setup-wizard', json_encode($readyWizard, JSON_UNESCAPED_SLASHES) ?: '');
        $this->assertSame(8, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM marketing_live_setup_steps
             WHERE workspace_id = ? AND status = 'ready'",
            [$this->workspaceId]
        )['count'] ?? 0));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $writerWizard = $this->marketing->getMarketingLiveSetupWizard($this->userId);
            $this->assertSame('ready', (string) ($writerWizard['status'] ?? ''));
            $this->marketing->updateMarketingLiveExecutionPolicy(['status' => 'disabled'], $this->userId);
            $this->fail('Marketing writers should not enable or disable live policy from setup.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        $executionPage = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($executionPage, 200, 'marketing_execution.php live setup wizard');
        $executionBody = (string) ($executionPage['body'] ?? '');
        $this->assertStringContainsString('Live Setup Wizard', $executionBody);
        $this->assertStringContainsString('Confirm worker schedule', $executionBody);
        $this->assertStringNotContainsString('DB_PASSWORD', $executionBody);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $executionBody);

        $exportsPage = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($exportsPage, 200, 'marketing_channel_exports.php live setup wizard');
        $exportsBody = (string) ($exportsPage['body'] ?? '');
        $this->assertStringContainsString('Live Setup Wizard', $exportsBody);
        $this->assertStringContainsString('Enable live policy', $exportsBody);
        $this->assertStringNotContainsString('DB_PASSWORD', $exportsBody);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $exportsBody);
    }

    public function testPhaseFortySevenLiveRehearsalPromotesApprovedDryRunToLiveQueue(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $bundleId = $this->marketing->createChannelExportBundle((int) $fixture['distribution_post_id'], $this->userId);
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Live Rehearsal Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_internal_webhook',
            'secret_reference' => 'vault://marketing/live-rehearsal',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_internal_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'rehearsal evidence required'],
        ], $this->userId);
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId])['status'] ?? ''));
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId])['status'] ?? ''));

        $rehearsalId = $this->marketing->createLiveRehearsalRun([
            'source_type' => 'channel_export_bundle',
            'execution_type' => 'website_webhook',
            'connector_id' => $connectorId,
            'channel_export_bundle_id' => $bundleId,
            'target_channel' => 'website_webhook',
            'target_recipient' => 'ops@example.test',
            'checklist' => ['content reviewed', 'audience checked', 'manager will approve'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $dryRun = $this->marketing->runLiveRehearsalDryRun($rehearsalId, $this->userId);
        $this->assertSame('dry_run_passed', (string) ($dryRun['status'] ?? ''), json_encode($dryRun, JSON_PRETTY_PRINT));
        $this->assertSame('passed', (string) ($dryRun['dry_run_evidence_json']['dry_run_status'] ?? ''));
        $this->assertFalse((bool) ($dryRun['dry_run_evidence_json']['external_api_called'] ?? true));
        $this->assertGreaterThan(0, (int) ($dryRun['dry_run_queue_id'] ?? 0));
        $this->assertGreaterThan(0, (int) ($dryRun['dry_run_attempt_id'] ?? 0));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->decideLiveRehearsalRun($rehearsalId, 'approved', 'Writer should not approve.', $this->userId);
            $this->fail('Marketing writer should not approve live rehearsals.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        $approved = $this->marketing->decideLiveRehearsalRun($rehearsalId, 'approved', 'Approved after dry-run evidence.', $this->userId);
        $this->assertSame('approved', (string) ($approved['status'] ?? ''));
        $promotion = $this->marketing->promoteLiveRehearsalToQueue($rehearsalId, $this->userId);
        $queue = (array) ($promotion['queue'] ?? []);
        $this->assertSame('approved', (string) ($queue['status'] ?? ''), json_encode($promotion, JSON_PRETTY_PRINT));
        $this->assertSame('live', (string) ($queue['execution_mode'] ?? ''));
        $this->assertSame($rehearsalId, (int) ($queue['live_rehearsal_run_id'] ?? 0));
        $this->assertSame(1, (int) ($queue['live_rehearsal_required'] ?? 0));
        $this->assertSame('promoted', (string) ($queue['live_rehearsal_status'] ?? ''));
        $this->assertSame('passed', (string) ($queue['live_rehearsal_evidence_json']['dry_run_status'] ?? ''));
        $readiness = $this->marketing->getMarketingLiveExecutionReadiness((int) $queue['id']);
        $this->assertSame('live_ready', (string) ($readiness['status'] ?? ''), json_encode($readiness, JSON_PRETTY_PRINT));

        $missingConfirmation = $this->marketing->runExecutionQueueItem((int) $queue['id'], [
            'attempt_mode' => 'live',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('blocked', (string) ($missingConfirmation['status'] ?? ''));
        $this->assertContains('live_confirmation_required', (array) ($missingConfirmation['result_json']['blocked_reasons'] ?? []));
        $this->assertNotContains('live_rehearsal_evidence_required', (array) ($missingConfirmation['result_json']['blocked_reasons'] ?? []));

        $directQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'channel_export_bundle_id' => $bundleId,
            'payload' => ['event' => 'missing_rehearsal_evidence'],
            'live_rehearsal_required' => 1,
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($directQueueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved to prove rehearsal gate blocks direct live.', $this->userId));
        $blockedDirect = $this->marketing->runExecutionQueueItem($directQueueId, [
            'attempt_mode' => 'live',
            'live_confirmation' => 'RUN LIVE',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('blocked', (string) ($blockedDirect['status'] ?? ''));
        $this->assertContains('live_rehearsal_evidence_required', (array) ($blockedDirect['result_json']['blocked_reasons'] ?? []));

        $page = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_channel_exports.php live rehearsals');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Live Rehearsals', $body);
        $this->assertStringContainsString('Create Live Rehearsal', $body);
        $this->assertStringContainsString('Dry-run: Passed', $body);
        $this->assertStringContainsString('Queue #', $body);
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $body);

        $executionPage = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($executionPage, 200, 'marketing_execution.php live rehearsals');
        $executionBody = (string) ($executionPage['body'] ?? '');
        $this->assertStringContainsString('Dry-Run And Live Rehearsals', $executionBody);
        $this->assertStringContainsString('Open Rehearsals', $executionBody);
    }

    public function testPhaseFortyEightLiveSchedulerValidationTracksProductionWorkerEvidence(): void
    {
        $this->marketing->updateLiveOrchestratorSchedule([
            'status' => 'active',
            'expected_interval_minutes' => 5,
            'max_stale_minutes' => 30,
            'max_queue_items_per_run' => 25,
        ], $this->userId);
        $this->marketing->updateLiveQueueDispatcherSchedule([
            'status' => 'active',
            'expected_interval_minutes' => 5,
            'max_stale_minutes' => 30,
            'max_queue_items_per_run' => 10,
        ], $this->userId);
        $this->marketing->updateLiveEmailWorkerSchedule([
            'status' => 'active',
            'expected_interval_minutes' => 5,
            'max_stale_minutes' => 30,
            'max_handoffs_per_run' => 25,
        ], $this->userId);
        $this->marketing->updateLiveChannelWorkerSchedule([
            'status' => 'active',
            'expected_interval_minutes' => 5,
            'max_stale_minutes' => 30,
            'max_handoffs_per_run' => 25,
        ], $this->userId);
        $this->marketing->updateLiveOutcomeSyncSchedule([
            'status' => 'active',
            'expected_interval_minutes' => 5,
            'max_stale_minutes' => 30,
            'max_queue_items_per_run' => 250,
        ], $this->userId);

        Database::execute(
            "INSERT INTO marketing_live_execution_launcher_runs
             (workspace_id, uuid, invocation_uuid, source, status, target_scope, readiness_only, limit_count,
              processed_count, succeeded_count, blocked_count, failed_count, result_json, started_at, completed_at)
             VALUES (?, UUID(), UUID(), 'cron', 'completed', 'all', 0, 25, 0, 0, 0, 0, ?, NOW(), NOW())",
            [$this->workspaceId, json_encode(['secret_safe' => true], JSON_UNESCAPED_SLASHES)]
        );
        Database::execute(
            "INSERT INTO marketing_live_orchestration_runs
             (workspace_id, uuid, source, status, limit_count, processed_count, succeeded_count, blocked_count, failed_count, result_json, requested_by, started_at, completed_at)
             VALUES (?, UUID(), 'cron', 'completed', 25, 0, 0, 0, 0, ?, ?, NOW(), NOW())",
            [$this->workspaceId, json_encode(['secret_safe' => true], JSON_UNESCAPED_SLASHES), $this->userId]
        );
        Database::execute(
            "INSERT INTO marketing_live_queue_dispatch_runs
             (workspace_id, uuid, source, status, limit_count, processed_count, succeeded_count, failed_count, blocked_count, skipped_count, result_json, requested_by, started_at, completed_at)
             VALUES (?, UUID(), 'cron', 'completed', 10, 0, 0, 0, 0, 0, ?, ?, NOW(), NOW())",
            [$this->workspaceId, json_encode(['secret_safe' => true], JSON_UNESCAPED_SLASHES), $this->userId]
        );
        $dispatchRunId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO marketing_live_email_worker_runs
             (workspace_id, uuid, source, status, limit_count, processed_count, sent_count, blocked_count, failed_count, skipped_count, result_json, requested_by, started_at, completed_at)
             VALUES (?, UUID(), 'cron', 'completed', 25, 0, 0, 0, 0, 0, ?, ?, NOW(), NOW())",
            [$this->workspaceId, json_encode(['secret_safe' => true], JSON_UNESCAPED_SLASHES), $this->userId]
        );
        Database::execute(
            "INSERT INTO marketing_live_channel_worker_runs
             (workspace_id, uuid, source, status, limit_count, processed_count, sent_count, blocked_count, failed_count, skipped_count, result_json, requested_by, started_at, completed_at)
             VALUES (?, UUID(), 'cron', 'completed', 25, 0, 0, 0, 0, 0, ?, ?, NOW(), NOW())",
            [$this->workspaceId, json_encode(['secret_safe' => true], JSON_UNESCAPED_SLASHES), $this->userId]
        );
        Database::execute(
            "INSERT INTO marketing_live_outcome_sync_runs
             (workspace_id, uuid, source, status, limit_count, processed_count, delivered_count, failed_count, blocked_count, queued_count, unknown_count, result_json, requested_by, started_at, completed_at)
             VALUES (?, UUID(), 'cron', 'completed', 250, 0, 0, 0, 0, 0, 0, ?, ?, NOW(), NOW())",
            [$this->workspaceId, json_encode(['secret_safe' => true], JSON_UNESCAPED_SLASHES), $this->userId]
        );
        Database::execute(
            "INSERT INTO marketing_live_webhook_attempts
             (workspace_id, uuid, status, http_method, endpoint_host, endpoint_url_hash,
              request_headers_json, request_payload_json, response_status_code, response_preview, duration_ms,
              attempt_count, external_api_called, third_party_delivery, metadata_json, attempted_at, created_by)
             VALUES (?, UUID(), 'sent', 'POST', 'example.com', SHA2('https://example.com/hook', 256),
                     JSON_OBJECT('Content-Type', 'application/json'), JSON_OBJECT('event', 'scheduler_validation'),
                     202, 'accepted', 60, 1, 1, 0, JSON_OBJECT('secret_safe', true, 'raw_secret_values_visible', false), NOW(), ?)",
            [$this->workspaceId, $this->userId]
        );

        $validation = $this->marketing->getMarketingLiveSchedulerValidation(true, $this->userId);
        $this->assertSame('ready', (string) ($validation['status'] ?? ''), json_encode($validation, JSON_PRETTY_PRINT));
        $this->assertCount(7, (array) ($validation['components'] ?? []));
        $this->assertSame(7, (int) ($validation['counts']['ready'] ?? 0), json_encode($validation, JSON_PRETTY_PRINT));
        $this->assertStringContainsString('run_marketing_live_execution.php', implode("\n", (array) ($validation['command_hints'] ?? [])));

        $persistedCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM marketing_live_scheduler_validations
             WHERE workspace_id = ?",
            [$this->workspaceId]
        )['count'] ?? 0);
        $this->assertGreaterThanOrEqual(7, $persistedCount);
        $persistedJson = (string) (Database::queryOne(
            "SELECT GROUP_CONCAT(COALESCE(evidence_json, '')) AS evidence
             FROM marketing_live_scheduler_validations
             WHERE workspace_id = ?",
            [$this->workspaceId]
        )['evidence'] ?? '');
        $this->assertStringNotContainsString('DB_PASSWORD', $persistedJson);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $persistedJson);

        Database::execute(
            "DELETE FROM marketing_live_execution_launcher_runs WHERE workspace_id = ?",
            [$this->workspaceId]
        );
        $staleValidation = $this->marketing->getMarketingLiveSchedulerValidation(true, $this->userId);
        $launcher = array_values(array_filter(
            (array) ($staleValidation['components'] ?? []),
            static fn(array $component): bool => (string) ($component['worker_key'] ?? '') === 'marketing_live_execution_launcher'
        ))[0] ?? [];
        $this->assertSame('stale', (string) ($launcher['status'] ?? ''), json_encode($launcher, JSON_PRETTY_PRINT));
        $this->assertStringContainsString('run_marketing_live_execution.php', (string) ($launcher['command_hint'] ?? ''));

        Database::execute(
            "INSERT INTO marketing_live_worker_leases
             (workspace_id, uuid, worker_key, dispatch_run_id, lease_token, source, status, acquired_at, heartbeat_at, expires_at, metadata_json)
             VALUES (?, UUID(), 'marketing_live_queue_dispatcher', ?, UUID(), 'cron', 'running', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE), JSON_OBJECT('secret_safe', true))
             ON DUPLICATE KEY UPDATE
                dispatch_run_id = VALUES(dispatch_run_id),
                lease_token = VALUES(lease_token),
                source = VALUES(source),
                status = VALUES(status),
                acquired_at = VALUES(acquired_at),
                heartbeat_at = VALUES(heartbeat_at),
                expires_at = VALUES(expires_at),
                released_at = NULL,
                metadata_json = VALUES(metadata_json)",
            [$this->workspaceId, $dispatchRunId]
        );
        $leaseValidation = $this->marketing->getMarketingLiveSchedulerValidation(true, $this->userId);
        $dispatcher = array_values(array_filter(
            (array) ($leaseValidation['components'] ?? []),
            static fn(array $component): bool => (string) ($component['worker_key'] ?? '') === 'marketing_live_queue_dispatcher'
        ))[0] ?? [];
        $this->assertSame('running', (string) ($dispatcher['lease_state'] ?? ''), json_encode($dispatcher, JSON_PRETTY_PRINT));
        $this->assertIsInt($dispatcher['heartbeat_age_seconds'] ?? null);

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php production scheduler validation');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Production Scheduler Validation', $body);
        $this->assertStringContainsString('Copy Scheduler Commands', $body);
        $this->assertStringContainsString('Marketing live execution launcher', $body);
        $this->assertStringContainsString('Marketing live queue dispatcher', $body);
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $body);
    }

    public function testPhaseFortyNineLiveRecoveryControlsPreserveEvidenceAndRestrictActions(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $bundleId = $this->marketing->createChannelExportBundle((int) $fixture['distribution_post_id'], $this->userId);
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Live Recovery Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_internal_webhook',
            'secret_reference' => 'vault://marketing/live-recovery',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_internal_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'recovery controls active'],
        ], $this->userId);

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'approved',
            'connector_id' => $connectorId,
            'channel_export_bundle_id' => $bundleId,
            'payload' => ['event' => 'live_recovery', 'recipient' => 'recover@example.test'],
            'live_rerun_policy' => 'allow_failed_retry',
            'live_max_retries' => 2,
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        Database::execute(
            "UPDATE marketing_execution_queue
             SET status = 'failed',
                 live_dispatch_status = 'failed',
                 live_retry_status = 'none',
                 live_blocked_reason = 'Provider timeout'
             WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $queueId]
        );
        Database::execute(
            "INSERT INTO marketing_execution_attempts
             (workspace_id, queue_id, connector_id, uuid, status, attempt_mode, adapter_key, failure_class,
              result_json, error_message, started_at, completed_at, created_by)
             VALUES (?, ?, ?, UUID(), 'failed', 'live', 'crm_internal_webhook', 'provider_timeout', ?, 'Provider timeout', NOW(), NOW(), ?)",
            [$this->workspaceId, $queueId, $connectorId, json_encode(['message' => 'Provider timeout', 'secret_safe' => true], JSON_UNESCAPED_SLASHES), $this->userId]
        );

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $requested = $this->marketing->requestLiveQueueRecovery($queueId, 'retry', 'Please retry after checking endpoint.', $this->userId);
        $this->assertSame('requested', (string) ($requested['live_recovery_status'] ?? ''));
        try {
            $this->marketing->recoverLiveQueueItem($queueId, 'retry', ['reason' => 'Writer should not execute retry.'], $this->userId);
            $this->fail('Marketing writers should not execute live recovery actions.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        $retried = $this->marketing->recoverLiveQueueItem($queueId, 'retry', [
            'reason' => 'Retry after provider timeout.',
            'scheduled_at' => date('Y-m-d H:i:s', time() + 60),
        ], $this->userId);
        $this->assertSame('queued', (string) ($retried['status'] ?? ''));
        $this->assertSame('confirmed', (string) ($retried['live_dispatch_status'] ?? ''));
        $this->assertSame('scheduled', (string) ($retried['live_retry_status'] ?? ''));
        $this->assertSame('retry_scheduled', (string) ($retried['live_recovery_status'] ?? ''));
        $this->assertSame(1, (int) ($retried['live_retry_count'] ?? 0));

        Database::execute(
            "INSERT INTO marketing_suppression_entries
             (workspace_id, uuid, channel, identifier, identifier_hash, reason, status, source, metadata_json, created_by)
             VALUES (?, UUID(), 'email', 'recover@example.test', SHA2('recover@example.test', 256), 'Opted out', 'active', 'test', JSON_OBJECT('secret_safe', true), ?)",
            [$this->workspaceId, $this->userId]
        );
        $suppressed = $this->marketing->recoverLiveQueueItem($queueId, 'recheck_suppression', [
            'reason' => 'Recheck suppression before retry.',
        ], $this->userId);
        $this->assertSame('blocked', (string) ($suppressed['status'] ?? ''));
        $this->assertSame('blocked', (string) ($suppressed['live_dispatch_status'] ?? ''));
        $this->assertSame('blocked', (string) ($suppressed['live_recovery_status'] ?? ''));
        $this->assertStringContainsString('suppressed', strtolower((string) ($suppressed['live_blocked_reason'] ?? '')));

        $cancelled = $this->marketing->recoverLiveQueueItem($queueId, 'cancel', [
            'reason' => 'Operator cancelled after suppression block.',
        ], $this->userId);
        $this->assertSame('cancelled', (string) ($cancelled['status'] ?? ''));
        $this->assertSame('cancelled', (string) ($cancelled['live_dispatch_status'] ?? ''));
        $this->assertNotContains($queueId, array_map(
            static fn(array $queue): int => (int) ($queue['id'] ?? 0),
            $this->marketing->listDueLiveDispatchQueue(20, 0)
        ));

        $exhaustedQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'failed',
            'connector_id' => $connectorId,
            'channel_export_bundle_id' => $bundleId,
            'payload' => ['event' => 'retry_exhausted'],
            'live_rerun_policy' => 'allow_failed_retry',
            'live_retry_count' => 1,
            'live_max_retries' => 1,
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        Database::execute(
            "UPDATE marketing_execution_queue SET live_dispatch_status = 'failed' WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $exhaustedQueueId]
        );
        try {
            $this->marketing->recoverLiveQueueItem($exhaustedQueueId, 'retry', ['reason' => 'Should respect max attempts.'], $this->userId);
            $this->fail('Retry should respect max attempts.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('retry', strtolower($e->getMessage()));
        }
        $this->assertSame('permanent_failed', (string) ($this->marketing->getExecutionQueueItem($exhaustedQueueId)['live_recovery_status'] ?? ''));

        $events = $this->marketing->listLiveRecoveryEvents(['queue_id' => $queueId], 10, 0);
        $this->assertGreaterThanOrEqual(4, count($events));
        $this->assertContains('retry', array_map(static fn(array $event): string => (string) $event['action_type'], $events));
        $this->assertStringNotContainsString('vault://marketing/live-recovery', json_encode($events, JSON_UNESCAPED_SLASHES) ?: '');

        $queues = $this->marketing->listLiveRecoveryQueue('cancelled', 10, 0);
        $this->assertContains($queueId, array_map(static fn(array $queue): int => (int) $queue['id'], $queues));

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php live recovery controls');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Failure Recovery Controls', $body);
        $this->assertStringContainsString('Recovery Events', $body);
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $body);
    }

    public function testPhaseFiftyLiveConsentSuppressionGatesBlockUnsafeRecipients(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, lead_source, stage, created_by)
             VALUES (?, UUID(), 'Consent', 'Gate', 'phase50-recipient@example.com', '+15550995050', 'form', 'qualified', ?)",
            [$this->workspaceId, $this->userId]
        );
        $contactId = (int) Database::queryOne(
            "SELECT id FROM contacts WHERE workspace_id = ? AND email = 'phase50-recipient@example.com'",
            [$this->workspaceId]
        )['id'];
        $campaignId = $this->createCampaign($this->workspaceId, 'Phase 50 Consent Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 50 Consent Email',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'approved',
            'draft_body' => 'Consent-safe live execution copy.',
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ]);
        $consentPolicyId = $this->marketing->createConsentPolicy([
            'policy_name' => 'Phase 50 Email Consent',
            'channel' => 'email',
            'consent_basis' => 'existing_customer',
            'requires_unsubscribe' => 1,
            'requires_suppression_check' => 1,
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $emailConnectorId = $this->marketing->createChannelConnector([
            'name' => 'Phase 50 Email Connector',
            'connector_type' => 'email',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['email_queue_handoff', 'dry_run', 'unsubscribe_check'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_email_queue_handoff',
            'secret_reference' => 'vault://marketing/phase-50-email',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $whatsappConnectorId = $this->marketing->createChannelConnector([
            'name' => 'Phase 50 WhatsApp Connector',
            'connector_type' => 'whatsapp',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['copy_export', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_whatsapp_queue_handoff',
            'secret_reference' => 'vault://marketing/phase-50-whatsapp',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['email', 'whatsapp'],
            'allowed_adapter_keys' => ['crm_email_queue_handoff', 'crm_whatsapp_queue_handoff'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'consent checked', 'suppression checked', 'unsubscribe required'],
        ], $this->userId);
        Database::execute(
            "UPDATE marketing_channel_connectors
             SET live_preflight_status = 'passed',
                 live_preflight_checked_at = NOW(),
                 live_preflight_json = JSON_OBJECT('source', 'phase_50_test', 'secret_safe', true)
             WHERE workspace_id = ? AND id IN (?, ?)",
            [$this->workspaceId, $emailConnectorId, $whatsappConnectorId]
        );

        $missingUnsubscribeRunId = $this->marketing->createEmailCampaignRun([
            'name' => 'Phase 50 Missing Unsubscribe Run',
            'content_item_id' => $contentId,
            'campaign_id' => $campaignId,
            'consent_policy_id' => $consentPolicyId,
            'consent_status' => 'approved',
            'subject' => 'Consent gate missing unsubscribe',
            'preheader_text' => 'Consent safety',
            'body_snapshot' => 'Body without unsubscribe copy.',
            'unsubscribe_text' => '',
            'suppression_notes' => 'Suppression checked.',
            'suppression_list_checked_at' => date('Y-m-d H:i:s'),
            'approval_status' => 'approved',
            'recipient_count' => 1,
            'status' => 'ready',
            'created_by' => $this->userId,
        ]);
        $missingQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'email',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $emailConnectorId,
            'email_run_id' => $missingUnsubscribeRunId,
            'payload' => ['recipient_contact_ids' => [$contactId]],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($missingQueueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Consent gate missing unsubscribe check.', $this->userId));
        $missingReadiness = $this->marketing->getMarketingLiveExecutionReadiness($missingQueueId);
        $missingReasons = (array) ($missingReadiness['blocked_queue_items'][0]['readiness']['blocked_reasons'] ?? []);
        $this->assertContains('email_unsubscribe_text_required', $missingReasons, json_encode($missingReadiness, JSON_PRETTY_PRINT));
        $missingAttempt = $this->marketing->runExecutionQueueItem($missingQueueId, ['attempt_mode' => 'live', 'live_confirmation' => 'RUN LIVE', 'created_by' => $this->userId]);
        $this->assertSame('blocked', (string) ($missingAttempt['status'] ?? ''));
        $missingQueue = $this->marketing->getExecutionQueueItem($missingQueueId);
        $this->assertSame('blocked', (string) ($missingQueue['live_suppression_result'] ?? ''));
        $this->assertFalse((bool) ($missingQueue['live_unsubscribe_evidence_json']['present'] ?? true));

        $suppressedRunId = $this->marketing->createEmailCampaignRun([
            'name' => 'Phase 50 Suppression Run',
            'content_item_id' => $contentId,
            'campaign_id' => $campaignId,
            'consent_policy_id' => $consentPolicyId,
            'consent_status' => 'approved',
            'subject' => 'Consent gate suppression',
            'preheader_text' => 'Consent safety',
            'body_snapshot' => 'Body with unsubscribe copy.',
            'unsubscribe_text' => 'Reply unsubscribe to opt out.',
            'suppression_notes' => 'Suppression checked.',
            'suppression_list_checked_at' => date('Y-m-d H:i:s'),
            'approval_status' => 'approved',
            'recipient_count' => 1,
            'status' => 'ready',
            'created_by' => $this->userId,
        ]);
        $suppressedQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'email',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $emailConnectorId,
            'email_run_id' => $suppressedRunId,
            'payload' => ['recipient_contact_ids' => [$contactId]],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($suppressedQueueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Consent gate suppression check.', $this->userId));

        $otherWorkspaceId = $this->createWorkspace('Phase 50 Suppression Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        (new Marketing())->createSuppressionEntry([
            'channel' => 'email',
            'identifier' => 'phase50-recipient@example.com',
            'reason' => 'Other workspace suppression must not apply.',
            'created_by' => $this->userId,
        ]);
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        $this->marketing = new Marketing();

        $clearReadiness = $this->marketing->getMarketingLiveExecutionReadiness($suppressedQueueId);
        $this->assertSame(1, (int) ($clearReadiness['counts']['ready_live_queue_items'] ?? 0), json_encode($clearReadiness, JSON_PRETTY_PRINT));

        $this->marketing->createSuppressionEntry([
            'channel' => 'email',
            'identifier' => 'phase50-recipient@example.com',
            'reason' => 'Current workspace opted out.',
            'created_by' => $this->userId,
        ]);
        $suppressedReadiness = $this->marketing->getMarketingLiveExecutionReadiness($suppressedQueueId);
        $suppressedReasons = (array) ($suppressedReadiness['blocked_queue_items'][0]['readiness']['blocked_reasons'] ?? []);
        $this->assertContains('email_recipient_suppressed', $suppressedReasons, json_encode($suppressedReadiness, JSON_PRETTY_PRINT));
        $suppressedAttempt = $this->marketing->runExecutionQueueItem($suppressedQueueId, ['attempt_mode' => 'live', 'live_confirmation' => 'RUN LIVE', 'created_by' => $this->userId]);
        $this->assertSame('blocked', (string) ($suppressedAttempt['status'] ?? ''));
        $suppressedQueue = $this->marketing->getExecutionQueueItem($suppressedQueueId);
        $this->assertSame('blocked', (string) ($suppressedQueue['live_suppression_result'] ?? ''));
        $this->assertSame(1, (int) ($suppressedQueue['live_consent_evidence_json']['suppressed_recipient_count'] ?? 0));

        $whatsappQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'whatsapp',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $whatsappConnectorId,
            'payload' => [
                'recipient_contact_ids' => [$contactId],
                'message' => 'Your launch review is ready.',
                'consent_basis' => 'explicit_opt_in',
            ],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($whatsappQueueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'WhatsApp live gate check.', $this->userId));
        $whatsappReadiness = $this->marketing->getMarketingLiveExecutionReadiness($whatsappQueueId);
        $whatsappReasons = (array) ($whatsappReadiness['blocked_queue_items'][0]['readiness']['blocked_reasons'] ?? []);
        $this->assertContains('whatsapp_template_required_outside_24h_window', $whatsappReasons, json_encode($whatsappReadiness, JSON_PRETTY_PRINT));

        $reviewQueues = $this->marketing->getLiveConsentSuppressionReviewQueues(5);
        $this->assertNotEmpty($reviewQueues['missing_unsubscribe']);
        $this->assertNotEmpty($reviewQueues['suppression_blocked']);
        $this->assertNotEmpty($reviewQueues['whatsapp_template_blocked']);

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php consent suppression review');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Consent And Suppression Review', $body);
        $this->assertStringContainsString('Missing Unsubscribe', $body);
        $this->assertStringNotContainsString('phase50-recipient@example.com', $body);
        $this->assertStringNotContainsString('vault://marketing/phase-50-email', $body);
    }

    public function testPhaseFiftyOneLiveOutcomeReconciliationIsIdempotentAndSafe(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Phase 51 Outcome Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 51 Outcome Email',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'approved',
            'draft_body' => 'Outcome reconciliation copy.',
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ]);
        $emailRunId = $this->marketing->createEmailCampaignRun([
            'name' => 'Phase 51 Outcome Run',
            'content_item_id' => $contentId,
            'campaign_id' => $campaignId,
            'consent_status' => 'approved',
            'subject' => 'Outcome reconciliation',
            'preheader_text' => 'Outcome safety',
            'body_snapshot' => 'Outcome body with unsubscribe copy.',
            'unsubscribe_text' => 'Reply unsubscribe to opt out.',
            'suppression_notes' => 'Suppression checked.',
            'suppression_list_checked_at' => date('Y-m-d H:i:s'),
            'approval_status' => 'approved',
            'recipient_count' => 1,
            'status' => 'ready',
            'created_by' => $this->userId,
        ]);
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Phase 51 Email Connector',
            'connector_type' => 'email',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['email_queue_handoff', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_email_queue_handoff',
            'secret_reference' => 'vault://marketing/phase-51-email',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'email',
            'execution_mode' => 'live',
            'status' => 'succeeded',
            'connector_id' => $connectorId,
            'email_run_id' => $emailRunId,
            'payload' => ['source' => 'phase_51_reconciliation'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        Database::execute(
            "INSERT INTO marketing_live_email_handoffs
             (workspace_id, uuid, queue_id, email_run_id, connector_id, recipient_email, adapter_key,
              idempotency_key, provider_reference, consent_basis, unsubscribe_text_evidence,
              suppression_checked_at, suppression_result, consent_evidence_json, status, metadata_json, created_by)
             VALUES (?, UUID(), ?, ?, ?, 'phase51-recipient@example.com', 'crm_email_queue_handoff',
                     SHA2(CONCAT('phase51:', ?), 256), 'email_queue:phase51', 'existing_customer', 'hash:phase51',
                     NOW(), 'clear', JSON_OBJECT('secret_safe', true), 'sent', JSON_OBJECT('secret_safe', true), ?)",
            [$this->workspaceId, $queueId, $emailRunId, $connectorId, $queueId, $this->userId]
        );

        $firstSync = $this->marketing->syncLiveExecutionQueueOutcomes($queueId, 10);
        $this->assertSame(1, (int) ($firstSync['synced'] ?? 0));
        $this->assertSame(1, (int) ($firstSync['events_recorded'] ?? 0));
        $queue = $this->marketing->getExecutionQueueItem($queueId);
        $this->assertSame('delivered', (string) ($queue['live_outcome_status'] ?? ''));
        $this->assertNotEmpty($queue['live_outcome_reconciliation_key'] ?? '');
        $this->assertSame('delivered', (string) ($queue['live_outcome_reconciliation_json']['normalized_status'] ?? ''));

        $secondSync = $this->marketing->syncLiveExecutionQueueOutcomes($queueId, 10);
        $this->assertSame(1, (int) ($secondSync['synced'] ?? 0));
        $this->assertSame(0, (int) ($secondSync['events_recorded'] ?? 0));
        $events = $this->marketing->listLiveOutcomeReconciliationEvents(['queue_id' => $queueId], 10, 0);
        $this->assertCount(1, $events);
        $this->assertSame('delivered', (string) ($events[0]['normalized_status'] ?? ''));
        $this->assertStringNotContainsString('vault://marketing/phase-51-email', json_encode($events, JSON_UNESCAPED_SLASHES) ?: '');

        Database::execute(
            "UPDATE marketing_live_email_handoffs
             SET status = 'failed',
                 error_message = 'Provider rejected request with Bearer super-secret-token'
             WHERE workspace_id = ? AND queue_id = ?",
            [$this->workspaceId, $queueId]
        );
        $failedSync = $this->marketing->syncLiveExecutionQueueOutcomes($queueId, 10);
        $this->assertSame(1, (int) ($failedSync['events_recorded'] ?? 0));
        $failedQueue = $this->marketing->getExecutionQueueItem($queueId);
        $this->assertSame('failed', (string) ($failedQueue['live_outcome_status'] ?? ''));
        $events = $this->marketing->listLiveOutcomeReconciliationEvents(['queue_id' => $queueId], 10, 0);
        $this->assertCount(2, $events);
        $this->assertContains('failed', array_map(static fn(array $event): string => (string) $event['normalized_status'], $events));
        $this->assertStringNotContainsString('super-secret-token', json_encode($events, JSON_UNESCAPED_SLASHES) ?: '');

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php outcome reconciliation events');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Outcome Reconciliation Events', $body);
        $this->assertStringNotContainsString('phase51-recipient@example.com', $body);
        $this->assertStringNotContainsString('super-secret-token', $body);
    }

    public function testPhaseFiftyTwoLiveProofPacksAreSanitizedAndWorkspaceScoped(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Phase 52 Proof Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 52 Proof Email',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'approved',
            'draft_body' => 'Proof pack copy with unsubscribe copy.',
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ]);
        $emailRunId = $this->marketing->createEmailCampaignRun([
            'name' => 'Phase 52 Proof Run',
            'content_item_id' => $contentId,
            'campaign_id' => $campaignId,
            'consent_status' => 'approved',
            'subject' => 'Proof pack',
            'preheader_text' => 'Proof safety',
            'body_snapshot' => 'Proof body with unsubscribe copy.',
            'unsubscribe_text' => 'Reply unsubscribe to opt out.',
            'suppression_notes' => 'Suppression checked.',
            'suppression_list_checked_at' => date('Y-m-d H:i:s'),
            'approval_status' => 'approved',
            'recipient_count' => 1,
            'status' => 'ready',
            'created_by' => $this->userId,
        ]);
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Phase 52 Email Connector',
            'connector_type' => 'email',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['email_queue_handoff', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_email_queue_handoff',
            'secret_reference' => 'vault://marketing/phase-52-email',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['email'],
            'allowed_adapter_keys' => ['crm_email_queue_handoff'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'proof pack required'],
        ], $this->userId);
        Database::execute(
            "UPDATE marketing_channel_connectors
             SET live_preflight_status = 'passed',
                 live_preflight_checked_at = NOW(),
                 live_preflight_json = JSON_OBJECT('secret_safe', true, 'source', 'phase_52_test')
             WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $connectorId]
        );

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'email',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'email_run_id' => $emailRunId,
            'payload' => ['recipient_email' => 'phase52-recipient@example.com', 'source' => 'phase_52_proof_pack'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved for proof pack.', $this->userId));
        Database::execute(
            "UPDATE marketing_execution_queue
             SET status = 'succeeded',
                 live_dispatch_status = 'completed',
                 live_last_confirmed_at = NOW(),
                 live_last_confirmed_by = ?,
                 live_last_confirmation_text = 'RUN LIVE',
                 live_suppression_checked_at = NOW(),
                 live_suppression_result = 'clear',
                 live_consent_basis = 'existing_customer',
                 live_unsubscribe_evidence_json = JSON_OBJECT('present', true, 'hash', SHA2('unsubscribe text', 256)),
                 live_consent_evidence_json = JSON_OBJECT('suppressed_recipient_count', 0, 'secret_safe', true),
                 live_outcome_status = 'queued',
                 live_outcome_checked_at = NOW()
             WHERE workspace_id = ? AND id = ?",
            [$this->userId, $this->workspaceId, $queueId]
        );
        Database::execute(
            "INSERT INTO marketing_execution_attempts
             (workspace_id, queue_id, connector_id, uuid, status, attempt_mode, adapter_key,
              provider_reference, failure_class, duration_ms, request_evidence_json, response_evidence_json,
              adapter_contract_json, idempotency_key, live_confirmation_text, result_json, error_message,
              started_at, completed_at, created_by)
             VALUES (?, ?, ?, UUID(), 'success', 'live', 'crm_email_queue_handoff',
                     'email_queue:phase52', NULL, 42,
                     JSON_OBJECT('Authorization', 'Bearer super-secret-token', 'recipient_email', 'phase52-recipient@example.com'),
                     JSON_OBJECT('message', 'Provider accepted phase52-recipient@example.com with Bearer super-secret-token', 'provider_reference', 'email_queue:phase52'),
                     JSON_OBJECT('requires_live_policy', true, 'secret_safe', true),
                     SHA2(CONCAT('phase52:', ?), 256), 'RUN LIVE',
                     JSON_OBJECT('message', 'Sent to phase52-recipient@example.com', 'secret_reference', 'vault://marketing/phase-52-email'),
                     NULL, NOW(), NOW(), ?)",
            [$this->workspaceId, $queueId, $connectorId, $queueId, $this->userId]
        );
        Database::execute(
            "INSERT INTO marketing_live_email_handoffs
             (workspace_id, uuid, queue_id, email_run_id, connector_id, recipient_email, adapter_key,
              idempotency_key, provider_reference, consent_basis, unsubscribe_text_evidence,
              suppression_checked_at, suppression_result, consent_evidence_json, status, metadata_json, created_by)
             VALUES (?, UUID(), ?, ?, ?, 'phase52-recipient@example.com', 'crm_email_queue_handoff',
                     SHA2(CONCAT('phase52-handoff:', ?), 256), 'email_queue:phase52', 'existing_customer', 'hash:phase52',
                     NOW(), 'clear', JSON_OBJECT('secret_safe', true), 'sent', JSON_OBJECT('secret_safe', true), ?)",
            [$this->workspaceId, $queueId, $emailRunId, $connectorId, $queueId, $this->userId]
        );
        $handoffId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO marketing_live_email_delivery_proofs
             (workspace_id, uuid, handoff_id, proof_status, provider_key, smtp_method, message_id, to_email, evidence_json, proved_at)
             VALUES (?, UUID(), ?, 'sent', 'smtp', 'queue_worker', 'message-phase52-secret', 'phase52-recipient@example.com',
                     JSON_OBJECT('provider_reference', 'email_queue:phase52', 'recipient_email', 'phase52-recipient@example.com'), NOW())",
            [$this->workspaceId, $handoffId]
        );
        $this->marketing->recoverLiveQueueItem($queueId, 'operator_note', ['recovery_reason' => 'Proof pack operator note with Bearer super-secret-token'], $this->userId);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->createLiveProofPack($queueId, $this->userId);
            $this->fail('Marketing writer should not generate live proof packs.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }
        $pack = $this->marketing->createLiveProofPack($queueId, $this->userId);
        $this->assertSame('complete', (string) ($pack['proof_status'] ?? ''));
        $this->assertSame('delivered', (string) ($pack['normalized_outcome_status'] ?? ''));
        $this->assertTrue((bool) ($pack['summary_json']['includes']['approval_evidence'] ?? false));
        $this->assertTrue((bool) ($pack['summary_json']['includes']['confirmation_hash'] ?? false));
        $this->assertGreaterThanOrEqual(1, (int) ($pack['summary_json']['counts']['adapter_attempts'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($pack['summary_json']['counts']['email_delivery_proofs'] ?? 0));

        $encodedPack = json_encode($pack, JSON_UNESCAPED_SLASHES) ?: '';
        $this->assertStringNotContainsString('vault://marketing/phase-52-email', $encodedPack);
        $this->assertStringNotContainsString('phase52-recipient@example.com', $encodedPack);
        $this->assertStringNotContainsString('super-secret-token', $encodedPack);
        $this->assertStringNotContainsString('RUN LIVE', $encodedPack);
        $this->assertStringContainsString('phrase_hash', $encodedPack);

        $summary = $this->marketing->getMarketingLiveProofReportSummary();
        $this->assertGreaterThanOrEqual(1, (int) ($summary['proof_packs']['total'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['live_volume'] ?? 0));

        $otherWorkspaceId = $this->createWorkspace('Phase 52 Proof Isolation Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $isolatedMarketing = new Marketing();
        $this->assertNull($isolatedMarketing->getLiveProofPack((int) ($pack['id'] ?? 0)));
        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        $this->marketing = new Marketing();

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php live proof packs');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Live Proof Packs', $body);
        $this->assertStringNotContainsString('phase52-recipient@example.com', $body);
        $this->assertStringNotContainsString('super-secret-token', $body);
        $this->assertStringNotContainsString('vault://marketing/phase-52-email', $body);
    }

    public function testPhaseOneHundredThirtyNineLiveQueueDispatcherRunsDueConfirmedItems(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Due Live Dispatch Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_internal_webhook',
            'secret_reference' => 'vault://marketing/due-dispatch',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_internal_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'dispatcher confirmation required'],
        ], $this->userId);
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId])['status'] ?? ''));
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId])['status'] ?? ''));

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => (int) $fixture['landing_page_id'],
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
            'payload' => ['event' => 'due_dispatch_live_execution'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved for due live dispatch.', $this->userId));

        $emptyRun = $this->marketing->runLiveExecutionDispatcher([
            'source' => 'test',
            'limit_count' => 5,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('completed', (string) ($emptyRun['status'] ?? ''));
        $this->assertSame(0, (int) ($emptyRun['processed_count'] ?? -1));
        $this->assertContains('no_due_confirmed_live_queue_items', (array) ($emptyRun['result_json']['skipped'] ?? []));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->confirmExecutionQueueForLiveDispatch($queueId, $this->userId, 'RUN LIVE');
            $this->fail('Marketing writer should not confirm a live dispatch queue item.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }

        try {
            $this->marketing->confirmExecutionQueueForLiveDispatch($queueId, $this->userId, 'run it');
            $this->fail('Live dispatch confirmation should require exact RUN LIVE text.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('live_confirmation_required', $e->getMessage());
        }

        $confirmedQueue = $this->marketing->confirmExecutionQueueForLiveDispatch($queueId, $this->userId, 'RUN LIVE');
        $this->assertSame('confirmed', (string) ($confirmedQueue['live_dispatch_status'] ?? ''));
        $this->assertNotEmpty($confirmedQueue['live_dispatch_confirmed_at'] ?? null);
        $this->assertSame('RUN LIVE', (string) ($confirmedQueue['live_last_confirmation_text'] ?? ''));

        $dispatchRun = $this->marketing->runLiveExecutionDispatcher([
            'source' => 'test',
            'limit_count' => 5,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('completed', (string) ($dispatchRun['status'] ?? ''));
        $this->assertSame(1, (int) ($dispatchRun['processed_count'] ?? 0));
        $this->assertSame(1, (int) ($dispatchRun['succeeded_count'] ?? 0));
        $this->assertSame(0, (int) ($dispatchRun['blocked_count'] ?? 1));

        $dispatchedQueue = $this->marketing->getExecutionQueueItem($queueId);
        $this->assertSame('succeeded', (string) ($dispatchedQueue['status'] ?? ''));
        $this->assertSame('completed', (string) ($dispatchedQueue['live_dispatch_status'] ?? ''));
        $this->assertSame((int) ($dispatchRun['id'] ?? 0), (int) ($dispatchedQueue['live_dispatch_run_id'] ?? 0));
        $attempts = $this->marketing->listExecutionAttempts(['queue_id' => $queueId], 5, 0);
        $this->assertSame('success', (string) ($attempts[0]['status'] ?? ''));
        $this->assertSame('RUN LIVE', (string) ($attempts[0]['live_confirmation_text'] ?? ''));

        $blockedQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => (int) $fixture['landing_page_id'],
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('-2 minutes')),
            'payload' => ['event' => 'due_dispatch_emergency_blocked'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $blockedApprovalId = $this->marketing->requestExecutionApproval($blockedQueueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($blockedApprovalId, 'approved', 'Approved before emergency stop.', $this->userId));
        $this->marketing->confirmExecutionQueueForLiveDispatch($blockedQueueId, $this->userId, 'RUN LIVE');
        $this->marketing->pauseMarketingLiveExecution($this->userId, 'Dispatcher emergency stop validation.');
        $blockedRun = $this->marketing->runLiveExecutionDispatcher([
            'source' => 'test',
            'limit_count' => 5,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('blocked', (string) ($blockedRun['status'] ?? ''));
        $this->assertSame(1, (int) ($blockedRun['processed_count'] ?? 0));
        $this->assertSame(1, (int) ($blockedRun['blocked_count'] ?? 0));
        $blockedQueue = $this->marketing->getExecutionQueueItem($blockedQueueId);
        $this->assertSame('blocked', (string) ($blockedQueue['live_dispatch_status'] ?? ''));
        $this->assertContains('live_execution_emergency_paused', (array) ($blockedQueue['readiness_json']['blocked_reasons'] ?? []));
        $this->marketing->resumeMarketingLiveExecution($this->userId);

        $page = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_channel_exports.php live queue dispatcher');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Run Due Live Dispatcher', $body);
        $this->assertStringContainsString('Confirm Dispatch', $body);
        $this->assertStringContainsString('Live Dispatch Runs', $body);

        $executionPage = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($executionPage, 200, 'marketing_execution.php live queue dispatcher');
        $executionBody = (string) ($executionPage['body'] ?? '');
        $this->assertStringContainsString('Live Queue Dispatcher', $executionBody);
        $this->assertStringContainsString('run_marketing_live_dispatcher.php', $executionBody);
        $this->assertStringContainsString('live_dispatch_status=confirmed', $executionBody);
    }

    public function testPhaseOneHundredFortyLiveDispatcherCliHelpIsSafe(): void
    {
        $script = realpath(__DIR__ . '/../../../scripts/run_marketing_live_dispatcher.php');
        $this->assertIsString($script);
        $output = [];
        $exitCode = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help', $output, $exitCode);
        $body = implode("\n", $output);

        $this->assertSame(0, $exitCode, $body);
        $this->assertStringContainsString('Marketing Live Queue Dispatcher', $body);
        $this->assertStringContainsString('--workspace-id', $body);
        $this->assertStringContainsString('--user-id', $body);
        $this->assertStringContainsString('No raw secrets are printed', $body);
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $body);
    }

    public function testPhaseOneHundredFortyOneLiveDispatcherScheduleBlocksCliUntilActive(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Scheduled Live Dispatch Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_internal_webhook',
            'secret_reference' => 'vault://marketing/scheduled-dispatch',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_internal_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'dispatcher schedule active'],
        ], $this->userId);
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId])['status'] ?? ''));
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId])['status'] ?? ''));

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => (int) $fixture['landing_page_id'],
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('-3 minutes')),
            'payload' => ['event' => 'scheduled_dispatch_live_execution'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved for scheduled live dispatch.', $this->userId));
        $this->marketing->confirmExecutionQueueForLiveDispatch($queueId, $this->userId, 'RUN LIVE');

        $blockedRun = $this->marketing->runLiveExecutionDispatcher([
            'source' => 'cli',
            'limit_count' => 5,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('blocked', (string) ($blockedRun['status'] ?? ''));
        $this->assertContains('dispatcher_schedule_not_active', (array) ($blockedRun['result_json']['schedule_blockers'] ?? []));
        $this->assertSame('confirmed', (string) ($this->marketing->getExecutionQueueItem($queueId)['live_dispatch_status'] ?? ''));

        $schedule = $this->marketing->updateLiveQueueDispatcherSchedule([
            'status' => 'active',
            'expected_interval_minutes' => 5,
            'max_stale_minutes' => 15,
            'max_queue_items_per_run' => 5,
        ], $this->userId);
        $this->assertSame('active', (string) ($schedule['status'] ?? ''));
        $this->assertStringContainsString('run_marketing_live_dispatcher.php', (string) ($schedule['command'] ?? ''));

        $run = $this->marketing->runLiveExecutionDispatcher([
            'source' => 'cli',
            'limit_count' => 5,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('completed', (string) ($run['status'] ?? ''));
        $this->assertSame(1, (int) ($run['succeeded_count'] ?? 0));

        $health = $this->marketing->getLiveQueueDispatcherHealth(true, $this->userId);
        $this->assertContains((string) ($health['status'] ?? ''), ['healthy', 'attention']);
        $this->assertSame(0, (int) ($health['counts']['due_confirmed'] ?? -1));
        $this->assertStringContainsString('run_marketing_live_dispatcher.php', (string) ($health['command'] ?? ''));

        $paused = $this->marketing->pauseLiveQueueDispatcher($this->userId, 'Scheduled dispatcher pause test.');
        $this->assertTrue((bool) ($paused['emergency_paused'] ?? false));
        $resumed = $this->marketing->resumeLiveQueueDispatcher($this->userId);
        $this->assertFalse((bool) ($resumed['emergency_paused'] ?? true));

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php live dispatcher schedule');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Save Dispatcher Schedule', $body);
        $this->assertStringContainsString('Pause Live Dispatcher', $body);
        $this->assertStringContainsString('Due confirmed', $body);
    }

    public function testPhaseOneHundredFortyTwoLiveDispatcherLeaseBlocksOverlap(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Lease Guard Live Dispatch Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_internal_webhook',
            'secret_reference' => 'vault://marketing/lease-guard-dispatch',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_internal_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'dispatcher lease clear'],
        ], $this->userId);
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId])['status'] ?? ''));
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId])['status'] ?? ''));

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => (int) $fixture['landing_page_id'],
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('-2 minutes')),
            'payload' => ['event' => 'lease_guard_dispatch_live_execution'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved for lease-guarded live dispatch.', $this->userId));
        $this->marketing->confirmExecutionQueueForLiveDispatch($queueId, $this->userId, 'RUN LIVE');
        $this->marketing->updateLiveQueueDispatcherSchedule([
            'status' => 'active',
            'expected_interval_minutes' => 5,
            'max_stale_minutes' => 15,
            'max_queue_items_per_run' => 5,
        ], $this->userId);

        Database::execute(
            "INSERT INTO marketing_live_worker_leases
             (workspace_id, uuid, worker_key, dispatch_run_id, lease_token, source, status, acquired_at, heartbeat_at, expires_at, metadata_json)
             VALUES (?, UUID(), 'marketing_live_queue_dispatcher', 999999, UUID(), 'cron', 'running', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE), ?)
             ON DUPLICATE KEY UPDATE
                dispatch_run_id = VALUES(dispatch_run_id),
                lease_token = VALUES(lease_token),
                source = VALUES(source),
                status = VALUES(status),
                acquired_at = VALUES(acquired_at),
                heartbeat_at = VALUES(heartbeat_at),
                expires_at = VALUES(expires_at),
                released_at = NULL,
                metadata_json = VALUES(metadata_json)",
            [
                $this->workspaceId,
                json_encode(['dispatch_run_id' => 999999, 'source' => 'test_overlap_guard', 'secret_safe' => true]),
            ]
        );

        $blockedRun = $this->marketing->runLiveExecutionDispatcher([
            'source' => 'cron',
            'limit_count' => 5,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('blocked', (string) ($blockedRun['status'] ?? ''));
        $this->assertContains('live_dispatcher_lease_active', (array) ($blockedRun['result_json']['skipped'] ?? []));
        $this->assertSame('confirmed', (string) ($this->marketing->getExecutionQueueItem($queueId)['live_dispatch_status'] ?? ''));

        $health = $this->marketing->getLiveQueueDispatcherHealth();
        $this->assertTrue((bool) ($health['lease']['active'] ?? false));
        $this->assertSame(999999, (int) ($health['lease']['dispatch_run_id'] ?? 0));
        $this->assertContains('Wait for the active dispatcher lease to finish or investigate a stuck dispatcher run.', (array) ($health['recommended_actions'] ?? []));

        Database::execute(
            "UPDATE marketing_live_worker_leases
             SET status = 'released', released_at = NOW(), expires_at = NOW()
             WHERE workspace_id = ? AND worker_key = 'marketing_live_queue_dispatcher'",
            [$this->workspaceId]
        );

        $run = $this->marketing->runLiveExecutionDispatcher([
            'source' => 'cron',
            'limit_count' => 5,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('completed', (string) ($run['status'] ?? ''));
        $this->assertSame(1, (int) ($run['succeeded_count'] ?? 0));

        $lease = Database::queryOne(
            "SELECT status, dispatch_run_id
             FROM marketing_live_worker_leases
             WHERE workspace_id = ? AND worker_key = 'marketing_live_queue_dispatcher'",
            [$this->workspaceId]
        );
        $this->assertSame('released', (string) ($lease['status'] ?? ''));
        $this->assertSame((int) ($run['id'] ?? 0), (int) ($lease['dispatch_run_id'] ?? 0));

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php live dispatcher lease');
        $this->assertStringContainsString('Active Lease', (string) ($page['body'] ?? ''));
    }

    public function testPhaseOneHundredFortyThreeLiveDispatchCancellationStopsDueDispatcher(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Cancelable Live Dispatch Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_internal_webhook',
            'secret_reference' => 'vault://marketing/cancel-dispatch',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_internal_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'cancel before dispatch available'],
        ], $this->userId);
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId])['status'] ?? ''));
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId])['status'] ?? ''));

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => (int) $fixture['landing_page_id'],
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('-1 minute')),
            'payload' => ['event' => 'cancel_dispatch_live_execution'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved before cancel test.', $this->userId));
        $this->marketing->confirmExecutionQueueForLiveDispatch($queueId, $this->userId, 'RUN LIVE');
        $this->marketing->updateLiveQueueDispatcherSchedule([
            'status' => 'active',
            'expected_interval_minutes' => 5,
            'max_stale_minutes' => 15,
            'max_queue_items_per_run' => 5,
        ], $this->userId);

        $pageBeforeCancel = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($pageBeforeCancel, 200, 'marketing_channel_exports.php live dispatch cancel action');
        $this->assertStringContainsString('Cancel Dispatch', (string) ($pageBeforeCancel['body'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->cancelExecutionQueueLiveDispatch($queueId, $this->userId, 'Marketing role should not cancel live dispatch.');
            $this->fail('Marketing role should not cancel live dispatch queue items.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);

        $cancelled = $this->marketing->cancelExecutionQueueLiveDispatch($queueId, $this->userId, 'Operator found the wrong landing page.');
        $this->assertSame('cancelled', (string) ($cancelled['live_dispatch_status'] ?? ''));
        $this->assertSame('approved', (string) ($cancelled['status'] ?? ''));
        $this->assertStringContainsString('wrong landing page', (string) ($cancelled['live_blocked_reason'] ?? ''));

        $emptyRun = $this->marketing->runLiveExecutionDispatcher([
            'source' => 'cron',
            'limit_count' => 5,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('completed', (string) ($emptyRun['status'] ?? ''));
        $this->assertSame(0, (int) ($emptyRun['processed_count'] ?? -1));
        $this->assertContains('no_due_confirmed_live_queue_items', (array) ($emptyRun['result_json']['skipped'] ?? []));
        $this->assertSame('cancelled', (string) ($this->marketing->getExecutionQueueItem($queueId)['live_dispatch_status'] ?? ''));

        $this->marketing->confirmExecutionQueueForLiveDispatch($queueId, $this->userId, 'RUN LIVE');
        $run = $this->marketing->runLiveExecutionDispatcher([
            'source' => 'cron',
            'limit_count' => 5,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('completed', (string) ($run['status'] ?? ''));
        $this->assertSame(1, (int) ($run['succeeded_count'] ?? 0));

        $events = $this->marketing->listLiveExecutionEvents(['queue_id' => $queueId], 10, 0);
        $this->assertContains('live_blocked', array_column($events, 'event_type'));

        $page = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_channel_exports.php live dispatch cancel');
    }

    public function testPhaseOneHundredFortyFourLiveDispatcherPreviewShowsDueConfirmedItems(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Preview Live Dispatch Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_internal_webhook',
            'secret_reference' => 'vault://marketing/preview-dispatch',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_internal_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'dispatcher preview available'],
        ], $this->userId);
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId])['status'] ?? ''));
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId])['status'] ?? ''));

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => (int) $fixture['landing_page_id'],
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('-4 minutes')),
            'payload' => ['event' => 'preview_dispatch_live_execution'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved for due preview.', $this->userId));
        $this->marketing->confirmExecutionQueueForLiveDispatch($queueId, $this->userId, 'RUN LIVE');

        $preview = $this->marketing->listDueLiveDispatchQueue(5, 0);
        $this->assertCount(1, $preview);
        $this->assertSame($queueId, (int) ($preview[0]['id'] ?? 0));
        $this->assertSame('confirmed', (string) ($preview[0]['live_dispatch_status'] ?? ''));
        $this->assertGreaterThanOrEqual(0, (int) ($preview[0]['due_age_minutes'] ?? -1));

        $health = $this->marketing->getLiveQueueDispatcherHealth();
        $this->assertSame(1, (int) ($health['counts']['due_confirmed'] ?? 0));

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php due live dispatch preview');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Due Dispatch Queue', $body);
        $this->assertStringContainsString('Preview Live Dispatch Connector', $body);
        $this->assertStringContainsString('live queue #' . $queueId, $body);
    }

    public function testPhaseOneHundredThirtyFourControlledLiveExecutionPublishesLandingPage(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $landingPageId = (int) $fixture['landing_page_id'];
        $publicToken = (string) $fixture['public_token'];
        $this->assertTrue($this->marketing->unpublishLandingPage($landingPageId, $this->userId));
        $this->assertNull($this->marketing->getPublishedLandingPageByToken($publicToken));

        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'CRM Landing Publication Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'landing_page_publication', 'tokenized_publication', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_landing_publication',
            'secret_reference' => 'vault://marketing/landing-publication',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_landing_publication'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'landing publication adapter only'],
        ], $this->userId);

        $publishQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => $landingPageId,
            'payload' => ['publication_action' => 'publish', 'landing_page_id' => $landingPageId],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $publishApprovalId = $this->marketing->requestExecutionApproval($publishQueueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($publishApprovalId, 'approved', 'Approved controlled landing publication.', $this->userId));
        $blockedReadiness = $this->marketing->getMarketingLiveExecutionReadiness($publishQueueId);
        $this->assertContains('connector_live_preflight_required', (array) ($blockedReadiness['blocked_queue_items'][0]['readiness']['blocked_reasons'] ?? []));
        $this->assertSame(1, (int) ($blockedReadiness['counts']['blocked_live_queue_items'] ?? 0));
        $dryRun = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($dryRun['status'] ?? ''));
        $preflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($preflight['status'] ?? ''));
        $readyReadiness = $this->marketing->getMarketingLiveExecutionReadiness($publishQueueId);
        $this->assertSame(1, (int) ($readyReadiness['counts']['ready_live_queue_items'] ?? 0), json_encode($readyReadiness, JSON_PRETTY_PRINT));

        $publishAttempt = $this->marketing->runExecutionQueueItem($publishQueueId, ['attempt_mode' => 'live', 'live_confirmation' => 'RUN LIVE', 'created_by' => $this->userId]);
        $this->assertSame('success', $publishAttempt['status']);
        $publishResult = (array) ($publishAttempt['result_json']['adapter_result'] ?? []);
        $this->assertSame('crm_landing_publication', (string) ($publishResult['adapter_key'] ?? ''));
        $this->assertSame('publish', (string) ($publishResult['publication_action'] ?? ''));
        $this->assertTrue((bool) ($publishResult['landing_publication_changed'] ?? false));
        $this->assertFalse((bool) ($publishResult['external_api_called'] ?? true));
        $this->assertFalse((bool) ($publishResult['third_party_delivery'] ?? true));

        $publication = $this->marketing->getLandingPagePublication($landingPageId);
        $this->assertSame('published', (string) ($publication['status'] ?? ''));
        $this->assertSame($publicToken, (string) ($publication['public_token'] ?? ''));
        $this->assertNotNull($this->marketing->getPublishedLandingPageByToken($publicToken));

        $unpublishQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => $landingPageId,
            'payload' => ['publication_action' => 'unpublish', 'landing_page_id' => $landingPageId],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $unpublishApprovalId = $this->marketing->requestExecutionApproval($unpublishQueueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($unpublishApprovalId, 'approved', 'Approved controlled landing unpublish.', $this->userId));

        $unpublishAttempt = $this->marketing->runExecutionQueueItem($unpublishQueueId, ['attempt_mode' => 'live', 'live_confirmation' => 'RUN LIVE', 'created_by' => $this->userId]);
        $this->assertSame('success', $unpublishAttempt['status']);
        $unpublishResult = (array) ($unpublishAttempt['result_json']['adapter_result'] ?? []);
        $this->assertSame('unpublish', (string) ($unpublishResult['publication_action'] ?? ''));
        $this->assertTrue((bool) ($unpublishResult['landing_publication_changed'] ?? false));
        $this->assertFalse((bool) ($unpublishResult['external_api_called'] ?? true));

        $publication = $this->marketing->getLandingPagePublication($landingPageId);
        $this->assertSame('unpublished', (string) ($publication['status'] ?? ''));
        $this->assertNull($this->marketing->getPublishedLandingPageByToken($publicToken));
    }

    public function testPhaseOneHundredThirtyFiveLiveConnectorPreflightRequiresPolicyAndManager(): void
    {
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'CRM Landing Preflight Connector',
            'connector_type' => 'website_webhook',
            'status' => 'configured',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'setup_checklist' => ['landing page preview reviewed', 'operator owner assigned'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_landing_publication',
            'secret_reference' => 'vault://marketing/landing-preflight',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $dryRun = $this->marketing->runChannelConnectorTest($connectorId, [
            'test_mode' => 'dry_run',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('passed', (string) ($dryRun['status'] ?? ''));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->runChannelConnectorTest($connectorId, [
                'test_mode' => 'live_preflight',
                'created_by' => $this->userId,
            ]);
            $this->fail('Marketing writer should not run live connector preflight.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $blockedPreflight = $this->marketing->runChannelConnectorTest($connectorId, [
            'test_mode' => 'live_preflight',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('blocked', (string) ($blockedPreflight['status'] ?? ''));
        $this->assertContains('live_policy_not_ready', (array) ($blockedPreflight['result_json']['live_preflight_blockers'] ?? []));
        $this->assertFalse((bool) ($blockedPreflight['result_json']['external_api_called'] ?? true));

        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_landing_publication'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'live preflight required'],
        ], $this->userId);
        $passedPreflight = $this->marketing->runChannelConnectorTest($connectorId, [
            'test_mode' => 'live_preflight',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('passed', (string) ($passedPreflight['status'] ?? ''));
        $this->assertSame('live_preflight', (string) ($passedPreflight['test_mode'] ?? ''));
        $this->assertTrue((bool) ($passedPreflight['result_json']['live_preflight_only'] ?? false));
        $this->assertFalse((bool) ($passedPreflight['result_json']['external_api_called'] ?? true));
        $this->assertFalse((bool) ($passedPreflight['result_json']['third_party_delivery'] ?? true));
        $this->assertSame([], (array) ($passedPreflight['diagnostics_json']['live_preflight_blockers'] ?? ['unexpected']));

        $connector = $this->marketing->getChannelConnector($connectorId);
        $this->assertSame('passed', (string) ($connector['live_preflight_status'] ?? ''));
        $this->assertNotEmpty($connector['live_preflight_checked_at'] ?? null);
        $this->assertTrue((bool) ($connector['live_preflight_json']['can_run_live_queue'] ?? false));

        $diagnostics = $this->marketing->getChannelConnectorDiagnostics();
        $this->assertSame(1, (int) ($diagnostics['live_preflight_passed_connectors'] ?? 0));
        $this->assertTrue((bool) ($diagnostics['secret_safe'] ?? false));
        $this->assertStringNotContainsString('vault://marketing/landing-preflight', json_encode($diagnostics, JSON_UNESCAPED_SLASHES) ?: '');

        $page = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_channel_exports.php live connector preflight');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Run Live Preflight', $body);
        $this->assertStringContainsString('CRM Landing Preflight Connector', $body);
        $this->assertStringContainsString('live preflight passed', strtolower($body));
    }

    public function testPhaseOneHundredThirtySixControlledOutboundWebhookRecordsAttemptEvidence(): void
    {
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'CRM Outbound Webhook Connector',
            'connector_type' => 'website_webhook',
            'status' => 'configured',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'setup_checklist' => ['landing page preview reviewed', 'operator owner assigned'],
            'config' => [
                'endpoint_url' => 'http://127.0.0.1:1/marketing-live-webhook-test',
                'allowed_hosts' => ['127.0.0.1'],
                'http_method' => 'POST',
                'timeout_seconds' => 1,
                'max_retries' => 0,
                'headers' => [
                    'X-CRM-Test' => 'controlled-live-webhook',
                    'Authorization' => 'Bearer should-not-be-stored',
                ],
            ],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_outbound_webhook',
            'secret_reference' => 'vault://marketing/outbound-webhook',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $dryRun = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($dryRun['status'] ?? ''));
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_outbound_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'webhook preflight required'],
        ], $this->userId);
        $preflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($preflight['status'] ?? ''), json_encode($preflight, JSON_PRETTY_PRINT));
        $this->assertSame([], (array) ($preflight['diagnostics_json']['live_preflight_blockers'] ?? ['unexpected']));

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'payload' => [
                'event' => 'controlled_outbound_webhook_test',
                'api_token' => 'queue-secret-token',
            ],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved controlled outbound webhook attempt.', $this->userId));
        $readiness = $this->marketing->getMarketingLiveExecutionReadiness($queueId);
        $this->assertSame(1, (int) ($readiness['counts']['ready_live_queue_items'] ?? 0), json_encode($readiness, JSON_PRETTY_PRINT));

        $attempt = $this->marketing->runExecutionQueueItem($queueId, ['attempt_mode' => 'live', 'live_confirmation' => 'RUN LIVE', 'created_by' => $this->userId]);
        $this->assertSame('failed', (string) ($attempt['status'] ?? ''));
        $adapterResult = (array) ($attempt['result_json']['adapter_result'] ?? []);
        $this->assertSame('crm_outbound_webhook', (string) ($adapterResult['adapter_key'] ?? ''));
        $this->assertTrue((bool) ($adapterResult['external_api_called'] ?? false));
        $this->assertFalse((bool) ($adapterResult['third_party_delivery'] ?? true));
        $this->assertSame('failed', (string) ($adapterResult['webhook_status'] ?? ''));
        $this->assertSame('127.0.0.1', (string) ($adapterResult['endpoint_host'] ?? ''));
        $this->assertNotEmpty($adapterResult['endpoint_url_hash'] ?? '');
        $this->assertStringNotContainsString('queue-secret-token', json_encode($attempt, JSON_UNESCAPED_SLASHES) ?: '');
        $this->assertStringNotContainsString('should-not-be-stored', json_encode($attempt, JSON_UNESCAPED_SLASHES) ?: '');

        $webhookAttempts = $this->marketing->listLiveWebhookAttempts(['queue_id' => $queueId], 5, 0);
        $this->assertCount(1, $webhookAttempts);
        $webhookAttempt = $webhookAttempts[0];
        $this->assertSame('failed', (string) ($webhookAttempt['status'] ?? ''));
        $this->assertSame(1, (int) ($webhookAttempt['external_api_called'] ?? 0));
        $this->assertSame(0, (int) ($webhookAttempt['third_party_delivery'] ?? 1));
        $this->assertSame('[configured]', (string) ($webhookAttempt['request_payload_json']['payload']['api_token'] ?? ''));
        $this->assertArrayNotHasKey('Authorization', (array) ($webhookAttempt['request_headers_json'] ?? []));
        $this->assertStringNotContainsString('http://127.0.0.1:1/marketing-live-webhook-test', json_encode($webhookAttempt, JSON_UNESCAPED_SLASHES) ?: '');

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php outbound webhook evidence');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Outbound Webhook Evidence', $body);
        $this->assertStringContainsString('CRM Outbound Webhook Connector', $body);
        $this->assertStringNotContainsString('queue-secret-token', $body);
        $this->assertStringNotContainsString('should-not-be-stored', $body);
    }

    public function testPhaseOneHundredFortyFiveEncryptedConnectorSecretFeedsOutboundWebhookSafely(): void
    {
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Authenticated Outbound Webhook Connector',
            'connector_type' => 'website_webhook',
            'status' => 'configured',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'setup_checklist' => ['credential owner assigned', 'webhook host allowlisted'],
            'config' => [
                'endpoint_url' => 'http://127.0.0.1:1/marketing-live-auth-webhook-test',
                'allowed_hosts' => ['127.0.0.1'],
                'http_method' => 'POST',
                'timeout_seconds' => 1,
                'max_retries' => 0,
                'requires_secret' => true,
            ],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_outbound_webhook',
            'secret_reference' => 'vault://marketing/authenticated-webhook',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_outbound_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'encrypted secret stored'],
        ], $this->userId);
        $dryRun = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($dryRun['status'] ?? ''));

        $blockedPreflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('blocked', (string) ($blockedPreflight['status'] ?? ''));
        $this->assertContains('connector_secret_value_required', (array) ($blockedPreflight['diagnostics_json']['live_preflight_blockers'] ?? []));

        $summary = $this->marketing->saveChannelConnectorLiveSecret($connectorId, [
            'secret_reference' => 'vault://marketing/authenticated-webhook',
            'secret_type' => 'bearer_token',
            'header_name' => 'Authorization',
            'header_prefix' => 'Bearer',
            'secret_value' => 'super-live-webhook-token',
        ], $this->userId);
        $this->assertTrue((bool) ($summary['configured'] ?? false));
        $this->assertSame('Authorization', (string) ($summary['header_name'] ?? ''));
        $this->assertFalse((bool) ($summary['secret_value_visible'] ?? true));
        $secretRow = Database::queryOne(
            "SELECT encrypted_value FROM marketing_live_connector_secrets WHERE workspace_id = ? AND connector_id = ?",
            [$this->workspaceId, $connectorId]
        );
        $this->assertNotEmpty($secretRow['encrypted_value'] ?? '');
        $this->assertStringNotContainsString('super-live-webhook-token', (string) ($secretRow['encrypted_value'] ?? ''));

        $preflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($preflight['status'] ?? ''), json_encode($preflight, JSON_PRETTY_PRINT));
        $this->assertSame([], (array) ($preflight['diagnostics_json']['live_preflight_blockers'] ?? ['unexpected']));
        $this->assertTrue((bool) ($preflight['diagnostics_json']['secret']['configured'] ?? false));
        $this->assertStringNotContainsString('super-live-webhook-token', json_encode($preflight, JSON_UNESCAPED_SLASHES) ?: '');

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'payload' => ['event' => 'authenticated_outbound_webhook_test'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved authenticated outbound webhook attempt.', $this->userId));
        $attempt = $this->marketing->runExecutionQueueItem($queueId, ['attempt_mode' => 'live', 'live_confirmation' => 'RUN LIVE', 'created_by' => $this->userId]);
        $adapterResult = (array) ($attempt['result_json']['adapter_result'] ?? []);
        $this->assertSame('crm_outbound_webhook', (string) ($adapterResult['adapter_key'] ?? ''));
        $this->assertTrue((bool) ($adapterResult['external_api_called'] ?? false));
        $this->assertTrue((bool) ($adapterResult['secret_reference_resolved'] ?? false));

        $webhookAttempts = $this->marketing->listLiveWebhookAttempts(['queue_id' => $queueId], 5, 0);
        $this->assertCount(1, $webhookAttempts);
        $this->assertSame('[configured]', (string) ($webhookAttempts[0]['request_headers_json']['Authorization'] ?? ''));
        $this->assertTrue((bool) ($webhookAttempts[0]['metadata_json']['secret']['configured'] ?? false));
        $this->assertStringNotContainsString('super-live-webhook-token', json_encode($webhookAttempts[0], JSON_UNESCAPED_SLASHES) ?: '');

        $page = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_channel_exports.php encrypted connector secret');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Save Encrypted Secret', $body);
        $this->assertStringContainsString('Secret Store: Stored', $body);
        $this->assertStringNotContainsString('super-live-webhook-token', $body);
    }

    public function testPhaseOneHundredFortySixLiveConnectorSecretRotationVerificationAndRevocation(): void
    {
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Rotating Outbound Webhook Connector',
            'connector_type' => 'website_webhook',
            'status' => 'configured',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'setup_checklist' => ['credential owner assigned', 'rotation runbook reviewed'],
            'config' => [
                'endpoint_url' => 'http://127.0.0.1:1/marketing-live-rotating-webhook-test',
                'allowed_hosts' => ['127.0.0.1'],
                'http_method' => 'POST',
                'timeout_seconds' => 1,
                'requires_secret' => true,
            ],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_outbound_webhook',
            'secret_reference' => 'vault://marketing/rotating-webhook',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_outbound_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'secret rotation controls available'],
        ], $this->userId);
        $dryRun = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($dryRun['status'] ?? ''));

        $initial = $this->marketing->saveChannelConnectorLiveSecret($connectorId, [
            'secret_reference' => 'vault://marketing/rotating-webhook',
            'secret_type' => 'bearer_token',
            'header_name' => 'Authorization',
            'header_prefix' => 'Bearer',
            'secret_value' => 'first-rotating-token',
        ], $this->userId);
        $this->assertTrue((bool) ($initial['configured'] ?? false));

        $verified = $this->marketing->verifyChannelConnectorLiveSecret($connectorId, $this->userId);
        $this->assertSame('passed', (string) ($verified['verification_status'] ?? ''));
        $this->assertTrue((bool) ($verified['configured'] ?? false));
        $this->assertStringNotContainsString('first-rotating-token', json_encode($verified, JSON_UNESCAPED_SLASHES) ?: '');

        $rotated = $this->marketing->saveChannelConnectorLiveSecret($connectorId, [
            'secret_reference' => 'vault://marketing/rotating-webhook',
            'secret_type' => 'bearer_token',
            'header_name' => 'Authorization',
            'header_prefix' => 'Bearer',
            'secret_value' => 'second-rotating-token',
        ], $this->userId);
        $this->assertTrue((bool) ($rotated['configured'] ?? false));
        $this->assertNotEmpty($rotated['rotated_at'] ?? null);
        $secretRow = Database::queryOne(
            "SELECT encrypted_value, last_verification_status, rotated_at FROM marketing_live_connector_secrets WHERE workspace_id = ? AND connector_id = ? AND status = 'active'",
            [$this->workspaceId, $connectorId]
        );
        $this->assertSame('passed', (string) ($secretRow['last_verification_status'] ?? ''));
        $this->assertNotEmpty($secretRow['rotated_at'] ?? null);
        $this->assertStringNotContainsString('first-rotating-token', (string) ($secretRow['encrypted_value'] ?? ''));
        $this->assertStringNotContainsString('second-rotating-token', (string) ($secretRow['encrypted_value'] ?? ''));

        $preflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($preflight['status'] ?? ''), json_encode($preflight, JSON_PRETTY_PRINT));
        $this->assertTrue((bool) ($preflight['diagnostics_json']['secret']['configured'] ?? false));

        $revoked = $this->marketing->revokeChannelConnectorLiveSecret($connectorId, $this->userId, 'Credential rotated out of service.');
        $this->assertTrue((bool) ($revoked['revoked'] ?? false));
        $connector = $this->marketing->getChannelConnector($connectorId);
        $this->assertSame(0, (int) ($connector['live_enabled'] ?? 1));
        $this->assertSame('blocked', (string) ($connector['secret_status'] ?? ''));
        $this->assertContains('connector_secret_revoked', (array) ($connector['live_preflight_json']['live_preflight_blockers'] ?? []));

        $blockedPreflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('blocked', (string) ($blockedPreflight['status'] ?? ''));
        $this->assertContains('connector_live_not_enabled', (array) ($blockedPreflight['diagnostics_json']['live_preflight_blockers'] ?? []));

        $events = $this->marketing->listChannelConnectorSecretEvents($connectorId, 10, 0);
        $eventTypes = array_map(static fn(array $event): string => (string) ($event['event_type'] ?? ''), $events);
        $this->assertContains('saved', $eventTypes);
        $this->assertContains('verified', $eventTypes);
        $this->assertContains('rotated', $eventTypes);
        $this->assertContains('revoked', $eventTypes);
        $this->assertStringNotContainsString('second-rotating-token', json_encode($events, JSON_UNESCAPED_SLASHES) ?: '');

        $page = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_channel_exports.php secret rotation controls');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Verify Stored Secret', $body);
        $this->assertStringContainsString('Revoke Stored Secret', $body);
        $this->assertStringContainsString('credential event(s)', $body);
        $this->assertStringNotContainsString('first-rotating-token', $body);
        $this->assertStringNotContainsString('second-rotating-token', $body);
    }

    public function testPhaseOneHundredFortySevenLiveConnectorSecretFreshnessBlocksPreflight(): void
    {
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Freshness Guard Webhook Connector',
            'connector_type' => 'website_webhook',
            'status' => 'configured',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'setup_checklist' => ['credential expiry policy set', 'rotation owner assigned'],
            'config' => [
                'endpoint_url' => 'http://127.0.0.1:1/marketing-live-freshness-webhook-test',
                'allowed_hosts' => ['127.0.0.1'],
                'http_method' => 'POST',
                'timeout_seconds' => 1,
                'requires_secret' => true,
            ],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_outbound_webhook',
            'secret_reference' => 'vault://marketing/freshness-webhook',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_outbound_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'credential freshness enforced'],
        ], $this->userId);
        $dryRun = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($dryRun['status'] ?? ''));

        $expired = $this->marketing->saveChannelConnectorLiveSecret($connectorId, [
            'secret_reference' => 'vault://marketing/freshness-webhook',
            'secret_type' => 'bearer_token',
            'header_name' => 'Authorization',
            'header_prefix' => 'Bearer',
            'secret_value' => 'expired-freshness-token',
            'expires_at' => date('Y-m-d\TH:i', strtotime('-1 day')),
        ], $this->userId);
        $this->assertSame('expired', (string) ($expired['freshness_status'] ?? ''));
        $expiredPreflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('blocked', (string) ($expiredPreflight['status'] ?? ''));
        $this->assertContains('connector_secret_expired', (array) ($expiredPreflight['diagnostics_json']['live_preflight_blockers'] ?? []));
        $this->assertSame('expired', (string) ($expiredPreflight['diagnostics_json']['secret']['freshness_status'] ?? ''));
        $this->assertStringNotContainsString('expired-freshness-token', json_encode($expiredPreflight, JSON_UNESCAPED_SLASHES) ?: '');

        $fresh = $this->marketing->saveChannelConnectorLiveSecret($connectorId, [
            'secret_reference' => 'vault://marketing/freshness-webhook',
            'secret_type' => 'bearer_token',
            'header_name' => 'Authorization',
            'header_prefix' => 'Bearer',
            'secret_value' => 'fresh-freshness-token',
            'expires_at' => date('Y-m-d\TH:i', strtotime('+30 days')),
            'rotation_interval_days' => 90,
        ], $this->userId);
        $this->assertSame('fresh', (string) ($fresh['freshness_status'] ?? ''));
        $freshPreflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($freshPreflight['status'] ?? ''), json_encode($freshPreflight, JSON_PRETTY_PRINT));
        $this->assertSame([], (array) ($freshPreflight['diagnostics_json']['live_preflight_blockers'] ?? ['unexpected']));

        $rotationDue = $this->marketing->saveChannelConnectorLiveSecret($connectorId, [
            'secret_reference' => 'vault://marketing/freshness-webhook',
            'secret_type' => 'bearer_token',
            'header_name' => 'Authorization',
            'header_prefix' => 'Bearer',
            'secret_value' => 'rotation-due-freshness-token',
            'expires_at' => date('Y-m-d\TH:i', strtotime('+30 days')),
            'rotation_due_at' => date('Y-m-d\TH:i', strtotime('-1 hour')),
        ], $this->userId);
        $this->assertSame('rotation_due', (string) ($rotationDue['freshness_status'] ?? ''));
        $duePreflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('blocked', (string) ($duePreflight['status'] ?? ''));
        $this->assertContains('connector_secret_rotation_due', (array) ($duePreflight['diagnostics_json']['live_preflight_blockers'] ?? []));
        $this->assertSame('rotation_due', (string) ($duePreflight['diagnostics_json']['secret']['freshness_status'] ?? ''));
        $this->assertStringNotContainsString('rotation-due-freshness-token', json_encode($duePreflight, JSON_UNESCAPED_SLASHES) ?: '');

        $page = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_channel_exports.php credential freshness controls');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Expires At', $body);
        $this->assertStringContainsString('Rotation Due At', $body);
        $this->assertStringContainsString('Rotation Interval Days', $body);
        $this->assertStringNotContainsString('fresh-freshness-token', $body);
        $this->assertStringNotContainsString('rotation-due-freshness-token', $body);
    }

    public function testPhaseOneHundredFortyEightConnectorHealthProbeFeedsLivePreflight(): void
    {
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Health Checked Webhook Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'setup_checklist' => ['credential owner assigned', 'webhook health reviewed'],
            'config' => [
                'endpoint_url' => 'http://127.0.0.1:1/marketing-live-health-webhook-test',
                'allowed_hosts' => ['127.0.0.1'],
                'http_method' => 'POST',
                'timeout_seconds' => 1,
                'requires_secret' => true,
            ],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_outbound_webhook',
            'secret_reference' => 'vault://marketing/health-webhook',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_outbound_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector health probe required before dispatch'],
        ], $this->userId);
        $dryRun = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($dryRun['status'] ?? ''));
        $this->marketing->saveChannelConnectorLiveSecret($connectorId, [
            'secret_reference' => 'vault://marketing/health-webhook',
            'secret_type' => 'bearer_token',
            'header_name' => 'Authorization',
            'header_prefix' => 'Bearer',
            'secret_value' => 'health-probe-live-token',
            'expires_at' => date('Y-m-d\TH:i', strtotime('+30 days')),
        ], $this->userId);

        $healthyProbe = $this->marketing->runChannelConnectorHealthProbe($connectorId, [
            'probe_mode' => 'local_adapter',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('healthy', (string) ($healthyProbe['status'] ?? ''), json_encode($healthyProbe, JSON_PRETTY_PRINT));
        $this->assertGreaterThanOrEqual(85, (int) ($healthyProbe['health_score'] ?? 0));
        $this->assertSame(0, (int) ($healthyProbe['external_api_called'] ?? 1));
        $this->assertStringNotContainsString('health-probe-live-token', json_encode($healthyProbe, JSON_UNESCAPED_SLASHES) ?: '');

        $passingPreflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($passingPreflight['status'] ?? ''), json_encode($passingPreflight, JSON_PRETTY_PRINT));
        $this->assertSame('healthy', (string) ($passingPreflight['diagnostics_json']['latest_health_probe_status'] ?? ''));
        $this->assertSame([], (array) ($passingPreflight['diagnostics_json']['latest_health_probe_blockers'] ?? []));

        $this->marketing->updateChannelConnector($connectorId, [
            'config' => [
                'endpoint_url' => 'http://127.0.0.1:1/marketing-live-health-webhook-test',
                'allowed_hosts' => ['example.com'],
                'http_method' => 'POST',
                'timeout_seconds' => 1,
                'requires_secret' => true,
            ],
        ]);
        $blockedProbe = $this->marketing->runChannelConnectorHealthProbe($connectorId, [
            'probe_mode' => 'local_adapter',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('blocked', (string) ($blockedProbe['status'] ?? ''));
        $this->assertContains('webhook_endpoint_safe', (array) ($blockedProbe['blockers_json'] ?? []));

        $this->marketing->updateChannelConnector($connectorId, [
            'config' => [
                'endpoint_url' => 'http://127.0.0.1:1/marketing-live-health-webhook-test',
                'allowed_hosts' => ['127.0.0.1'],
                'http_method' => 'POST',
                'timeout_seconds' => 1,
                'requires_secret' => true,
            ],
        ]);
        $blockedPreflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('blocked', (string) ($blockedPreflight['status'] ?? ''));
        $this->assertContains('connector_health_blocked', (array) ($blockedPreflight['diagnostics_json']['live_preflight_blockers'] ?? []));
        $this->assertSame('blocked', (string) ($blockedPreflight['diagnostics_json']['latest_health_probe_status'] ?? ''));
        $this->assertStringNotContainsString('health-probe-live-token', json_encode($blockedPreflight, JSON_UNESCAPED_SLASHES) ?: '');

        $page = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_channel_exports.php connector health probes');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Run Health Probe', $body);
        $this->assertStringContainsString('Health:', $body);
        $this->assertStringNotContainsString('health-probe-live-token', $body);
    }

    public function testPhaseOneHundredFortyNineConnectorLiveThrottlesBlockExcessDispatch(): void
    {
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Throttled Webhook Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'setup_checklist' => ['credential owner assigned', 'throttle reviewed'],
            'config' => [
                'endpoint_url' => 'http://127.0.0.1:1/marketing-live-throttle-webhook-test',
                'allowed_hosts' => ['127.0.0.1'],
                'http_method' => 'POST',
                'timeout_seconds' => 1,
                'requires_secret' => true,
            ],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_outbound_webhook',
            'live_hourly_cap' => 1,
            'live_daily_cap' => 0,
            'live_cooldown_seconds' => 0,
            'secret_reference' => 'vault://marketing/throttle-webhook',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_outbound_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector throttles enforced'],
        ], $this->userId);
        $dryRun = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($dryRun['status'] ?? ''));
        $this->marketing->saveChannelConnectorLiveSecret($connectorId, [
            'secret_reference' => 'vault://marketing/throttle-webhook',
            'secret_type' => 'bearer_token',
            'header_name' => 'Authorization',
            'header_prefix' => 'Bearer',
            'secret_value' => 'connector-throttle-token',
            'expires_at' => date('Y-m-d\TH:i', strtotime('+30 days')),
        ], $this->userId);
        $preflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($preflight['status'] ?? ''), json_encode($preflight, JSON_PRETTY_PRINT));
        $this->assertSame(1, (int) ($preflight['diagnostics_json']['connector_live_throttle']['hourly_cap'] ?? 0));

        $firstQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'payload' => ['event' => 'connector_throttle_first'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $firstApprovalId = $this->marketing->requestExecutionApproval($firstQueueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($firstApprovalId, 'approved', 'Approved first throttled attempt.', $this->userId));
        $firstReadiness = $this->marketing->getMarketingLiveExecutionReadiness($firstQueueId);
        $this->assertSame(1, (int) ($firstReadiness['counts']['ready_live_queue_items'] ?? 0), json_encode($firstReadiness, JSON_PRETTY_PRINT));
        $firstAttempt = $this->marketing->runExecutionQueueItem($firstQueueId, ['attempt_mode' => 'live', 'live_confirmation' => 'RUN LIVE', 'created_by' => $this->userId]);
        $this->assertSame('failed', (string) ($firstAttempt['status'] ?? ''), json_encode($firstAttempt, JSON_PRETTY_PRINT));
        $connectorAfterFirst = $this->marketing->getChannelConnector($connectorId);
        $this->assertNotEmpty($connectorAfterFirst['last_live_attempt_at'] ?? null);
        $this->assertSame(1, (int) ($connectorAfterFirst['live_throttle_json']['hourly_used'] ?? 0));

        $secondQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'payload' => ['event' => 'connector_throttle_second'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $secondApprovalId = $this->marketing->requestExecutionApproval($secondQueueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($secondApprovalId, 'approved', 'Approved second throttled attempt.', $this->userId));
        $blockedReadiness = $this->marketing->getMarketingLiveExecutionReadiness($secondQueueId);
        $this->assertSame(1, (int) ($blockedReadiness['counts']['blocked_live_queue_items'] ?? 0), json_encode($blockedReadiness, JSON_PRETTY_PRINT));
        $this->assertContains('connector_hourly_live_cap_exceeded', (array) ($blockedReadiness['blocked_queue_items'][0]['readiness']['blocked_reasons'] ?? []));
        $blockedAttempt = $this->marketing->runExecutionQueueItem($secondQueueId, ['attempt_mode' => 'live', 'live_confirmation' => 'RUN LIVE', 'created_by' => $this->userId]);
        $this->assertSame('blocked', (string) ($blockedAttempt['status'] ?? ''));
        $this->assertContains('connector_hourly_live_cap_exceeded', (array) ($blockedAttempt['result_json']['blocked_reasons'] ?? []));
        $this->assertStringNotContainsString('connector-throttle-token', json_encode($blockedAttempt, JSON_UNESCAPED_SLASHES) ?: '');

        $this->marketing->updateChannelConnector($connectorId, [
            'live_hourly_cap' => 0,
            'live_daily_cap' => 0,
            'live_cooldown_seconds' => 3600,
        ]);
        $thirdQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'payload' => ['event' => 'connector_throttle_cooldown'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $thirdApprovalId = $this->marketing->requestExecutionApproval($thirdQueueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($thirdApprovalId, 'approved', 'Approved cooldown attempt.', $this->userId));
        $cooldownReadiness = $this->marketing->getMarketingLiveExecutionReadiness($thirdQueueId);
        $this->assertContains('connector_live_cooldown_active', (array) ($cooldownReadiness['blocked_queue_items'][0]['readiness']['blocked_reasons'] ?? []));
        $this->assertGreaterThan(0, (int) ($cooldownReadiness['blocked_queue_items'][0]['readiness']['connector_live_throttle']['cooldown_remaining_seconds'] ?? 0));

        $page = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_channel_exports.php connector live throttles');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Hourly Live Cap', $body);
        $this->assertStringContainsString('Daily Live Cap', $body);
        $this->assertStringContainsString('Cooldown Seconds', $body);
        $this->assertStringNotContainsString('connector-throttle-token', $body);
    }

    public function testPhaseOneHundredFiftyLiveQueueRetriesRespectBackoffAndExhaustion(): void
    {
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Retry Webhook Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'setup_checklist' => ['credential owner assigned', 'retry policy reviewed'],
            'config' => [
                'endpoint_url' => 'http://127.0.0.1:1/marketing-live-retry-webhook-test',
                'allowed_hosts' => ['127.0.0.1'],
                'http_method' => 'POST',
                'timeout_seconds' => 1,
                'requires_secret' => true,
            ],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_outbound_webhook',
            'secret_reference' => 'vault://marketing/retry-webhook',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_outbound_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'retry backoff enforced'],
        ], $this->userId);
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId])['status'] ?? ''));
        $this->marketing->saveChannelConnectorLiveSecret($connectorId, [
            'secret_reference' => 'vault://marketing/retry-webhook',
            'secret_type' => 'bearer_token',
            'header_name' => 'Authorization',
            'header_prefix' => 'Bearer',
            'secret_value' => 'connector-retry-token',
            'expires_at' => date('Y-m-d\TH:i', strtotime('+30 days')),
        ], $this->userId);
        $preflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($preflight['status'] ?? ''), json_encode($preflight, JSON_PRETTY_PRINT));

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'payload' => ['event' => 'connector_retry_first'],
            'live_rerun_policy' => 'allow_failed_retry',
            'live_max_retries' => 1,
            'live_retry_backoff_seconds' => 3600,
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved retry test.', $this->userId));
        $this->marketing->confirmExecutionQueueForLiveDispatch($queueId, $this->userId, 'RUN LIVE');

        $firstAttempt = $this->marketing->runExecutionQueueItem($queueId, [
            'attempt_mode' => 'live',
            'live_confirmation' => 'RUN LIVE',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('failed', (string) ($firstAttempt['status'] ?? ''), json_encode($firstAttempt, JSON_PRETTY_PRINT));
        $scheduled = $this->marketing->getExecutionQueueItem($queueId);
        $this->assertSame('queued', (string) ($scheduled['status'] ?? ''));
        $this->assertSame('confirmed', (string) ($scheduled['live_dispatch_status'] ?? ''));
        $this->assertSame('scheduled', (string) ($scheduled['live_retry_status'] ?? ''));
        $this->assertSame(1, (int) ($scheduled['live_retry_count'] ?? 0));
        $this->assertNotEmpty($scheduled['live_retry_after'] ?? null);
        $this->assertSame('failed_live_attempt_scheduled_for_retry', (string) ($scheduled['live_retry_json']['reason'] ?? ''));

        $backoffReadiness = $this->marketing->getMarketingLiveExecutionReadiness($queueId);
        $this->assertContains('live_retry_backoff_active', (array) ($backoffReadiness['blocked_queue_items'][0]['readiness']['blocked_reasons'] ?? []), json_encode($backoffReadiness, JSON_PRETTY_PRINT));
        $this->assertSame([], $this->marketing->listDueLiveDispatchQueue(10, 0));
        $health = $this->marketing->getLiveQueueDispatcherHealth();
        $this->assertSame(1, (int) ($health['counts']['scheduled_retries'] ?? 0), json_encode($health, JSON_PRETTY_PRINT));

        Database::execute(
            "UPDATE marketing_execution_queue
             SET live_retry_after = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
             WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $queueId]
        );
        $this->assertCount(1, $this->marketing->listDueLiveDispatchQueue(10, 0));
        $dispatchRun = $this->marketing->runLiveExecutionDispatcher([
            'source' => 'manual',
            'limit_count' => 5,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('failed', (string) ($dispatchRun['status'] ?? ''), json_encode($dispatchRun, JSON_PRETTY_PRINT));
        $this->assertSame(1, (int) ($dispatchRun['processed_count'] ?? 0));
        $exhausted = $this->marketing->getExecutionQueueItem($queueId);
        $this->assertSame('failed', (string) ($exhausted['status'] ?? ''));
        $this->assertSame('failed', (string) ($exhausted['live_dispatch_status'] ?? ''));
        $this->assertSame('exhausted', (string) ($exhausted['live_retry_status'] ?? ''));
        $this->assertSame(1, (int) ($exhausted['live_retry_count'] ?? 0));
        $this->assertStringNotContainsString('connector-retry-token', json_encode($dispatchRun, JSON_UNESCAPED_SLASHES) ?: '');

        $page = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_channel_exports.php live retry controls');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Live Rerun Policy', $body);
        $this->assertStringContainsString('Max Live Retries', $body);
        $this->assertStringContainsString('Retry Backoff Seconds', $body);
        $this->assertStringNotContainsString('connector-retry-token', $body);
    }

    public function testPhaseOneHundredFiftyOneLiveExecutionOutcomesReconcileWebhookEvidence(): void
    {
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Outcome Webhook Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'setup_checklist' => ['outcome evidence reviewed'],
            'config' => [
                'endpoint_url' => 'https://example.com/marketing-outcome',
                'allowed_hosts' => ['example.com'],
                'http_method' => 'POST',
                'requires_secret' => false,
            ],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_outbound_webhook',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'approved',
            'connector_id' => $connectorId,
            'payload' => ['event' => 'outcome_sync'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        Database::execute(
            "UPDATE marketing_execution_queue
             SET status = 'succeeded',
                 live_dispatch_status = 'completed',
                 live_outcome_status = 'unknown'
             WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $queueId]
        );
        Database::execute(
            "INSERT INTO marketing_live_webhook_attempts
             (workspace_id, uuid, queue_id, connector_id, status, http_method, endpoint_host, endpoint_url_hash,
              request_headers_json, request_payload_json, response_status_code, response_preview, duration_ms,
              attempt_count, external_api_called, third_party_delivery, metadata_json, attempted_at, created_by)
             VALUES (?, UUID(), ?, ?, 'sent', 'POST', 'example.com', SHA2('https://example.com/marketing-outcome', 256),
                     JSON_OBJECT('Content-Type', 'application/json'), JSON_OBJECT('event', 'outcome_sync'), 202, 'accepted',
                     120, 1, 1, 0, JSON_OBJECT('raw_secret_values_visible', false), NOW(), ?)",
            [$this->workspaceId, $queueId, $connectorId, $this->userId]
        );

        $summary = $this->marketing->syncLiveExecutionQueueOutcomes($queueId);
        $this->assertSame(1, (int) ($summary['synced'] ?? 0), json_encode($summary, JSON_PRETTY_PRINT));
        $this->assertSame(1, (int) ($summary['counts']['delivered'] ?? 0));
        $queue = $this->marketing->getExecutionQueueItem($queueId);
        $this->assertSame('delivered', (string) ($queue['live_outcome_status'] ?? ''));
        $this->assertSame('sent', (string) ($queue['live_outcome_json']['webhook_status'] ?? ''));
        $this->assertSame('example.com', (string) ($queue['live_outcome_json']['endpoint_host'] ?? ''));
        $this->assertNotEmpty($queue['live_outcome_checked_at'] ?? null);
        $this->assertStringNotContainsString('Authorization', json_encode($queue['live_outcome_json'], JSON_UNESCAPED_SLASHES) ?: '');

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php live outcome sync');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Outcome sync', $body);
    }

    public function testPhaseOneHundredFiftyTwoLiveOutcomeSyncWorkerRecordsRuns(): void
    {
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Outcome Worker Webhook Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'setup_checklist' => ['outcome worker evidence reviewed'],
            'config' => [
                'endpoint_url' => 'https://example.com/marketing-outcome-worker',
                'allowed_hosts' => ['example.com'],
                'http_method' => 'POST',
                'requires_secret' => false,
            ],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_outbound_webhook',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'approved',
            'connector_id' => $connectorId,
            'payload' => ['event' => 'outcome_sync_worker'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        Database::execute(
            "UPDATE marketing_execution_queue
             SET status = 'succeeded',
                 live_dispatch_status = 'completed',
                 live_outcome_status = 'unknown'
             WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, $queueId]
        );
        Database::execute(
            "INSERT INTO marketing_live_webhook_attempts
             (workspace_id, uuid, queue_id, connector_id, status, http_method, endpoint_host, endpoint_url_hash,
              request_headers_json, request_payload_json, response_status_code, response_preview, duration_ms,
              attempt_count, external_api_called, third_party_delivery, metadata_json, attempted_at, created_by)
             VALUES (?, UUID(), ?, ?, 'sent', 'POST', 'example.com', SHA2('https://example.com/marketing-outcome-worker', 256),
                     JSON_OBJECT('Content-Type', 'application/json'), JSON_OBJECT('event', 'outcome_sync_worker'), 202, 'accepted',
                     95, 1, 1, 0, JSON_OBJECT('raw_secret_values_visible', false), NOW(), ?)",
            [$this->workspaceId, $queueId, $connectorId, $this->userId]
        );

        $run = $this->marketing->runLiveOutcomeSyncWorker([
            'source' => 'manual',
            'limit' => 10,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('completed', (string) ($run['status'] ?? ''));
        $this->assertSame(1, (int) ($run['processed_count'] ?? 0), json_encode($run, JSON_PRETTY_PRINT));
        $this->assertSame(1, (int) ($run['delivered_count'] ?? 0));
        $this->assertSame(1, (int) ($run['result_json']['counts']['delivered'] ?? 0));
        $this->assertFalse((bool) ($run['result_json']['raw_secret_values_visible'] ?? true));

        $queue = $this->marketing->getExecutionQueueItem($queueId);
        $this->assertSame('delivered', (string) ($queue['live_outcome_status'] ?? ''));
        $this->assertSame('sent', (string) ($queue['live_outcome_json']['webhook_status'] ?? ''));

        $runs = $this->marketing->listLiveOutcomeSyncRuns([], 5, 0);
        $this->assertNotEmpty($runs);
        $this->assertSame((int) ($run['id'] ?? 0), (int) ($runs[0]['id'] ?? 0));

        $schedule = $this->marketing->updateLiveOutcomeSyncSchedule([
            'status' => 'active',
            'expected_interval_minutes' => 5,
            'max_stale_minutes' => 30,
            'max_queue_items_per_run' => 250,
        ], $this->userId);
        $this->assertSame('active', (string) ($schedule['status'] ?? ''));
        $this->assertStringContainsString('sync_marketing_live_outcomes.php', (string) ($schedule['command'] ?? ''));

        $blocked = $this->marketing->pauseLiveOutcomeSyncWorker($this->userId, 'Outcome sync pause validation.');
        $this->assertTrue((bool) ($blocked['emergency_paused'] ?? false));
        $resumed = $this->marketing->resumeLiveOutcomeSyncWorker($this->userId);
        $this->assertFalse((bool) ($resumed['emergency_paused'] ?? true));

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php live outcome sync worker');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Live Outcome Sync Worker', $body);
        $this->assertStringContainsString('sync_marketing_live_outcomes.php', $body);
        $this->assertStringContainsString('Run Outcome Sync', $body);
    }

    public function testPhaseOneHundredFiftyThreeLiveOutcomeSyncCliHelpIsSafe(): void
    {
        $script = realpath(__DIR__ . '/../../../scripts/sync_marketing_live_outcomes.php');
        $this->assertIsString($script);
        $output = [];
        $exitCode = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help', $output, $exitCode);
        $body = implode("\n", $output);

        $this->assertSame(0, $exitCode, $body);
        $this->assertStringContainsString('Marketing Live Outcome Sync', $body);
        $this->assertStringContainsString('--workspace-id', $body);
        $this->assertStringContainsString('--user-id', $body);
        $this->assertStringContainsString('No raw secrets are printed', $body);
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $body);
    }

    public function testPhaseOneHundredFiftyFourLiveOrchestratorRunsWorkerChain(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'Orchestrated Live Connector',
            'connector_type' => 'website_webhook',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['webhook_payload_preview', 'dry_run'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_internal_webhook',
            'secret_reference' => 'vault://marketing/orchestrated-live',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['website_webhook'],
            'allowed_adapter_keys' => ['crm_internal_webhook'],
            'daily_live_cap' => 25,
            'safety_checklist' => ['policy enabled', 'connector verified', 'orchestrator chain ready'],
        ], $this->userId);
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId])['status'] ?? ''));
        $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId])['status'] ?? ''));

        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'website_webhook',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'landing_page_id' => (int) $fixture['landing_page_id'],
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('-3 minutes')),
            'payload' => ['event' => 'orchestrated_live_execution'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved for orchestrator test.', $this->userId));
        $this->marketing->confirmExecutionQueueForLiveDispatch($queueId, $this->userId, 'RUN LIVE');

        $run = $this->marketing->runLiveExecutionOrchestrator([
            'source' => 'manual',
            'limit' => 5,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('completed', (string) ($run['status'] ?? ''), json_encode($run, JSON_PRETTY_PRINT));
        $this->assertGreaterThanOrEqual(2, (int) ($run['processed_count'] ?? 0));
        $this->assertGreaterThanOrEqual(2, (int) ($run['succeeded_count'] ?? 0));
        $this->assertGreaterThan(0, (int) ($run['dispatch_run_id'] ?? 0));
        $this->assertGreaterThan(0, (int) ($run['email_worker_run_id'] ?? 0));
        $this->assertGreaterThan(0, (int) ($run['outcome_sync_run_id'] ?? 0));
        $this->assertSame('completed', (string) ($run['result_json']['steps']['dispatcher']['status'] ?? ''));
        $this->assertSame('completed', (string) ($run['result_json']['steps']['email_worker']['status'] ?? ''));
        $this->assertSame('completed', (string) ($run['result_json']['steps']['outcome_sync']['status'] ?? ''));
        $this->assertFalse((bool) ($run['result_json']['raw_secret_values_visible'] ?? true));

        $queue = $this->marketing->getExecutionQueueItem($queueId);
        $this->assertSame('succeeded', (string) ($queue['status'] ?? ''));
        $this->assertSame('completed', (string) ($queue['live_dispatch_status'] ?? ''));
        $this->assertSame('delivered', (string) ($queue['live_outcome_status'] ?? ''));

        $runs = $this->marketing->listLiveOrchestrationRuns([], 5, 0);
        $this->assertNotEmpty($runs);
        $this->assertSame((int) ($run['id'] ?? 0), (int) ($runs[0]['id'] ?? 0));

        $schedule = $this->marketing->updateLiveOrchestratorSchedule([
            'status' => 'active',
            'expected_interval_minutes' => 5,
            'max_stale_minutes' => 30,
            'max_queue_items_per_run' => 25,
        ], $this->userId);
        $this->assertSame('active', (string) ($schedule['status'] ?? ''));
        $this->assertStringContainsString('run_marketing_live_execution.php', (string) ($schedule['command'] ?? ''));
        $paused = $this->marketing->pauseLiveOrchestratorWorker($this->userId, 'Orchestrator pause validation.');
        $this->assertTrue((bool) ($paused['emergency_paused'] ?? false));
        $resumed = $this->marketing->resumeLiveOrchestratorWorker($this->userId);
        $this->assertFalse((bool) ($resumed['emergency_paused'] ?? true));

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php live orchestrator');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Live Execution Orchestrator', $body);
        $this->assertStringContainsString('run_marketing_live_execution.php', $body);
        $this->assertStringContainsString('Run Live Orchestrator', $body);
    }

    public function testPhaseOneHundredFiftyFiveLiveOrchestratorCliHelpIsSafe(): void
    {
        $script = realpath(__DIR__ . '/../../../scripts/run_marketing_live_orchestrator.php');
        $this->assertIsString($script);
        $output = [];
        $exitCode = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help', $output, $exitCode);
        $body = implode("\n", $output);

        $this->assertSame(0, $exitCode, $body);
        $this->assertStringContainsString('Marketing Live Orchestrator', $body);
        $this->assertStringContainsString('--workspace-id', $body);
        $this->assertStringContainsString('--user-id', $body);
        $this->assertStringContainsString('No raw secrets are printed', $body);
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $body);
    }

    public function testPhaseOneHundredFiftyFiveLiveOrchestratorCliRunsWithResolvedManagerActor(): void
    {
        $script = realpath(__DIR__ . '/../../../scripts/run_marketing_live_orchestrator.php');
        $this->assertIsString($script);

        $output = [];
        $exitCode = 1;
        exec(
            escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' --workspace-id=' . (int) $this->workspaceId
            . ' --source=manual --limit=1 --json 2>&1',
            $output,
            $exitCode
        );
        $body = implode("\n", $output);
        $payload = json_decode($body, true);

        $this->assertSame(0, $exitCode, $body);
        $this->assertIsArray($payload, $body);
        $this->assertSame($this->userId, (int) ($payload['runs'][0]['actor_user_id'] ?? 0), $body);
        $this->assertSame('completed', (string) ($payload['runs'][0]['run']['status'] ?? ''), $body);
        $this->assertTrue((bool) ($payload['secret_safe'] ?? false));
        $this->assertFalse((bool) ($payload['external_api_called_by_orchestrator'] ?? true));
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $body);
    }

    public function testPhaseOneHundredFiftySevenLiveExecutionCronLauncherHelpIsSafe(): void
    {
        $script = realpath(__DIR__ . '/../../../cli/run_marketing_live_execution.php');
        $this->assertIsString($script);
        $output = [];
        $exitCode = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help', $output, $exitCode);
        $body = implode("\n", $output);

        $this->assertSame(0, $exitCode, $body);
        $this->assertStringContainsString('Marketing Live Execution Launcher', $body);
        $this->assertStringContainsString('--readiness', $body);
        $this->assertStringContainsString('--user-id', $body);
        $this->assertStringContainsString('No raw secrets are printed', $body);
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $body);
        $this->assertStringNotContainsString('DB_PASS', $body);
    }

    public function testPhaseOneHundredFiftySevenLiveExecutionCronLauncherReadinessUsesResolvedManagerActor(): void
    {
        $script = realpath(__DIR__ . '/../../../cli/run_marketing_live_execution.php');
        $this->assertIsString($script);

        $output = [];
        $exitCode = 1;
        exec(
            escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' --workspace-id=' . (int) $this->workspaceId
            . ' --source=manual --readiness --json 2>&1',
            $output,
            $exitCode
        );
        $body = implode("\n", $output);
        $payload = json_decode($body, true);

        $this->assertSame(0, $exitCode, $body);
        $this->assertIsArray($payload, $body);
        $this->assertSame($this->userId, (int) ($payload['runs'][0]['actor_user_id'] ?? 0), $body);
        $this->assertArrayHasKey('readiness_summary', (array) ($payload['runs'][0] ?? []));
        $this->assertTrue((bool) ($payload['secret_safe'] ?? false));
        $this->assertFalse((bool) ($payload['external_api_called_by_launcher'] ?? true));
        $this->assertStringNotContainsString('DB_PASSWORD', $body);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $body);
    }

    public function testPhaseOneHundredFiftyEightLiveExecutionLauncherRunsAreAuditable(): void
    {
        $invocationUuid = (string) (Database::queryOne('SELECT UUID() AS uuid')['uuid'] ?? '');
        Database::execute(
            "INSERT INTO marketing_live_execution_launcher_runs
             (workspace_id, uuid, invocation_uuid, source, status, target_scope, readiness_only, limit_count,
              processed_count, succeeded_count, blocked_count, failed_count, result_json, started_at, completed_at)
             VALUES (?, UUID(), ?, 'cron', 'completed', 'all', 1, 7, 0, 0, 0, 0, ?, NOW(), NOW())",
            [
                $this->workspaceId,
                $invocationUuid,
                json_encode([
                    'status' => 'attention',
                    'ready_for_live_execution' => false,
                    'readiness_summary' => [
                        'status' => 'attention',
                        'ready_for_live_execution' => false,
                        'component_statuses' => [
                            'orchestrator' => 'planned',
                            'webhook_attempts' => 'unknown',
                        ],
                        'warnings' => ['Orchestrator schedule is planned.'],
                        'secret_safe' => true,
                    ],
                    'readiness' => ['orchestrator' => ['status' => 'planned']],
                    'secret_safe' => true,
                ], JSON_UNESCAPED_SLASHES),
            ]
        );
        $launcherRunId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), 'Other launcher workspace', ?, 'active', 'trialing', ?)",
            ['other-launcher-' . uniqid(), $this->userId]
        );
        $otherWorkspaceId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO marketing_live_execution_launcher_runs
             (workspace_id, uuid, invocation_uuid, source, status, target_scope, readiness_only, limit_count, result_json, started_at, completed_at)
             VALUES (?, UUID(), UUID(), 'cron', 'completed', 'all', 1, 1, ?, NOW(), NOW())",
            [$otherWorkspaceId, json_encode(['readiness' => ['orchestrator' => ['status' => 'active']]], JSON_UNESCAPED_SLASHES)]
        );

        $runs = $this->marketing->listLiveExecutionLauncherRuns(['invocation_uuid' => $invocationUuid], 10, 0);
        $this->assertCount(1, $runs);
        $this->assertSame($launcherRunId, (int) ($runs[0]['id'] ?? 0));
        $this->assertSame('completed', (string) ($runs[0]['status'] ?? ''));
        $this->assertTrue((bool) ($runs[0]['readiness_only'] ?? false));
        $this->assertSame('planned', (string) ($runs[0]['result_json']['readiness']['orchestrator']['status'] ?? ''));

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php live launcher evidence');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Launcher Evidence', $body);
        $this->assertStringContainsString('Launcher run #' . $launcherRunId, $body);
        $this->assertStringContainsString('run_marketing_live_execution.php', $body);
        $this->assertStringContainsString('Readiness: Attention', $body);
        $this->assertStringContainsString('Ready: No', $body);
        $this->assertStringContainsString('Webhook Attempts Unknown', $body);
    }

    public function testPhaseOneHundredFiftyNineLiveExecutionLauncherHealthTracksFreshness(): void
    {
        Database::execute(
            "DELETE FROM marketing_live_execution_launcher_runs WHERE workspace_id = ?",
            [$this->workspaceId]
        );
        $this->marketing->updateLiveOrchestratorSchedule([
            'status' => 'active',
            'expected_interval_minutes' => 5,
            'max_stale_minutes' => 10,
            'max_queue_items_per_run' => 25,
        ], $this->userId);

        $stale = $this->marketing->getLiveExecutionLauncherHealth(true, $this->userId);
        $this->assertSame('stale', (string) ($stale['status'] ?? ''));
        $this->assertStringContainsString('run_marketing_live_execution.php', (string) ($stale['command'] ?? ''));
        $this->assertFalse((bool) ($stale['external_api_called_by_health_check'] ?? true));

        $healthRow = Database::queryOne(
            "SELECT status, diagnostics_json
             FROM marketing_live_worker_health_checks
             WHERE workspace_id = ? AND worker_key = 'marketing_live_execution_launcher'
             LIMIT 1",
            [$this->workspaceId]
        );
        $this->assertSame('stale', (string) ($healthRow['status'] ?? ''));
        $this->assertStringNotContainsString('DB_PASSWORD', (string) ($healthRow['diagnostics_json'] ?? ''));

        Database::execute(
            "INSERT INTO marketing_live_execution_launcher_runs
             (workspace_id, uuid, invocation_uuid, source, status, target_scope, readiness_only, limit_count,
              processed_count, succeeded_count, blocked_count, failed_count, result_json, started_at, completed_at)
             VALUES (?, UUID(), UUID(), 'cron', 'completed', 'all', 0, 25, 0, 0, 0, 0, ?, NOW(), NOW())",
            [$this->workspaceId, json_encode(['secret_safe' => true, 'external_api_called_by_launcher' => false], JSON_UNESCAPED_SLASHES)]
        );
        $healthy = $this->marketing->getLiveExecutionLauncherHealth();
        $this->assertSame('healthy', (string) ($healthy['status'] ?? ''), json_encode($healthy, JSON_PRETTY_PRINT));
        $this->assertGreaterThanOrEqual(1, (int) ($healthy['counts']['recent_completed'] ?? 0));

        Database::execute(
            "INSERT INTO marketing_live_execution_launcher_runs
             (workspace_id, uuid, invocation_uuid, source, status, target_scope, readiness_only, limit_count,
              result_json, error_message, started_at, completed_at)
             VALUES (?, UUID(), UUID(), 'cron', 'failed', 'all', 0, 25, ?, 'Sanitized launcher failure.', NOW(), NOW())",
            [$this->workspaceId, json_encode(['error' => 'Sanitized launcher failure.'], JSON_UNESCAPED_SLASHES)]
        );
        $blocked = $this->marketing->getLiveExecutionLauncherHealth();
        $this->assertSame('blocked', (string) ($blocked['status'] ?? ''));

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php live launcher health');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Launcher health', $body);
        $this->assertStringContainsString('Last launcher run', $body);
        $this->assertStringContainsString('Sanitized launcher failure.', $body);
    }

    public function testPhaseOneHundredSixtyLiveWebhookAttemptHealthIsWorkspaceScopedAndSecretSafe(): void
    {
        Database::execute(
            "DELETE FROM marketing_live_webhook_attempts WHERE workspace_id = ?",
            [$this->workspaceId]
        );
        $unknown = $this->marketing->getLiveWebhookAttemptHealth(true, $this->userId);
        $this->assertSame('unknown', (string) ($unknown['status'] ?? ''));
        $this->assertFalse((bool) ($unknown['external_api_called_by_health_check'] ?? true));

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), 'Other Webhook Workspace', ?, 'active', 'trialing', ?)",
            ['other-webhook-' . uniqid(), $this->userId]
        );
        $otherWorkspaceId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO marketing_live_webhook_attempts
             (workspace_id, uuid, status, http_method, endpoint_host, endpoint_url_hash,
              request_headers_json, request_payload_json, response_status_code, response_preview, duration_ms,
              attempt_count, external_api_called, third_party_delivery, metadata_json, attempted_at, created_by)
             VALUES (?, UUID(), 'sent', 'POST', 'other.example.com', SHA2('https://other.example.com/hook', 256),
                     JSON_OBJECT('Content-Type', 'application/json'), JSON_OBJECT('event', 'other_workspace'), 202, 'accepted',
                     95, 1, 1, 0, JSON_OBJECT('raw_secret_values_visible', false), NOW(), ?)",
            [$otherWorkspaceId, $this->userId]
        );
        Database::execute(
            "INSERT INTO marketing_live_webhook_attempts
             (workspace_id, uuid, status, http_method, endpoint_host, endpoint_url_hash,
              request_headers_json, request_payload_json, response_status_code, response_preview, error_message, duration_ms,
              attempt_count, external_api_called, third_party_delivery, metadata_json, attempted_at, created_by)
             VALUES (?, UUID(), 'failed', 'POST', 'example.com', SHA2('https://example.com/private-token-hook', 256),
                     JSON_OBJECT('Authorization', '[configured]'), JSON_OBJECT('payload', JSON_OBJECT('api_token', '[configured]')),
                     500, 'server error', 'Endpoint returned 500.', 120, 1, 1, 0,
                     JSON_OBJECT('raw_secret_values_visible', false, 'secret', JSON_OBJECT('configured', true)), NOW(), ?)",
            [$this->workspaceId, $this->userId]
        );

        $health = $this->marketing->getLiveWebhookAttemptHealth(true, $this->userId);
        $this->assertSame('attention', (string) ($health['status'] ?? ''), json_encode($health, JSON_PRETTY_PRINT));
        $this->assertSame(1, (int) ($health['counts']['recent_total'] ?? 0));
        $this->assertSame(1, (int) ($health['counts']['failed'] ?? 0));
        $this->assertSame(0, (int) ($health['counts']['sent'] ?? 0));
        $this->assertSame('example.com', (string) ($health['latest_attempt']['endpoint_host'] ?? ''));
        $this->assertFalse((bool) ($health['endpoint_url_visible'] ?? true));
        $this->assertFalse((bool) ($health['third_party_delivery_by_health_check'] ?? true));
        $this->assertStringNotContainsString('private-token-hook', json_encode($health, JSON_UNESCAPED_SLASHES) ?: '');

        $healthRow = Database::queryOne(
            "SELECT status, diagnostics_json
             FROM marketing_live_worker_health_checks
             WHERE workspace_id = ? AND worker_key = 'marketing_live_outbound_webhooks'
             LIMIT 1",
            [$this->workspaceId]
        );
        $this->assertSame('attention', (string) ($healthRow['status'] ?? ''));
        $this->assertStringNotContainsString('private-token-hook', (string) ($healthRow['diagnostics_json'] ?? ''));

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php outbound webhook health');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Outbound webhook health', $body);
        $this->assertStringContainsString('Recent 24h', $body);
        $this->assertStringNotContainsString('private-token-hook', $body);
        $this->assertStringNotContainsString('other.example.com', $body);
    }

    public function testPhaseOneHundredSixtyOneLiveExecutionReadinessIncludesWebhookHealth(): void
    {
        Database::execute(
            "DELETE FROM marketing_live_webhook_attempts WHERE workspace_id = ?",
            [$this->workspaceId]
        );
        Database::execute(
            "INSERT INTO marketing_live_webhook_attempts
             (workspace_id, uuid, status, http_method, endpoint_host, endpoint_url_hash,
              request_headers_json, request_payload_json, response_status_code, response_preview, error_message, duration_ms,
              attempt_count, external_api_called, third_party_delivery, metadata_json, attempted_at, created_by)
             VALUES (?, UUID(), 'failed', 'POST', 'example.com', SHA2('https://example.com/launcher-readiness-secret-url', 256),
                     JSON_OBJECT('Authorization', '[configured]'), JSON_OBJECT('payload', JSON_OBJECT('secret', '[configured]')),
                     500, 'server error', 'Endpoint returned 500.', 120, 1, 1, 0,
                     JSON_OBJECT('raw_secret_values_visible', false), NOW(), ?)",
            [$this->workspaceId, $this->userId]
        );

        $script = realpath(__DIR__ . '/../../../cli/run_marketing_live_execution.php');
        $this->assertIsString($script);
        $output = [];
        $exitCode = 1;
        exec(
            escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' --workspace-id=' . (int) $this->workspaceId
            . ' --readiness --json --source=test',
            $output,
            $exitCode
        );
        $body = implode("\n", $output);

        $this->assertSame(0, $exitCode, $body);
        $payload = json_decode($body, true);
        $this->assertIsArray($payload, $body);
        $this->assertSame('attention', (string) ($payload['runs'][0]['status'] ?? ''), $body);
        $this->assertFalse((bool) ($payload['runs'][0]['ready_for_live_execution'] ?? true));
        $readiness = (array) ($payload['runs'][0]['readiness'] ?? []);
        $this->assertArrayHasKey('webhook_attempts', $readiness);
        $this->assertSame('attention', (string) ($readiness['webhook_attempts']['status'] ?? ''), json_encode($readiness, JSON_PRETTY_PRINT));
        $this->assertSame('example.com', (string) ($readiness['webhook_attempts']['latest_attempt']['endpoint_host'] ?? ''));
        $this->assertFalse((bool) ($readiness['webhook_attempts']['external_api_called_by_health_check'] ?? true));
        $summary = (array) ($payload['runs'][0]['readiness_summary'] ?? []);
        $this->assertSame('attention', (string) ($summary['status'] ?? ''));
        $this->assertFalse((bool) ($summary['external_api_called_by_readiness'] ?? true));
        $this->assertContains('attention', (array) ($summary['component_statuses'] ?? []));
        $this->assertStringNotContainsString('launcher-readiness-secret-url', $body);

        $launcherRuns = $this->marketing->listLiveExecutionLauncherRuns(['readiness_only' => true], 5, 0);
        $this->assertNotEmpty($launcherRuns);
        $this->assertSame('attention', (string) ($launcherRuns[0]['result_json']['readiness']['webhook_attempts']['status'] ?? ''));
        $this->assertSame('attention', (string) ($launcherRuns[0]['result_json']['readiness_summary']['status'] ?? ''));
        $this->assertStringNotContainsString('launcher-readiness-secret-url', json_encode($launcherRuns[0], JSON_UNESCAPED_SLASHES) ?: '');
    }

    public function testPhaseOneHundredFiftySixLiveSmsAndWhatsappQueueHandoffsAreGatedAndQueued(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, lead_source, stage, created_by)
             VALUES
             (?, UUID(), 'Live', 'SMS', 'live-sms@example.com', '+15550101010', 'form', 'qualified', ?),
             (?, UUID(), 'Live', 'WhatsApp', 'live-wa@example.com', '+15550102020', 'form', 'qualified', ?)",
            [$this->workspaceId, $this->userId, $this->workspaceId, $this->userId]
        );
        $smsContactId = (int) Database::queryOne(
            "SELECT id FROM contacts WHERE workspace_id = ? AND email = 'live-sms@example.com'",
            [$this->workspaceId]
        )['id'];
        $whatsappContactId = (int) Database::queryOne(
            "SELECT id FROM contacts WHERE workspace_id = ? AND email = 'live-wa@example.com'",
            [$this->workspaceId]
        )['id'];
        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, metadata, created_at)
             VALUES (?, UUID(), ?, 'whatsapp', 'inbound', 'Recent WhatsApp opt-in', 'Yes, please send details.', 'read', ?, NOW())",
            [$this->workspaceId, $whatsappContactId, json_encode(['source' => 'marketing_live_handoff_test'])]
        );

        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['sms', 'whatsapp'],
            'allowed_adapter_keys' => ['crm_sms_queue_handoff', 'crm_whatsapp_queue_handoff'],
            'daily_live_cap' => 10,
            'safety_checklist' => ['policy enabled', 'connector verified', 'sms and whatsapp queue handoff only'],
        ], $this->userId);

        $smsConnectorId = $this->marketing->createChannelConnector([
            'name' => 'CRM SMS Queue Connector',
            'connector_type' => 'sms',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['copy_export', 'dry_run'],
            'setup_checklist' => ['copy export capability', 'dry-run diagnostics', 'assigned owner', 'opt-in reviewed'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_sms_queue_handoff',
            'secret_reference' => 'vault://marketing/sms/main',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $whatsappConnectorId = $this->marketing->createChannelConnector([
            'name' => 'CRM WhatsApp Queue Connector',
            'connector_type' => 'whatsapp',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['copy_export', 'dry_run'],
            'setup_checklist' => ['copy export capability', 'dry-run diagnostics', 'assigned owner', 'opt-in reviewed'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_whatsapp_queue_handoff',
            'secret_reference' => 'vault://marketing/whatsapp/main',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);

        foreach ([$smsConnectorId, $whatsappConnectorId] as $connectorId) {
            $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId])['status'] ?? ''));
            $this->assertSame('passed', (string) ($this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId])['status'] ?? ''));
        }

        $smsQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'sms',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $smsConnectorId,
            'payload' => [
                'recipient_contact_ids' => [$smsContactId],
                'message' => 'Your launch checklist is ready. Reply STOP to opt out.',
                'consent_reviewed' => true,
            ],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);
        $whatsappQueueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'whatsapp',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $whatsappConnectorId,
            'payload' => [
                'recipient_contact_ids' => [$whatsappContactId],
                'message' => 'Your launch checklist is ready for review.',
                'opt_in_reviewed' => true,
            ],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);

        foreach ([$smsQueueId, $whatsappQueueId] as $queueId) {
            $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
            $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved guarded messaging queue handoff.', $this->userId));
            $readiness = $this->marketing->getMarketingLiveExecutionReadiness($queueId);
            $this->assertSame(1, (int) ($readiness['counts']['ready_live_queue_items'] ?? 0), json_encode($readiness, JSON_PRETTY_PRINT));
        }

        $smsAttempt = $this->marketing->runExecutionQueueItem($smsQueueId, ['attempt_mode' => 'live', 'live_confirmation' => 'RUN LIVE', 'created_by' => $this->userId]);
        $whatsappAttempt = $this->marketing->runExecutionQueueItem($whatsappQueueId, ['attempt_mode' => 'live', 'live_confirmation' => 'RUN LIVE', 'created_by' => $this->userId]);

        $this->assertSame('success', (string) ($smsAttempt['status'] ?? ''));
        $this->assertSame('success', (string) ($whatsappAttempt['status'] ?? ''));
        $this->assertSame('crm_sms_queue_handoff', (string) ($smsAttempt['result_json']['adapter_result']['adapter_key'] ?? ''));
        $this->assertSame('crm_whatsapp_queue_handoff', (string) ($whatsappAttempt['result_json']['adapter_result']['adapter_key'] ?? ''));
        $this->assertFalse((bool) ($smsAttempt['result_json']['adapter_result']['external_api_called'] ?? true));
        $this->assertFalse((bool) ($whatsappAttempt['result_json']['adapter_result']['external_api_called'] ?? true));
        $this->assertTrue((bool) ($smsAttempt['result_json']['adapter_result']['channel_queue_handoff_only'] ?? false));
        $this->assertTrue((bool) ($whatsappAttempt['result_json']['adapter_result']['channel_queue_handoff_only'] ?? false));

        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM marketing_live_channel_handoffs WHERE workspace_id = ? AND queue_id = ? AND execution_type = 'sms' AND status = 'queued'",
            [$this->workspaceId, $smsQueueId]
        )['count'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM marketing_live_channel_handoffs WHERE workspace_id = ? AND queue_id = ? AND execution_type = 'whatsapp' AND status = 'queued'",
            [$this->workspaceId, $whatsappQueueId]
        )['count'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM sms_queue sq
             JOIN sms_messages sm ON sm.id = sq.message_id AND sm.workspace_id = sq.workspace_id
             WHERE sq.workspace_id = ? AND sq.status = 'pending' AND sm.message_body LIKE 'Your launch checklist%'",
            [$this->workspaceId]
        )['count'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM whatsapp_queue wq
             JOIN whatsapp_messages wm ON wm.id = wq.message_id AND wm.workspace_id = wq.workspace_id
             WHERE wq.workspace_id = ? AND wq.status = 'pending' AND wm.message_body LIKE 'Your launch checklist%'",
            [$this->workspaceId]
        )['count'] ?? 0));

        $smsQueue = $this->marketing->getExecutionQueueItem($smsQueueId);
        $whatsappQueue = $this->marketing->getExecutionQueueItem($whatsappQueueId);
        $this->assertSame('queued', (string) ($smsQueue['live_outcome_status'] ?? ''));
        $this->assertSame('queued', (string) ($whatsappQueue['live_outcome_status'] ?? ''));
        $this->assertSame('sms', (string) ($smsQueue['live_outcome_json']['execution_type'] ?? ''));
        $this->assertSame('whatsapp', (string) ($whatsappQueue['live_outcome_json']['execution_type'] ?? ''));

        $smsHandoff = Database::queryOne(
            "SELECT id, source_message_id, source_queue_id
             FROM marketing_live_channel_handoffs
             WHERE workspace_id = ? AND queue_id = ? AND execution_type = 'sms'
             LIMIT 1",
            [$this->workspaceId, $smsQueueId]
        );
        $whatsappHandoff = Database::queryOne(
            "SELECT id, source_message_id, source_queue_id
             FROM marketing_live_channel_handoffs
             WHERE workspace_id = ? AND queue_id = ? AND execution_type = 'whatsapp'
             LIMIT 1",
            [$this->workspaceId, $whatsappQueueId]
        );
        $this->assertNotEmpty($smsHandoff);
        $this->assertNotEmpty($whatsappHandoff);

        Database::execute('UPDATE demo_mode_state SET is_enabled = 1, simulation_only = 1 WHERE id = 1');
        try {
            $channelWorkerRun = $this->marketing->processLiveChannelHandoffWorker([
                'source' => 'test',
                'limit' => 10,
                'requested_by' => $this->userId,
            ]);
        } finally {
            Database::execute('UPDATE demo_mode_state SET is_enabled = 0, simulation_only = 1 WHERE id = 1');
        }
        $this->assertSame('completed', (string) ($channelWorkerRun['status'] ?? ''), json_encode($channelWorkerRun, JSON_PRETTY_PRINT));
        $this->assertSame(2, (int) ($channelWorkerRun['processed_count'] ?? 0));
        $this->assertSame(2, (int) ($channelWorkerRun['sent_count'] ?? 0));
        $this->assertSame(0, (int) ($channelWorkerRun['failed_count'] ?? 0));

        $channelLease = $this->marketing->getLiveChannelWorkerLeaseStatus();
        $this->assertSame('released', (string) ($channelLease['status'] ?? ''));
        $this->assertFalse((bool) ($channelLease['active'] ?? true));
        $this->assertSame((int) ($channelWorkerRun['id'] ?? 0), (int) ($channelLease['channel_worker_run_id'] ?? 0));

        $smsHandoff = Database::queryOne(
            "SELECT id, source_message_id, source_queue_id, status, worker_run_id
             FROM marketing_live_channel_handoffs
             WHERE workspace_id = ? AND queue_id = ? AND execution_type = 'sms'
             LIMIT 1",
            [$this->workspaceId, $smsQueueId]
        );
        $whatsappHandoff = Database::queryOne(
            "SELECT id, source_message_id, source_queue_id, status, worker_run_id
             FROM marketing_live_channel_handoffs
             WHERE workspace_id = ? AND queue_id = ? AND execution_type = 'whatsapp'
             LIMIT 1",
            [$this->workspaceId, $whatsappQueueId]
        );
        $this->assertSame('sent', (string) ($smsHandoff['status'] ?? ''));
        $this->assertSame('sent', (string) ($whatsappHandoff['status'] ?? ''));
        $this->assertSame((int) ($channelWorkerRun['id'] ?? 0), (int) ($smsHandoff['worker_run_id'] ?? 0));
        $this->assertSame((int) ($channelWorkerRun['id'] ?? 0), (int) ($whatsappHandoff['worker_run_id'] ?? 0));
        $this->assertSame('completed', (string) (Database::queryOne(
            "SELECT status FROM sms_queue WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, (int) ($smsHandoff['source_queue_id'] ?? 0)]
        )['status'] ?? ''));
        $this->assertSame('completed', (string) (Database::queryOne(
            "SELECT status FROM whatsapp_queue WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, (int) ($whatsappHandoff['source_queue_id'] ?? 0)]
        )['status'] ?? ''));

        $syncRun = $this->marketing->runLiveOutcomeSyncWorker([
            'source' => 'manual',
            'limit' => 10,
            'requested_by' => $this->userId,
        ]);
        $this->assertSame('completed', (string) ($syncRun['status'] ?? ''), json_encode($syncRun, JSON_PRETTY_PRINT));
        $this->assertSame(2, (int) ($syncRun['processed_count'] ?? 0));
        $this->assertSame(2, (int) ($syncRun['delivered_count'] ?? 0));

        $this->assertSame('sent', (string) (Database::queryOne(
            "SELECT status FROM marketing_live_channel_handoffs WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, (int) ($smsHandoff['id'] ?? 0)]
        )['status'] ?? ''));
        $this->assertSame('sent', (string) (Database::queryOne(
            "SELECT status FROM marketing_live_channel_handoffs WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, (int) ($whatsappHandoff['id'] ?? 0)]
        )['status'] ?? ''));
        $this->assertSame('sent', (string) (Database::queryOne(
            "SELECT proof_status
             FROM marketing_live_channel_delivery_proofs
             WHERE workspace_id = ? AND handoff_id = ?",
            [$this->workspaceId, (int) ($smsHandoff['id'] ?? 0)]
        )['proof_status'] ?? ''));
        $this->assertSame('sent', (string) (Database::queryOne(
            "SELECT proof_status
             FROM marketing_live_channel_delivery_proofs
             WHERE workspace_id = ? AND handoff_id = ?",
            [$this->workspaceId, (int) ($whatsappHandoff['id'] ?? 0)]
        )['proof_status'] ?? ''));
        $this->assertSame((int) ($channelWorkerRun['id'] ?? 0), (int) (Database::queryOne(
            "SELECT worker_run_id
             FROM marketing_live_channel_delivery_proofs
             WHERE workspace_id = ? AND handoff_id = ?",
            [$this->workspaceId, (int) ($smsHandoff['id'] ?? 0)]
        )['worker_run_id'] ?? 0));

        $smsQueue = $this->marketing->getExecutionQueueItem($smsQueueId);
        $whatsappQueue = $this->marketing->getExecutionQueueItem($whatsappQueueId);
        $this->assertSame('delivered', (string) ($smsQueue['live_outcome_status'] ?? ''));
        $this->assertSame('delivered', (string) ($whatsappQueue['live_outcome_status'] ?? ''));
        $this->assertSame(1, (int) ($smsQueue['live_outcome_json']['channel_sync']['proofs_recorded'] ?? 0));
        $this->assertSame(1, (int) ($whatsappQueue['live_outcome_json']['channel_sync']['proofs_recorded'] ?? 0));

        $page = $this->runWebEndpoint('public/marketing_execution.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_execution.php live messaging adapters');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('crm_sms_queue_handoff', $body);
        $this->assertStringContainsString('crm_whatsapp_queue_handoff', $body);
        $this->assertStringContainsString('Live Channel Worker', $body);
        $this->assertStringContainsString('process_marketing_live_channel_handoffs.php', $body);
        $this->assertStringContainsString('Channel Delivery Proofs', $body);
        $this->assertStringContainsString('Live email, SMS, and WhatsApp execution start as CRM queue handoffs', $body);
    }

    public function testPhaseOneHundredThirtyOneLiveEmailQueueHandoffQueuesApprovedEmailRun(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, lead_source, stage, created_by)
             VALUES
             (?, UUID(), 'Queue', 'One', 'queue-one@example.com', 'form', 'qualified', ?),
             (?, UUID(), 'Queue', 'Two', 'queue-two@example.com', 'form', 'qualified', ?),
             (?, UUID(), 'Queue', 'Three', 'queue-three@example.com', 'form', 'qualified', ?)",
            [$this->workspaceId, $this->userId, $this->workspaceId, $this->userId, $this->workspaceId, $this->userId]
        );
        $campaignId = $this->createCampaign($this->workspaceId, 'Live Email Queue Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Live Email Queue Content',
            'content_type' => 'email',
            'channel' => 'email',
            'status' => 'approved',
            'draft_body' => 'Use the launch readiness checklist and book a review call.',
            'campaign_id' => $campaignId,
            'created_by' => $this->userId,
        ]);
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Live Email Queue Segment',
            'status' => 'active',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        $snapshot = $this->marketing->createAudienceSegmentSnapshot($segmentId, $this->userId);
        $consentPolicyId = $this->marketing->createConsentPolicy([
            'policy_name' => 'Live Email Queue Consent',
            'channel' => 'email',
            'consent_basis' => 'existing_customer',
            'requires_unsubscribe' => 1,
            'requires_suppression_check' => 1,
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $emailRunId = $this->marketing->createEmailCampaignRun([
            'name' => 'Live Email Queue Run',
            'content_item_id' => $contentId,
            'campaign_id' => $campaignId,
            'audience_segment_id' => $segmentId,
            'segment_snapshot_id' => (int) ($snapshot['id'] ?? 0),
            'consent_policy_id' => $consentPolicyId,
            'consent_status' => 'approved',
            'subject' => 'Live queue handoff',
            'preview_text' => 'Queue-backed execution',
            'preheader_text' => 'Queue-backed execution',
            'body_snapshot' => 'Use the launch readiness checklist and book a review call.',
            'unsubscribe_text' => 'Reply unsubscribe to opt out.',
            'suppression_notes' => 'Suppression list checked before launch.',
            'suppression_list_checked_at' => date('Y-m-d H:i:s'),
            'cta_url' => 'https://example.com/live-email',
            'cta_label' => 'Book review',
            'send_checklist' => ['Audience confirmed', 'Consent approved', 'Unsubscribe copy included'],
            'approval_status' => 'approved',
            'recipient_count' => 3,
            'status' => 'ready',
            'created_by' => $this->userId,
        ]);

        $connectorId = $this->marketing->createChannelConnector([
            'name' => 'CRM Email Queue Connector',
            'connector_type' => 'email',
            'status' => 'ready',
            'execution_mode' => 'dry_run',
            'setup_status' => 'ready',
            'readiness_score' => 100,
            'capabilities' => ['csv_export', 'unsubscribe_check', 'email_queue_handoff', 'dry_run'],
            'setup_checklist' => ['consent policy reviewed', 'suppression list reviewed', 'unsubscribe reviewed', 'manual export owner named'],
            'live_enabled' => 1,
            'live_adapter_key' => 'crm_email_queue_handoff',
            'secret_reference' => 'vault://marketing/email/main',
            'secret_status' => 'verified',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $queueId = $this->marketing->createExecutionQueueItem([
            'execution_type' => 'email',
            'execution_mode' => 'live',
            'status' => 'draft',
            'connector_id' => $connectorId,
            'email_run_id' => $emailRunId,
            'payload' => ['source' => 'phase_80_test'],
            'created_by' => $this->userId,
            'requested_by' => $this->userId,
        ]);

        $this->marketing->updateMarketingLiveExecutionPolicy([
            'status' => 'live_ready',
            'live_execution_enabled' => 1,
            'require_manage_approval' => 1,
            'allowed_connector_types' => ['email'],
            'allowed_adapter_keys' => ['crm_email_queue_handoff'],
            'daily_live_cap' => 10,
            'safety_checklist' => ['policy enabled', 'consent approved', 'email queue handoff only'],
        ], $this->userId);
        $dryRun = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'dry_run', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($dryRun['status'] ?? ''));
        $preflight = $this->marketing->runChannelConnectorTest($connectorId, ['test_mode' => 'live_preflight', 'created_by' => $this->userId]);
        $this->assertSame('passed', (string) ($preflight['status'] ?? ''));
        $approvalId = $this->marketing->requestExecutionApproval($queueId, $this->userId);
        $this->assertTrue($this->marketing->decideExecutionApproval($approvalId, 'approved', 'Approved queue-backed live email handoff.', $this->userId));

        $readiness = $this->marketing->getMarketingLiveExecutionReadiness($queueId);
        $this->assertSame(1, (int) ($readiness['counts']['ready_live_queue_items'] ?? 0), json_encode($readiness, JSON_PRETTY_PRINT));

        $attempt = $this->marketing->runExecutionQueueItem($queueId, ['attempt_mode' => 'live', 'live_confirmation' => 'RUN LIVE', 'created_by' => $this->userId]);
        $this->assertSame('success', $attempt['status']);
        $adapterResult = (array) ($attempt['result_json']['adapter_result'] ?? []);
        $this->assertSame('crm_email_queue_handoff', (string) ($adapterResult['adapter_key'] ?? ''));
        $this->assertSame(3, (int) ($adapterResult['queued_recipients'] ?? 0));
        $this->assertFalse((bool) ($adapterResult['external_api_called'] ?? true));
        $this->assertTrue((bool) ($adapterResult['email_queue_handoff_only'] ?? false));

        $this->assertSame(3, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM marketing_live_email_handoffs WHERE workspace_id = ? AND queue_id = ? AND status = 'queued'",
            [$this->workspaceId, $queueId]
        )['count'] ?? 0));
        $this->assertSame(3, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM emails WHERE workspace_id = ? AND status = 'pending' AND subject = 'Live queue handoff'",
            [$this->workspaceId]
        )['count'] ?? 0));
        $this->assertSame(3, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM email_queue eq
             JOIN emails e ON e.id = eq.email_id AND e.workspace_id = eq.workspace_id
             WHERE eq.workspace_id = ? AND eq.status = 'pending' AND e.subject = 'Live queue handoff'",
            [$this->workspaceId]
        )['count'] ?? 0));

        $run = $this->marketing->getEmailCampaignRun($emailRunId);
        $this->assertSame('queued', (string) ($run['live_handoff_status'] ?? ''));
        $this->assertSame(3, (int) ($run['live_handoff_json']['queued_count'] ?? 0));

        $events = $this->marketing->listLiveExecutionEvents(['queue_id' => $queueId], 10, 0);
        $this->assertContains('live_succeeded', array_column($events, 'event_type'));

        $handoffs = $this->marketing->listLiveEmailHandoffs(['queue_id' => $queueId], 10, 0);
        $this->assertCount(3, $handoffs);
        $firstHandoff = $handoffs[0];
        $secondHandoff = $handoffs[1];
        $thirdHandoff = $handoffs[2];

        $unsubscribeTokens = $this->marketing->listLiveEmailUnsubscribeTokens(['email_run_id' => $emailRunId], 10, 0);
        $this->assertCount(3, $unsubscribeTokens);
        foreach ($unsubscribeTokens as $unsubscribeToken) {
            $this->assertSame('active', (string) ($unsubscribeToken['status'] ?? ''));
            $this->assertNotEmpty($unsubscribeToken['handoff_id'] ?? null);
        }
        $emailBody = (string) (Database::queryOne(
            'SELECT body FROM emails WHERE workspace_id = ? AND id = ?',
            [$this->workspaceId, (int) $firstHandoff['email_id']]
        )['body'] ?? '');
        $this->assertStringContainsString('marketing_unsubscribe.php?t=', $emailBody);
        preg_match('/marketing_unsubscribe\.php\?t=([a-f0-9]{48})/i', $emailBody, $tokenMatch);
        $this->assertNotEmpty($tokenMatch[1] ?? '');
        $unsubscribeResult = $this->marketing->processLiveEmailUnsubscribeToken((string) $tokenMatch[1], [
            'ip' => '127.0.0.1',
            'user_agent' => 'MarketingTest',
        ]);
        $this->assertTrue((bool) ($unsubscribeResult['success'] ?? false));
        $this->assertSame('unsubscribed', (string) ($unsubscribeResult['status'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM marketing_suppression_entries WHERE workspace_id = ? AND channel = 'email' AND source = 'live_email_unsubscribe' AND status = 'active'",
            [$this->workspaceId]
        )['count'] ?? 0));
        $unsubscribeEvents = $this->marketing->listLiveEmailUnsubscribeEvents(['event_type' => 'suppression_created'], 10, 0);
        $this->assertCount(1, $unsubscribeEvents);
        $this->assertFalse((bool) ($unsubscribeEvents[0]['metadata_json']['raw_ip_visible'] ?? true));

        $review = $this->marketing->controlLiveEmailHandoff((int) $firstHandoff['id'], 'mark_reviewed', $this->userId, 'Reviewed before worker pickup.');
        $this->assertSame('reviewed', (string) ($review['handoff']['operator_status'] ?? ''));

        Database::execute(
            "UPDATE emails SET status = 'failed', error_message = 'Simulated worker failure' WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, (int) $firstHandoff['email_id']]
        );
        Database::execute(
            "UPDATE email_queue SET status = 'failed', error_message = 'Simulated worker failure' WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId, (int) $firstHandoff['email_queue_id']]
        );
        $retry = $this->marketing->controlLiveEmailHandoff((int) $firstHandoff['id'], 'retry', $this->userId, 'Retry after worker failure.');
        $this->assertSame('retry_requested', (string) ($retry['handoff']['operator_status'] ?? ''));
        $this->assertSame('pending', (string) (Database::queryOne(
            'SELECT status FROM email_queue WHERE workspace_id = ? AND id = ?',
            [$this->workspaceId, (int) $firstHandoff['email_queue_id']]
        )['status'] ?? ''));
        $cancel = $this->marketing->controlLiveEmailHandoff((int) $firstHandoff['id'], 'cancel', $this->userId, 'Cancel before send.');
        $this->assertSame('cancelled', (string) ($cancel['handoff']['status'] ?? ''));

        $this->marketing->createSuppressionEntry([
            'channel' => 'email',
            'identifier' => (string) $secondHandoff['recipient_email'],
            'reason' => 'Worker-side suppression test',
            'created_by' => $this->userId,
        ]);
        Database::execute('UPDATE demo_mode_state SET is_enabled = 1, simulation_only = 1 WHERE id = 1');
        try {
            $workerRun = $this->marketing->processLiveEmailHandoffWorker(['source' => 'test', 'limit' => 5]);
        } finally {
            Database::execute('UPDATE demo_mode_state SET is_enabled = 0, simulation_only = 1 WHERE id = 1');
        }
        $this->assertSame('completed_with_errors', (string) ($workerRun['status'] ?? ''));
        $this->assertSame(2, (int) ($workerRun['processed_count'] ?? 0));
        $this->assertSame(1, (int) ($workerRun['sent_count'] ?? 0));
        $this->assertSame(1, (int) ($workerRun['blocked_count'] ?? 0));
        $blockedHandoff = $this->marketing->listLiveEmailHandoffs(['id' => (int) $secondHandoff['id']], 1, 0)[0] ?? [];
        $this->assertSame('blocked', (string) ($blockedHandoff['status'] ?? ''));
        $this->assertSame('blocked', (string) ($blockedHandoff['operator_status'] ?? ''));
        $this->assertSame((int) ($workerRun['id'] ?? 0), (int) ($blockedHandoff['worker_run_id'] ?? 0));
        $sentHandoff = $this->marketing->listLiveEmailHandoffs(['id' => (int) $thirdHandoff['id']], 1, 0)[0] ?? [];
        $this->assertSame('sent', (string) ($sentHandoff['status'] ?? ''));
        $this->assertSame('completed', (string) ($sentHandoff['operator_status'] ?? ''));
        $this->assertSame((int) ($workerRun['id'] ?? 0), (int) ($sentHandoff['worker_run_id'] ?? 0));
        $lease = $this->marketing->getLiveEmailWorkerLeaseStatus();
        $this->assertSame('released', (string) ($lease['status'] ?? ''));
        $this->assertFalse((bool) ($lease['active'] ?? true));
        $this->assertSame((int) ($workerRun['id'] ?? 0), (int) ($lease['run_id'] ?? 0));

        $health = $this->marketing->getLiveEmailWorkerHealth(true, $this->userId);
        $this->assertSame('attention', (string) ($health['status'] ?? ''));
        $this->assertSame((int) ($workerRun['id'] ?? 0), (int) ($health['last_run_id'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($health['counts']['blocked'] ?? 0));
        $this->assertTrue((bool) ($health['secret_safe'] ?? false));
        $healthRow = Database::queryOne(
            "SELECT status, last_run_id, blocked_count
             FROM marketing_live_worker_health_checks
             WHERE workspace_id = ? AND worker_key = 'marketing_live_email_handoffs'",
            [$this->workspaceId]
        );
        $this->assertSame('attention', (string) ($healthRow['status'] ?? ''));
        $this->assertSame((int) ($workerRun['id'] ?? 0), (int) ($healthRow['last_run_id'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($healthRow['blocked_count'] ?? 0));

        $proofs = $this->marketing->listLiveEmailDeliveryProofs(['worker_run_id' => (int) ($workerRun['id'] ?? 0)], 10, 0);
        $this->assertCount(2, $proofs);
        $proofStatuses = array_map(static fn(array $row): string => (string) ($row['proof_status'] ?? ''), $proofs);
        $this->assertContains('blocked', $proofStatuses);
        $this->assertContains('simulated', $proofStatuses);
        foreach ($proofs as $proof) {
            $this->assertSame((int) ($workerRun['id'] ?? 0), (int) ($proof['worker_run_id'] ?? 0));
            $this->assertFalse((bool) ($proof['evidence_json']['raw_secret_values_visible'] ?? true));
            $this->assertFalse((bool) ($proof['evidence_json']['external_smtp_from_page'] ?? true));
        }

        $handoffEvents = $this->marketing->listLiveEmailHandoffEvents(['queue_id' => $queueId], 20, 0);
        $this->assertContains('retry_requested', array_column($handoffEvents, 'event_type'));
        $this->assertContains('cancelled', array_column($handoffEvents, 'event_type'));
        $this->assertContains('blocked', array_column($handoffEvents, 'event_type'));
        $this->assertContains('sent', array_column($handoffEvents, 'event_type'));
    }

    public function testPhaseOneHundredThirtyTwoLiveEmailWorkerLeaseBlocksOverlap(): void
    {
        Database::execute(
            "INSERT INTO marketing_live_worker_leases
             (workspace_id, uuid, worker_key, run_id, lease_token, source, status, acquired_at, heartbeat_at, expires_at, metadata_json)
             VALUES (?, UUID(), 'marketing_live_email_handoffs', NULL, UUID(), 'test', 'running', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?)",
            [$this->workspaceId, json_encode(['source' => 'overlap_test', 'secret_safe' => true])]
        );

        $run = $this->marketing->processLiveEmailHandoffWorker(['source' => 'test', 'limit' => 5]);
        $this->assertSame('blocked', (string) ($run['status'] ?? ''));
        $this->assertStringContainsString('worker lease', strtolower((string) ($run['error_message'] ?? '')));

        $lease = $this->marketing->getLiveEmailWorkerLeaseStatus();
        $this->assertSame('running', (string) ($lease['status'] ?? ''));
        $this->assertTrue((bool) ($lease['active'] ?? false));

        $health = $this->marketing->getLiveEmailWorkerHealth(false, $this->userId);
        $this->assertSame('blocked', (string) ($health['status'] ?? ''));
        $this->assertSame('running', (string) ($health['lease']['status'] ?? ''));
        $this->assertTrue((bool) ($health['lease']['active'] ?? false));
    }

    public function testPhaseOneHundredThirtyThreeLiveEmailWorkerScheduleProfileControlsHealthWindow(): void
    {
        $default = $this->marketing->getLiveEmailWorkerSchedule();
        $this->assertSame('planned', (string) ($default['status'] ?? ''));
        $this->assertFalse((bool) ($default['configured'] ?? true));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $denied = false;
        try {
            $this->marketing->updateLiveEmailWorkerSchedule(['status' => 'active'], $this->userId);
        } catch (RuntimeException $e) {
            $denied = true;
        } finally {
            Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        }
        $this->assertTrue($denied, 'Marketing role should not update live worker schedule controls.');

        $schedule = $this->marketing->updateLiveEmailWorkerSchedule([
            'status' => 'active',
            'schedule_label' => 'Every three minutes',
            'expected_interval_minutes' => 3,
            'max_stale_minutes' => 9,
            'max_handoffs_per_run' => 7,
            'min_seconds_between_sends' => 4,
            'hourly_send_cap' => 12,
            'daily_send_cap' => 30,
        ], $this->userId);
        $this->assertSame('active', (string) ($schedule['status'] ?? ''));
        $this->assertTrue((bool) ($schedule['configured'] ?? false));
        $this->assertSame(3, (int) ($schedule['expected_interval_minutes'] ?? 0));
        $this->assertSame(9, (int) ($schedule['max_stale_minutes'] ?? 0));
        $this->assertSame(7, (int) ($schedule['max_handoffs_per_run'] ?? 0));
        $this->assertSame(4, (int) ($schedule['min_seconds_between_sends'] ?? 0));
        $this->assertSame(12, (int) ($schedule['hourly_send_cap'] ?? 0));
        $this->assertSame(30, (int) ($schedule['daily_send_cap'] ?? 0));

        $health = $this->marketing->getLiveEmailWorkerHealth(false, $this->userId);
        $this->assertSame(9, (int) ($health['stale_threshold_minutes'] ?? 0));
        $this->assertSame('active', (string) ($health['schedule']['status'] ?? ''));
        $this->assertSame(12, (int) ($health['throttle']['hourly_send_cap'] ?? 0));

        $run = $this->marketing->processLiveEmailHandoffWorker(['source' => 'test', 'limit' => 50]);
        $this->assertSame('completed', (string) ($run['status'] ?? ''));
        $this->assertSame(1, (int) ($run['limit_count'] ?? 0), 'Send-gap throttle should reduce one run to one handoff.');
        $confirmed = $this->marketing->getLiveEmailWorkerSchedule();
        $this->assertNotEmpty($confirmed['last_confirmed_at'] ?? null);
        $this->assertSame('active', (string) ($confirmed['status'] ?? ''));

        $paused = $this->marketing->pauseLiveEmailWorker($this->userId, 'Emergency pause from test.');
        $this->assertSame('paused', (string) ($paused['status'] ?? ''));
        $this->assertTrue((bool) ($paused['emergency_paused'] ?? false));
        $blockedRun = $this->marketing->processLiveEmailHandoffWorker(['source' => 'test', 'limit' => 5]);
        $this->assertSame('blocked', (string) ($blockedRun['status'] ?? ''));
        $this->assertStringContainsString('emergency_paused', (string) ($blockedRun['error_message'] ?? ''));
        $blockedHealth = $this->marketing->getLiveEmailWorkerHealth(false, $this->userId);
        $this->assertSame('blocked', (string) ($blockedHealth['status'] ?? ''));
        $this->assertContains('emergency_paused', (array) ($blockedHealth['schedule_blockers'] ?? []));

        $resumed = $this->marketing->resumeLiveEmailWorker($this->userId);
        $this->assertSame('active', (string) ($resumed['status'] ?? ''));
        $this->assertFalse((bool) ($resumed['emergency_paused'] ?? true));
    }

    public function testPhaseThirtySevenJourneyPlannerWorkspaceIsolationAndRoleLimits(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        try {
            $this->marketing->createJourney(['name' => 'Viewer Journey']);
            $this->fail('Viewer should not create marketing journeys.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $journeyId = $this->marketing->createJourney([
            'name' => 'Marketing Role Journey',
            'steps' => [['step_type' => 'task', 'title' => 'Manual follow-up']],
            'created_by' => $this->userId,
        ]);
        $this->marketing->generateJourneyDraft(['journey_id' => $journeyId, 'created_by' => $this->userId]);
        try {
            $this->marketing->deleteJourney($journeyId);
            $this->fail('Marketing role should not delete journeys.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        $otherWorkspaceId = $this->createWorkspace('Other Journey Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $otherJourneyId = $otherMarketing->createJourney(['name' => 'Other Workspace Journey']);

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $this->assertNull($this->marketing->getJourney($otherJourneyId));

        $this->marketing->deleteJourney($journeyId);
        $this->assertNull($this->marketing->getJourney($journeyId));
    }

    public function testPhaseEighteenChannelExportBundlesValidateReadinessAndExport(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Instagram Distribution Source',
            'channel' => 'instagram',
            'draft_body' => 'A channel-ready caption.',
        ]);
        $postWithoutAssetId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'instagram',
            'planned_copy' => 'A channel-ready caption.',
        ]);

        $draftBundleId = $this->marketing->createChannelExportBundle($postWithoutAssetId, $this->userId);
        $draftBundle = $this->marketing->getChannelExportBundle($draftBundleId);
        $this->assertSame('draft', $draftBundle['status']);
        $this->assertFalse((bool) $draftBundle['asset_readiness_json']['ready']);
        $this->assertTrue((bool) $draftBundle['export_payload_json']['no_external_publish']);

        $assetId = $this->createAssetForWorkspace($this->workspaceId, 'Instagram Graphic');
        $postWithAssetId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'asset_id' => $assetId,
            'channel' => 'instagram',
            'planned_copy' => 'A channel-ready caption.',
            'required_fields' => ['caption', 'destination_url', 'asset'],
        ]);
        $readyBundleId = $this->marketing->createChannelExportBundle($postWithAssetId, $this->userId);
        $readyBundle = $this->marketing->getChannelExportBundle($readyBundleId);
        $this->assertSame('ready', $readyBundle['status']);
        $this->assertSame('social', $readyBundle['bundle_type']);
        $this->assertSame(['caption', 'destination_url', 'asset'], $readyBundle['required_fields_json']);

        $exported = $this->marketing->markChannelExportBundleExported($readyBundleId);
        $this->assertSame('exported', $exported['status']);
    }

    public function testPhaseEighteenChannelExportsPageRenders(): void
    {
        $response = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession(), ['method' => 'GET']);
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Channel Export Bundles', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('No channel export bundles match this view', (string) ($response['body'] ?? ''));

        $invalidFilter = $this->runWebEndpoint('public/marketing_channel_exports.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['status' => 'not-a-status'],
        ]);
        $this->assertSame(200, (int) ($invalidFilter['status'] ?? 0), (string) ($invalidFilter['stderr'] ?? ''));
        $this->assertStringContainsString('Invalid status filter.', (string) ($invalidFilter['body'] ?? ''));
    }

    public function testPhaseTenDistributionToolkitPublishBundleAndValidation(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Manual Distribution Source',
            'channel' => 'linkedin',
            'draft_body' => 'Manual publishing copy for a channel variant.',
            'created_by' => $this->userId,
        ]);
        $assetId = $this->marketing->createAsset([
            'title' => 'Approved Graphic',
            'asset_type' => 'image',
            'asset_url' => 'https://example.com/approved.png',
        ]);

        $postId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'asset_id' => $assetId,
            'channel' => 'linkedin',
            'publishing_checklist' => "Copy checked\nUTM added",
            'required_fields' => "Caption\nDestination URL",
            'asset_rules' => 'Use approved crop',
            'created_by' => $this->userId,
        ]);

        $post = $this->marketing->getDistributionPost($postId);
        $this->assertSame(['Copy checked', 'UTM added'], $post['publishing_checklist_json']);
        $this->assertSame(['Caption', 'Destination URL'], $post['required_fields_json']);

        $bundle = $this->marketing->getDistributionExportBundle($postId);
        $this->assertSame('Manual Distribution Source', $bundle['content_title']);
        $this->assertSame('linkedin', $bundle['channel']);

        $this->marketing->markDistributionPostPublished($postId, 'https://example.com/live-post', date('Y-m-d\TH:i'));
        $published = $this->marketing->getDistributionPost($postId);
        $this->assertSame('published', $published['status']);
        $this->assertSame('https://example.com/live-post', $published['published_url']);
    }

    public function testPhaseTenDistributionRejectsInvalidPublishedUrlAndCrossWorkspaceAsset(): void
    {
        $contentId = $this->marketing->createContentItem(['title' => 'Distribution Validation Source']);
        $postId = $this->marketing->createDistributionPost(['content_item_id' => $contentId]);

        try {
            $this->marketing->markDistributionPostPublished($postId, 'not-a-url');
            $this->fail('Invalid published URL should be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('valid published URL', $e->getMessage());
        }

        $otherWorkspaceId = $this->createWorkspace('Other Distribution Asset Workspace');
        $otherAssetId = $this->createAssetForWorkspace($otherWorkspaceId, 'Other Workspace Asset');

        $this->expectExceptionMessage('Linked record was not found in the active workspace.');
        $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'asset_id' => $otherAssetId,
        ]);
    }

    public function testPhaseElevenMarketingAnalyticsSnapshotsAndSummary(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Analytics Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Analytics Content',
            'content_type' => 'email',
            'channel' => 'email',
            'campaign_id' => $campaignId,
            'draft_body' => 'Analytics draft body.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createUtmLink([
            'url' => 'https://example.com/analytics',
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
        ]);
        $this->marketing->createLandingPage([
            'title' => 'Analytics Landing',
            'slug' => 'analytics-landing',
            'conversion_goal' => 'lead_capture',
        ]);

        $summary = $this->marketing->getMarketingAnalyticsSummary('weekly');
        $this->assertGreaterThanOrEqual(1, (int) $summary['content_velocity']['total_created']);
        $this->assertGreaterThanOrEqual(1, (int) $summary['utm_usage']['total']);
        $this->assertArrayHasKey('form_submissions', $summary);
        $this->assertArrayHasKey('attributed_revenue', $summary);

        $snapshotId = $this->marketing->createMarketingAnalyticsSnapshot('weekly', null, $this->userId);
        $snapshots = $this->marketing->listMarketingAnalyticsSnapshots('weekly');
        $this->assertSame($snapshotId, (int) $snapshots[0]['id']);
        $this->assertArrayHasKey('total_created', $snapshots[0]['content_velocity_json']);
    }

    public function testPhaseNineteenAttributionRoiAnalyticsAndSnapshots(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'ROI Campaign');
        $formId = $this->createForm($this->workspaceId, 'ROI Lead Form');
        $form = Database::queryOne('SELECT uuid FROM forms WHERE id = ?', [$formId]);
        $contentId = $this->marketing->createContentItem([
            'title' => 'ROI Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'campaign_id' => $campaignId,
            'draft_body' => 'ROI content body.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createCampaignBrief([
            'title' => 'ROI Brief',
            'campaign_id' => $campaignId,
            'budget_estimate' => '200.00',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createUtmLink([
            'url' => 'https://example.com/roi',
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'utm_source' => 'linkedin',
            'utm_medium' => 'social',
            'utm_campaign' => 'roi',
            'created_by' => $this->userId,
        ]);
        $assetId = $this->createAssetForWorkspace($this->workspaceId, 'ROI Chart');
        $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'asset_id' => $assetId,
            'channel' => 'linkedin',
            'planned_copy' => 'ROI post copy',
            'created_by' => $this->userId,
        ]);
        $landingId = $this->marketing->createLandingPage([
            'title' => 'ROI Landing',
            'slug' => 'roi-landing',
            'headline' => 'Measure marketing ROI',
            'body_sections' => [['heading' => 'Outcome', 'body' => 'Connect campaigns to revenue.']],
            'cta_blocks' => [['heading' => 'CTA', 'body' => 'Book a review']],
            'form_id' => $formId,
            'campaign_id' => $campaignId,
            'status' => 'approved',
            'conversion_goal' => 'lead_capture',
            'created_by' => $this->userId,
        ]);
        $this->marketing->publishLandingPage($landingId, $this->userId);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, assigned_to)
             VALUES (?, UUID(), 'Roi', 'roi@example.com', ?)",
            [$this->workspaceId, $this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO form_submissions
             (workspace_id, visitor_id, contact_id, form_id, form_definition_id, form_data, page_path, submitted_at, campaign_id, utm_source, utm_medium, utm_campaign)
             VALUES (?, 'visitor-roi', ?, ?, ?, '{}', '/roi-landing', NOW(), ?, 'linkedin', 'social', 'roi')",
            [$this->workspaceId, $contactId, (string) ($form['uuid'] ?? ''), $formId, $campaignId]
        );
        $model = Database::queryOne("SELECT id FROM attribution_models WHERE slug = 'last_touch' LIMIT 1");
        Database::execute(
            "INSERT INTO attribution_results
             (workspace_id, model_id, contact_id, campaign_id, conversion_event, conversion_at, touch_type, channel, attribution_weight, credited_value)
             VALUES (?, ?, ?, ?, 'deal_won', NOW(), 'form_submit', 'email', 1, 500.00)",
            [$this->workspaceId, (int) ($model['id'] ?? 1), $contactId, $campaignId]
        );

        $summary = $this->marketing->getMarketingAnalyticsSummary('weekly');
        $this->assertSame(500.0, (float) $summary['campaign_roi']['total_revenue']);
        $this->assertSame(200.0, (float) $summary['campaign_roi']['total_budget']);
        $this->assertSame(150.0, (float) $summary['campaign_roi']['roi_percent']);
        $this->assertGreaterThanOrEqual(1, (int) $summary['content_influence']['influenced_content']);
        $this->assertSame(1, (int) $summary['form_conversion']['total_submissions']);
        $this->assertSame(1, (int) $summary['landing_page_funnel']['submissions']);
        $this->assertSame(1, (int) $summary['utm_performance']['total_links']);
        $this->assertSame(500.0, (float) $summary['revenue_attribution']['total_revenue']);

        $snapshotId = $this->marketing->createMarketingAnalyticsSnapshot('weekly', null, $this->userId);
        $snapshot = $this->marketing->listMarketingAnalyticsSnapshots('weekly')[0];
        $this->assertSame($snapshotId, (int) $snapshot['id']);
        $this->assertSame(500.0, (float) $snapshot['campaign_roi_json']['total_revenue']);
        $this->assertArrayHasKey('items', $snapshot['content_influence_json']);
        $this->assertArrayHasKey('by_source_medium', $snapshot['utm_performance_json']);
        $this->assertSame(500.0, (float) $snapshot['revenue_attribution_json']['total_revenue']);
    }

    public function testPhaseTwentyQualityChecksStoreRecommendationsWithoutChangingDraft(): void
    {
        $brandId = $this->marketing->createBrandProfile([
            'name' => 'Quality Brand',
            'voice' => 'Clear and practical',
            'banned_words' => 'guaranteed',
            'created_by' => $this->userId,
        ]);
        $personaId = $this->marketing->createPersona([
            'name' => 'Quality Persona',
            'segment' => 'Operators',
            'created_by' => $this->userId,
        ]);
        $seoTopicId = $this->marketing->createSeoTopic([
            'keyword' => 'marketing operations',
            'content_item_id' => null,
            'created_by' => $this->userId,
        ]);
        $this->marketing->createContextItem([
            'item_type' => 'compliance_term',
            'title' => 'Human review required',
            'body' => 'Claims must be reviewed before external publishing.',
            'created_by' => $this->userId,
        ]);
        $draft = 'Guaranteed pipeline growth from better planning.';
        $contentId = $this->marketing->createContentItem([
            'title' => 'Quality Check Draft',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'draft_body' => $draft,
            'brand_profile_id' => $brandId,
            'persona_id' => $personaId,
            'seo_topic_id' => $seoTopicId,
            'created_by' => $this->userId,
        ]);

        $check = $this->marketing->runContentQualityCheck($contentId, $this->userId);
        $this->assertSame($contentId, (int) $check['content_item_id']);
        $this->assertLessThan(100, (int) $check['overall_score']);
        $this->assertContains('guaranteed', $check['brand_voice_json']['details']['banned_word_hits']);
        $this->assertFalse((bool) $check['metadata_json']['draft_overwritten']);
        $this->assertSame($draft, $this->marketing->getContentItem($contentId)['draft_body']);
        $this->assertNotEmpty($check['recommendations_json']);

        $otherWorkspaceId = $this->createWorkspace('Other Quality Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $otherContentId = $otherMarketing->createContentItem(['title' => 'Other Quality Content']);

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        try {
            $this->marketing->runContentQualityCheck($otherContentId, $this->userId);
            $this->fail('Cross-workspace quality check should not run.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Marketing content item not found.', $e->getMessage());
        }

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $this->expectExceptionMessage('You do not have permission to perform this marketing action.');
        $this->marketing->runContentQualityCheck($contentId, $this->userId);
    }

    public function testQualityCheckStoresAiJsonReviewWhenProviderReturnsValidJson(): void
    {
        $ai = new class extends AIService {
            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                return [
                    'surface' => $surface,
                    'prompt_key' => $promptKey,
                    'prompt_version' => 12,
                    'rendered_prompt' => 'quality prompt',
                    'context_bundle' => $bundle,
                ];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return json_encode([
                    'overall_score' => 91,
                    'recommendations' => ['Keep the CTA prominent', 'Confirm proof before approval'],
                    'brand_voice' => ['score' => 94, 'details' => ['note' => 'on voice']],
                    'persona_fit' => ['score' => 90, 'details' => ['note' => 'clear audience']],
                    'compliance' => ['score' => 89, 'details' => ['claims' => 'reviewed']],
                    'cta_quality' => ['score' => 92, 'details' => ['has_cta' => true]],
                    'seo_readiness' => ['score' => 88, 'details' => ['keyword_used' => true]],
                    'approval_risk' => ['score' => 93, 'details' => ['risk_level' => 'low']],
                ]);
            }

            public function getLastProviderStatus(): array
            {
                return ['success' => true, 'mode' => 'fake_ai'];
            }
        };
        $marketing = new Marketing(null, $ai);
        $draft = 'Book a demo to improve marketing operations with human-reviewed planning.';
        $contentId = $this->marketing->createContentItem([
            'title' => 'AI Quality Draft',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'draft_body' => $draft,
            'created_by' => $this->userId,
        ]);

        $check = $marketing->runContentQualityCheck($contentId, $this->userId);

        $this->assertSame(91, (int) $check['overall_score']);
        $this->assertSame(94, (int) $check['brand_voice_json']['score']);
        $this->assertSame('ai_quality_review', (string) $check['metadata_json']['engine']);
        $this->assertTrue((bool) $check['metadata_json']['ai_quality_review']['used']);
        $this->assertSame(12, (int) $check['metadata_json']['ai_quality_review']['prompt_version']);
        $this->assertSame($draft, (string) $this->marketing->getContentItem($contentId)['draft_body']);
    }

    public function testQualityCheckFallsBackWhenAiJsonIsInvalid(): void
    {
        $ai = new class extends AIService {
            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                return [
                    'surface' => $surface,
                    'prompt_key' => $promptKey,
                    'prompt_version' => 13,
                    'rendered_prompt' => 'quality prompt',
                    'context_bundle' => $bundle,
                ];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return 'not json';
            }

            public function getLastProviderStatus(): array
            {
                return ['success' => true, 'mode' => 'fake_ai'];
            }
        };
        $marketing = new Marketing(null, $ai);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Invalid AI Quality Draft',
            'draft_body' => 'A draft without a strong call to action.',
            'created_by' => $this->userId,
        ]);

        $check = $marketing->runContentQualityCheck($contentId, $this->userId);

        $this->assertSame('deterministic_quality_fallback', (string) $check['metadata_json']['engine']);
        $this->assertFalse((bool) $check['metadata_json']['ai_quality_review']['used']);
        $this->assertSame('invalid_json', (string) $check['metadata_json']['ai_quality_review']['provider']['json_decode_status']);
        $this->assertNotEmpty($check['recommendations_json']);
    }

    public function testPhaseEightyFourQualityReviewSummaryAddsMediaAndAiSafetySignals(): void
    {
        $brandId = $this->marketing->createBrandProfile([
            'name' => 'Phase 84 Brand',
            'voice' => 'Practical and grounded',
            'banned_words' => 'guaranteed',
            'created_by' => $this->userId,
        ]);
        $mediaId = $this->marketing->createMediaFile([
            'title' => 'Missing Alt Launch Visual',
            'media_type' => 'image',
            'source_type' => 'url',
            'source_url' => 'https://example.com/launch-visual.jpg',
            'approval_status' => 'pending',
            'created_by' => $this->userId,
        ]);
        $draft = 'Guaranteed launch growth from a tighter marketing plan.';
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 84 Quality Draft',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'draft_body' => $draft,
            'brand_profile_id' => $brandId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->attachContentMedia($contentId, [
            'media_file_id' => $mediaId,
            'role' => 'featured',
            'created_by' => $this->userId,
        ]);

        $check = $this->marketing->runContentQualityCheck($contentId, $this->userId);
        $summary = $this->marketing->getMarketingQualityReviewSummary();

        $this->assertSame($draft, (string) $this->marketing->getContentItem($contentId)['draft_body']);
        $this->assertFalse((bool) ($check['metadata_json']['draft_overwritten'] ?? true));
        $this->assertFalse((bool) ($check['metadata_json']['external_publish'] ?? true));
        $this->assertContains('missing_alt_text', (array) ($check['metadata_json']['media_accessibility']['issues'] ?? []));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['needs_review'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['risk_items'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['media_accessibility_issues'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['draft_safe_checks'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['fallback_used'] ?? 0));
        $this->assertFalse((bool) ($summary['ai_safety']['draft_overwrite'] ?? true));
        $this->assertFalse((bool) ($summary['ai_safety']['external_publish'] ?? true));
        $this->assertSame('advisory_only', (string) ($summary['manual_first_boundary']['review_mode'] ?? ''));

        $dimensionLabels = array_column((array) ($summary['dimensions'] ?? []), 'label');
        $this->assertContains('Media Accessibility', $dimensionLabels);
        $reviewTitles = array_column((array) ($summary['content_needing_review'] ?? []), 'title');
        $this->assertContains('Phase 84 Quality Draft', $reviewTitles);

        $response = $this->runWebEndpoint('public/marketing_quality.php', $this->webSession(), ['method' => 'GET']);
        $body = (string) ($response['body'] ?? '');
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Quality Review Command Center', $body);
        $this->assertStringContainsString('Media Accessibility', $body);
        $this->assertStringContainsString('Draft-Safe Checks', $body);
        $this->assertStringContainsString('manual-first', strtolower($body));
    }

    public function testPhaseEightyFiveWorkflowClosureSummarySurfacesBlockedReasons(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase 85 Blocked Review Draft',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'status' => 'draft',
            'draft_body' => 'Launch update with internal context only.',
            'blocked_reason' => 'Waiting on compliance claim approval.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->requestContentReview($contentId, $this->userId, $this->userId, date('Y-m-d H:i:s', strtotime('-1 day')));

        $summary = $this->marketing->getMarketingWorkflowClosureSummary();

        $this->assertSame('blocked', (string) ($summary['status'] ?? ''));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['blocked_content'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['overdue_reviews'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['missing_audience'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['missing_cta'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($summary['counts']['missing_media'] ?? 0));
        $this->assertFalse((bool) ($summary['manual_first_boundary']['external_publish'] ?? true));
        $this->assertFalse((bool) ($summary['manual_first_boundary']['external_send'] ?? true));

        $itemsByTitle = [];
        foreach ((array) ($summary['closure_items'] ?? []) as $item) {
            $itemsByTitle[(string) ($item['title'] ?? '')] = $item;
        }
        $this->assertArrayHasKey('Phase 85 Blocked Review Draft', $itemsByTitle);
        $this->assertSame('blocked', (string) ($itemsByTitle['Phase 85 Blocked Review Draft']['severity'] ?? ''));
        $this->assertStringContainsString('Waiting on compliance claim approval', implode(' ', (array) ($itemsByTitle['Phase 85 Blocked Review Draft']['reasons'] ?? [])));
        $this->assertContains('Resolve blocked content', array_column((array) ($summary['next_actions'] ?? []), 'label'));

        $response = $this->runWebEndpoint('public/marketing_reviews.php', $this->webSession(), ['method' => 'GET']);
        $body = (string) ($response['body'] ?? '');
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Workflow Closure Board', $body);
        $this->assertStringContainsString('Blocked Reasons', $body);
        $this->assertStringContainsString('Missing Audience', $body);
        $this->assertStringContainsString('Manual-first', $body);
    }

    public function testPhaseTwentyOneCreativeAssetProductionReadinessAndIsolation(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Creative Source',
            'content_type' => 'social_post',
            'channel' => 'instagram',
            'draft_body' => 'Creative source copy.',
            'created_by' => $this->userId,
        ]);
        $briefId = $this->marketing->createCreativeBrief([
            'title' => 'Creative Brief',
            'content_item_id' => $contentId,
            'asset_type' => 'image',
            'channel' => 'instagram',
            'objective' => 'Create a launch graphic.',
            'specs' => ['dimensions' => '1080x1350'],
            'created_by' => $this->userId,
        ]);
        $requestId = $this->marketing->createAssetRequest([
            'title' => 'Launch Graphic Request',
            'creative_brief_id' => $briefId,
            'content_item_id' => $contentId,
            'requested_asset_type' => 'image',
            'channel' => 'instagram',
            'created_by' => $this->userId,
        ]);

        $readiness = $this->marketing->getCreativeAssetReadiness($contentId);
        $this->assertFalse((bool) $readiness['ready']);
        $this->assertSame(1, (int) $readiness['open_requests']);

        $this->assertTrue($this->marketing->updateAssetRequestStatus($requestId, 'ready'));
        $assetId = $this->createAssetForWorkspace($this->workspaceId, 'Launch Graphic Asset');
        $usageId = $this->marketing->recordAssetUsage([
            'asset_id' => $assetId,
            'content_item_id' => $contentId,
            'usage_context' => 'instagram_launch',
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $usageId);

        $ready = $this->marketing->getCreativeAssetReadiness($contentId);
        $this->assertTrue((bool) $ready['ready']);
        $this->assertSame(1, (int) $ready['ready_requests']);
        $this->assertSame(1, (int) $ready['linked_assets']);

        $otherWorkspaceId = $this->createWorkspace('Other Creative Workspace');
        $otherAssetId = $this->createAssetForWorkspace($otherWorkspaceId, 'Foreign Creative Asset');
        $this->expectExceptionMessage('Linked record was not found in the active workspace.');
        $this->marketing->recordAssetUsage([
            'asset_id' => $otherAssetId,
            'content_item_id' => $contentId,
        ]);
    }

    public function testPhaseTwentyTwoRecurringOperationsCadenceAndPlanningQueue(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Operations Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Operations Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'campaign_id' => $campaignId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Operations Brief',
            'campaign_id' => $campaignId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);

        $rhythmId = $this->marketing->createRecurringRhythm([
            'name' => 'Weekly Demand Planning',
            'rhythm_type' => 'planning',
            'cadence' => 'weekly',
            'day_of_week' => '1',
            'next_run_at' => date('Y-m-d\TH:i', strtotime('+1 day')),
            'checklist' => "Review queue\nAssign owners",
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);

        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $queueId = $this->marketing->createPlanningQueueItem([
            'title' => 'Plan next blog draft',
            'week_start' => $weekStart,
            'item_type' => 'content',
            'priority' => 'high',
            'rhythm_id' => $rhythmId,
            'content_item_id' => $contentId,
            'campaign_brief_id' => $briefId,
            'campaign_id' => $campaignId,
            'due_at' => date('Y-m-d\TH:i', strtotime('+2 days')),
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);

        $templateId = $this->marketing->createChecklistTemplate([
            'name' => 'Weekly Content Cadence',
            'template_type' => 'content',
            'checklist' => "Confirm objective\nConfirm CTA",
            'created_by' => $this->userId,
        ]);

        $rhythms = $this->marketing->listRecurringRhythms(['status' => 'active']);
        $this->assertSame($rhythmId, (int) $rhythms[0]['id']);
        $this->assertSame(['Review queue', 'Assign owners'], $rhythms[0]['checklist_json']);

        $queue = $this->marketing->listPlanningQueueItems(['week_start' => $weekStart, 'open' => true]);
        $this->assertSame($queueId, (int) $queue[0]['id']);
        $this->assertSame('Operations Content', $queue[0]['content_title']);
        $this->assertSame('Operations Brief', $queue[0]['campaign_brief_title']);

        $report = $this->marketing->getMarketingOperatingReport($weekStart);
        $this->assertSame($weekStart, $report['week_start']);
        $this->assertGreaterThanOrEqual(1, (int) $report['counts']['active_rhythms']);
        $this->assertGreaterThanOrEqual(1, (int) $report['counts']['queue_this_week']);
        $this->assertGreaterThanOrEqual(1, (int) $report['counts']['checklist_templates']);

        $this->assertTrue($this->marketing->updatePlanningQueueStatus($queueId, 'done'));
        $done = $this->marketing->listPlanningQueueItems(['status' => 'done']);
        $this->assertSame($queueId, (int) $done[0]['id']);

        $templates = $this->marketing->listChecklistTemplates(['status' => 'active']);
        $this->assertSame($templateId, (int) $templates[0]['id']);
        $this->assertSame(['Confirm objective', 'Confirm CTA'], $templates[0]['checklist_json']);

        $otherWorkspaceId = $this->createWorkspace('Other Operations Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $otherContentId = $otherMarketing->createContentItem(['title' => 'Other Operations Content']);

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        $this->expectExceptionMessage('Linked record was not found in the active workspace.');
        $this->marketing->createPlanningQueueItem([
            'title' => 'Invalid cross workspace item',
            'content_item_id' => $otherContentId,
        ]);
    }

    public function testPhaseTwentyTwoRecurringOperationsRespectWritePermissions(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);

        $this->expectExceptionMessage('You do not have permission to perform this marketing action.');
        $this->marketing->createRecurringRhythm([
            'name' => 'Viewer Rhythm',
            'rhythm_type' => 'planning',
        ]);
    }

    public function testPhaseTwentyThreeAuditDiagnosticsAndStarterCleanup(): void
    {
        $starter = $this->marketing->createMarketingStarterPack($this->userId);
        $this->assertTrue((bool) $starter['created']);

        $auditId = $this->marketing->recordMarketingAuditEvent(
            'manual_review',
            'marketing_content_items',
            null,
            'Manual audit review recorded.',
            ['source' => 'unit_test'],
            $this->userId
        );
        $this->assertGreaterThan(0, $auditId);
        $events = $this->marketing->listMarketingAuditEvents(['event_type' => 'manual_review']);
        $this->assertSame($auditId, (int) $events[0]['id']);
        $this->assertSame('unit_test', $events[0]['metadata_json']['source']);

        $diagnostics = $this->marketing->getMarketingDiagnostics();
        $this->assertTrue((bool) $diagnostics['tables']['marketing_content_items']['exists']);
        $this->assertTrue((bool) $diagnostics['tables']['marketing_audit_events']['exists']);
        $this->assertTrue((bool) $diagnostics['indexes']['idx_marketing_content_workspace_updated']);
        $this->assertGreaterThan(0, (int) ($diagnostics['starter_pack']['total'] ?? 0));

        $dryRun = $this->marketing->runMarketingCleanup('starter_pack', $this->userId, true);
        $this->assertTrue((bool) $dryRun['dry_run']);
        $this->assertFalse((bool) $dryRun['archived']);
        $this->assertGreaterThan(0, (int) ($dryRun['cleanup_run_id'] ?? 0));

        $cleanup = $this->marketing->runMarketingCleanup('starter_pack', $this->userId, false);
        $this->assertFalse((bool) $cleanup['dry_run']);
        $this->assertTrue((bool) $cleanup['archived']);

        $status = $this->marketing->getMarketingOnboardingStatus();
        $this->assertTrue((bool) ($status['starter_pack']['archived'] ?? false));
        $this->assertGreaterThanOrEqual(2, count($this->marketing->listMarketingAuditEvents(['target_type' => 'marketing_cleanup_runs'])));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM marketing_content_items
             WHERE workspace_id = ? AND status <> 'archived'
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source')) = 'marketing_onboarding_starter_pack'",
            [$this->workspaceId]
        )['count'] ?? 0));
    }

    public function testPhaseTwentyThreeCleanupRequiresManagePermission(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);

        $this->expectExceptionMessage('You do not have permission to perform this marketing action.');
        $this->marketing->runMarketingCleanup('starter_pack', $this->userId, true);
    }

    public function testPhaseTwentyFourAllMarketingPagesPassReleaseSmoke(): void
    {
        $fixture = $this->createMarketingReleaseQaFixture();
        $ownerSession = $this->webSession('owner');

        $pages = [
            ['public/marketing.php', [], 'Build, launch, and learn from one guided path.'],
            ['public/marketing_launch_packet.php', [], 'Guided Launch Packet'],
            ['public/marketing_onboarding.php', [], 'Know Your Customer'],
            ['public/marketing_campaign_workspace.php', [], 'Marketing Campaign Workspace'],
            ['public/marketing_execution.php', [], 'Marketing Execution Control Center'],
            ['public/marketing_content.php', [], 'Content Studio'],
            ['public/marketing_content_edit.php', [], 'Marketing Content'],
            ['public/marketing_content_edit.php', ['id' => $fixture['content_id']], 'Marketing Content'],
            ['public/marketing_content_view.php', ['id' => $fixture['content_id']], 'Release QA Content'],
            ['public/marketing_briefs.php', [], 'Campaign Plan Board'],
            ['public/marketing_brief_edit.php', ['id' => $fixture['brief_id']], 'Campaign Brief'],
            ['public/marketing_brief_view.php', ['id' => $fixture['brief_id']], 'Release QA Brief'],
            ['public/marketing_segments.php', [], 'Audience Builder'],
            ['public/marketing_segment_edit.php', ['id' => $fixture['segment_id']], 'Audience Rules'],
            ['public/marketing_segment_view.php', ['id' => $fixture['segment_id']], 'Audience Preview'],
            ['public/marketing_audience_activation.php', [], 'Audience Activation'],
            ['public/marketing_journeys.php', [], 'Marketing Journeys'],
            ['public/marketing_journey_edit.php', ['id' => $fixture['journey_id']], 'Marketing Journey'],
            ['public/marketing_journey_view.php', ['id' => $fixture['journey_id']], 'Release QA Journey'],
            ['public/marketing_playbooks.php', [], 'Campaign Playbooks'],
            ['public/marketing_playbook_edit.php', [], 'Campaign Playbook'],
            ['public/marketing_roadmap.php', [], 'Campaign Roadmap'],
            ['public/marketing_persona_offer_matrix.php', [], 'Persona Offer Matrix'],
            ['public/marketing_calendar.php', [], 'Marketing Calendar'],
            ['public/marketing_reviews.php', [], 'Marketing Approval Workbench'],
            ['public/marketing_launch_readiness.php', [], 'Launch Readiness'],
            ['public/marketing_launch_control.php', [], 'Launch Control Room'],
            ['public/marketing_launch_checklists.php', [], 'Campaign Launch Checklists'],
            ['public/marketing_guided_workflows.php', [], 'Marketing Guided Workflows'],
            ['public/marketing_task_hub.php', [], 'Marketing Task Hub'],
            ['public/marketing_context.php', [], 'Context Library'],
            ['public/marketing_brand.php', [], 'Brand Library'],
            ['public/marketing_personas.php', [], 'Customer Personas'],
            ['public/marketing_seo.php', [], 'SEO Topics'],
            ['public/marketing_landing_pages.php', [], 'Landing Page Plans'],
            ['public/marketing_landing_page_edit.php', ['id' => $fixture['landing_page_id']], 'Landing Page'],
            ['public/marketing_landing_page_view.php', ['id' => $fixture['landing_page_id']], 'Release QA Landing'],
            ['public/marketing_landing_page_preview.php', ['token' => $fixture['preview_token']], 'Authenticated preview only'],
            ['public/marketing_email_runs.php', [], 'Marketing Email Runs'],
            ['public/marketing_assets.php', [], 'Marketing Assets'],
            ['public/marketing_distribution.php', [], 'Distribution Queue'],
            ['public/marketing_distribution_bundle.php', ['id' => $fixture['distribution_post_id']], 'Manual Launch Packet'],
            ['public/marketing_launch_proof.php', ['id' => $fixture['distribution_post_id']], 'Manual Launch Proof'],
            ['public/marketing_channel_exports.php', [], 'Channel Export Bundles'],
            ['public/marketing_utm_links.php', [], 'UTM Links'],
            ['public/marketing_performance.php', [], 'Marketing Performance'],
            ['public/marketing_handoffs.php', [], 'Lead Handoffs'],
            ['public/marketing_weekly_report.php', [], 'Weekly Marketing Report'],
            ['public/marketing_monthly_report.php', [], 'Monthly Marketing Report'],
            ['public/marketing_assistants.php', [], 'Marketing Assistants'],
            ['public/marketing_quality.php', [], 'Marketing Quality Checks'],
            ['public/marketing_creative.php', [], 'Creative Production'],
            ['public/marketing_operations.php', [], 'Marketing Operations'],
            ['public/marketing_admin.php', [], 'Marketing Admin Diagnostics'],
        ];

        foreach ($pages as [$endpoint, $query, $expectedText]) {
            $response = $this->runWebEndpoint($endpoint, $ownerSession, ['method' => 'GET', 'query' => $query]);
            $this->assertEndpointHealthy($response, 200, $endpoint);
            $this->assertStringContainsString($expectedText, (string) ($response['body'] ?? ''), $endpoint);
        }

        $homeWithPacket = $this->runWebEndpoint('public/marketing.php', $ownerSession, ['method' => 'GET']);
        $this->assertStringContainsString(
            'marketing_distribution_bundle.php?id=' . $fixture['distribution_post_id'],
            (string) ($homeWithPacket['body'] ?? '')
        );
        $this->assertStringContainsString(
            'marketing_launch_proof.php?id=' . $fixture['distribution_post_id'],
            (string) ($homeWithPacket['body'] ?? '')
        );

        $publicLanding = $this->runEndpointScript('public/marketing_landing_public.php', [
            'method' => 'GET',
            'query' => ['token' => $fixture['public_token']],
        ]);
        $this->assertEndpointHealthy($publicLanding, 200, 'public/marketing_landing_public.php');
        $this->assertStringContainsString('Release QA Landing', (string) ($publicLanding['body'] ?? ''));
    }

    public function testPhaseTwentyFourMarketingRoleAccessMatrix(): void
    {
        $guest = $this->runEndpointScript('public/marketing.php', ['method' => 'GET']);
        $this->assertEndpointHealthy($guest, 302, 'public/marketing.php guest');
        $guestPacket = $this->runEndpointScript('public/marketing_launch_packet.php', ['method' => 'GET']);
        $this->assertEndpointHealthy($guestPacket, 302, 'public/marketing_launch_packet.php guest');
        $guestProof = $this->runEndpointScript('public/marketing_launch_proof.php', ['method' => 'GET']);
        $this->assertEndpointHealthy($guestProof, 302, 'public/marketing_launch_proof.php guest');

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $viewerDashboard = $this->runWebEndpoint('public/marketing.php', $this->webSession('viewer'), ['method' => 'GET']);
        $this->assertEndpointHealthy($viewerDashboard, 200, 'viewer marketing.php');
        $this->assertStringContainsString('marketing_launch_packet.php?step=setup', (string) ($viewerDashboard['body'] ?? ''));
        $viewerPacket = $this->runWebEndpoint('public/marketing_launch_packet.php', $this->webSession('viewer'), ['method' => 'GET']);
        $this->assertEndpointHealthy($viewerPacket, 200, 'viewer marketing_launch_packet.php');
        $this->assertStringContainsString('Read-only view', (string) ($viewerPacket['body'] ?? ''));
        $this->assertStringNotContainsString('<button class="btn-premium-primary" type="submit">', (string) ($viewerPacket['body'] ?? ''));
        $viewerProof = $this->runWebEndpoint('public/marketing_launch_proof.php', $this->webSession('viewer'), ['method' => 'GET']);
        $this->assertEndpointHealthy($viewerProof, 200, 'viewer marketing_launch_proof.php');
        $this->assertStringContainsString('Complete The Packet First', (string) ($viewerProof['body'] ?? ''));
        $this->assertStringNotContainsString('class="marketing-launch-proof-form"', (string) ($viewerProof['body'] ?? ''));
        $viewerHandoffs = $this->runWebEndpoint('public/marketing_handoffs.php', $this->webSession('viewer'), ['method' => 'GET']);
        $this->assertEndpointHealthy($viewerHandoffs, 200, 'viewer marketing_handoffs.php');
        $viewerEdit = $this->runWebEndpoint('public/marketing_content_edit.php', $this->webSession('viewer'), ['method' => 'GET']);
        $this->assertEndpointHealthy($viewerEdit, 302, 'viewer marketing_content_edit.php');
        $viewerAdmin = $this->runWebEndpoint('public/marketing_admin.php', $this->webSession('viewer'), ['method' => 'GET']);
        $this->assertEndpointHealthy($viewerAdmin, 302, 'viewer marketing_admin.php');

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        $marketingPacket = $this->runWebEndpoint('public/marketing_launch_packet.php', $this->webSession('marketing'), ['method' => 'GET']);
        $this->assertEndpointHealthy($marketingPacket, 200, 'marketing marketing_launch_packet.php');
        $this->assertStringContainsString('Save foundation', (string) ($marketingPacket['body'] ?? ''));
        $marketingProof = $this->runWebEndpoint('public/marketing_launch_proof.php', $this->webSession('marketing'), ['method' => 'GET']);
        $this->assertEndpointHealthy($marketingProof, 200, 'marketing marketing_launch_proof.php');
        $this->assertStringContainsString('Complete The Packet First', (string) ($marketingProof['body'] ?? ''));
        $marketingEdit = $this->runWebEndpoint('public/marketing_content_edit.php', $this->webSession('marketing'), ['method' => 'GET']);
        $this->assertEndpointHealthy($marketingEdit, 200, 'marketing marketing_content_edit.php');
        $marketingHandoffs = $this->runWebEndpoint('public/marketing_handoffs.php', $this->webSession('marketing'), ['method' => 'GET']);
        $this->assertEndpointHealthy($marketingHandoffs, 200, 'marketing marketing_handoffs.php');
        $marketingAdmin = $this->runWebEndpoint('public/marketing_admin.php', $this->webSession('marketing'), ['method' => 'GET']);
        $this->assertEndpointHealthy($marketingAdmin, 302, 'marketing marketing_admin.php');

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $ownerPacket = $this->runWebEndpoint('public/marketing_launch_packet.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($ownerPacket, 200, 'owner marketing_launch_packet.php');
        $this->assertStringContainsString('Guided Launch Packet', (string) ($ownerPacket['body'] ?? ''));
        $ownerProof = $this->runWebEndpoint('public/marketing_launch_proof.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($ownerProof, 200, 'owner marketing_launch_proof.php');
        $this->assertStringContainsString('Manual Launch Proof', (string) ($ownerProof['body'] ?? ''));
        $ownerAdmin = $this->runWebEndpoint('public/marketing_admin.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($ownerAdmin, 200, 'owner marketing_admin.php');
        $this->assertStringContainsString('Marketing Admin Diagnostics', (string) ($ownerAdmin['body'] ?? ''));
    }

    public function testPhaseTwentyFourEndToEndReleaseWorkflow(): void
    {
        $starter = $this->marketing->createMarketingStarterPack($this->userId);
        $this->assertTrue((bool) $starter['created']);

        $fixture = $this->createMarketingReleaseQaFixture();
        $approvalId = $this->marketing->requestContentReview($fixture['content_id'], $this->userId, $this->userId, date('Y-m-d\TH:i', strtotime('+2 days')));
        $this->assertTrue($this->marketing->decideContentApproval($approvalId, 'approved', 'Release QA approved.', $this->userId));
        $this->assertSame('approved', $this->marketing->getContentItem($fixture['content_id'])['status']);

        $queue = $this->marketing->listPlanningQueueItems(['content_item_id' => $fixture['content_id']]);
        $this->assertNotEmpty($queue);

        $bundle = $this->marketing->getDistributionExportBundle($fixture['distribution_post_id']);
        $this->assertTrue((bool) ($bundle['generated_at'] ?? false));
        $channelBundleId = $this->marketing->createChannelExportBundle($fixture['distribution_post_id'], $this->userId);
        $channelBundle = $this->marketing->getChannelExportBundle($channelBundleId);
        $this->assertTrue((bool) $channelBundle['export_payload_json']['no_external_publish']);

        $quality = $this->marketing->runContentQualityCheck($fixture['content_id'], $this->userId);
        $this->assertSame($fixture['content_id'], (int) $quality['content_item_id']);
        $this->assertSame((string) $fixture['draft_body'], (string) $this->marketing->getContentItem($fixture['content_id'])['draft_body']);

        $snapshotId = $this->marketing->createMarketingAnalyticsSnapshot('weekly', null, $this->userId);
        $this->assertGreaterThan(0, $snapshotId);
        $diagnostics = $this->marketing->getMarketingDiagnostics();
        $this->assertSame('407_create_marketing_live_proof_packs.sql', (string) ($diagnostics['latest_migration']['migration_name'] ?? ''));
        $this->assertTrue((bool) $diagnostics['tables']['marketing_audit_events']['exists']);
        $this->assertTrue((bool) $diagnostics['indexes']['idx_marketing_content_workspace_updated']);
        $this->assertSame('ready', (string) ($diagnostics['ai_readiness']['status'] ?? ''));
        $this->assertSame(6, (int) ($diagnostics['ai_readiness']['prompt_count'] ?? 0));
        $this->assertSame('ready', (string) ($diagnostics['ai_readiness']['operating_status'] ?? ''));
        $this->assertSame(4, (int) ($diagnostics['ai_readiness']['operating_prompt_count'] ?? 0));
        $this->assertSame('ready', (string) ($diagnostics['ai_readiness']['creative_status'] ?? ''));
        $this->assertSame(7, (int) ($diagnostics['ai_readiness']['creative_prompt_count'] ?? 0));
        $this->assertFalse((bool) ($diagnostics['ai_readiness']['live_provider_required'] ?? true));

        $this->assertGreaterThan(0, $this->marketing->recordMarketingAuditEvent(
            'release_workflow_verified',
            'marketing_content_items',
            $fixture['content_id'],
            'Release workflow completed.',
            ['external_publish' => false, 'external_send' => false],
            $this->userId
        ));
    }

    public function testMarketingAiReleaseHardeningWorkflowStaysManualFirstAndDeterministic(): void
    {
        $ai = new class extends AIService {
            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                return [
                    'surface' => $surface,
                    'prompt_key' => $promptKey,
                    'prompt_version' => 1,
                    'rendered_prompt' => 'fake deterministic prompt',
                    'context_bundle' => $bundle,
                ];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return '';
            }

            public function getLastProviderStatus(): array
            {
                return [
                    'success' => false,
                    'mode' => 'fake_empty',
                    'message' => 'Fake AI returned no content so deterministic fallback is exercised.',
                ];
            }
        };
        $marketing = new Marketing(null, $ai);

        $campaignId = $this->createCampaign($this->workspaceId, 'AI Release Hardening Campaign');
        $formId = $this->createForm($this->workspaceId, 'AI Release Hardening Form');
        $brandId = $marketing->createBrandProfile([
            'name' => 'AI Release Brand',
            'voice' => 'Clear, grounded, practical',
            'tone' => 'Calm and useful',
            'cta_defaults' => 'Book a readiness review',
            'created_by' => $this->userId,
        ]);
        $personaId = $marketing->createPersona([
            'name' => 'AI Release Persona',
            'segment' => 'Marketing operations leaders',
            'pains' => 'Manual handoffs across campaign work',
            'goals' => 'Reviewable drafts and safe release execution',
            'created_by' => $this->userId,
        ]);
        $offerId = $marketing->createContextItem([
            'item_type' => 'offer',
            'title' => 'AI Release Offer',
            'body' => 'A deterministic release readiness review.',
            'persona_id' => $personaId,
            'created_by' => $this->userId,
        ]);
        $pillarId = $marketing->createContextItem([
            'item_type' => 'content_pillar',
            'title' => 'Manual-first AI',
            'body' => 'AI drafts and recommends while humans save, publish, and send.',
            'created_by' => $this->userId,
        ]);
        $seoTopicId = $marketing->createSeoTopic([
            'keyword' => 'manual first marketing ai',
            'intent' => 'commercial',
            'priority' => 'high',
            'created_by' => $this->userId,
        ]);
        $marketing->createContextItem([
            'item_type' => 'default_cta',
            'title' => 'Book a readiness review',
            'created_by' => $this->userId,
        ]);
        $marketing->createContextItem([
            'item_type' => 'compliance_term',
            'title' => 'Human approval required',
            'body' => 'Drafts must be reviewed before external use.',
            'created_by' => $this->userId,
        ]);

        $briefCountBefore = (int) (Database::queryOne(
            'SELECT COUNT(*) AS count FROM marketing_campaign_briefs WHERE workspace_id = ?',
            [$this->workspaceId]
        )['count'] ?? 0);
        $briefDraft = $marketing->generateCampaignBriefDraft([
            'title' => 'AI Release Brief',
            'audience' => 'Marketing operations leaders',
            'channels' => 'email, linkedin',
            'campaign_id' => $campaignId,
            'persona_id' => $personaId,
            'offer_context_item_id' => $offerId,
            'content_pillar_context_item_id' => $pillarId,
        ]);
        $this->assertSame($briefCountBefore, (int) (Database::queryOne(
            'SELECT COUNT(*) AS count FROM marketing_campaign_briefs WHERE workspace_id = ?',
            [$this->workspaceId]
        )['count'] ?? 0));
        $this->assertTrue((bool) ($briefDraft['ai_context']['provider']['fallback_used'] ?? false));
        $this->assertSame('not_attempted', (string) ($briefDraft['ai_context']['provider']['json_decode_status'] ?? ''));

        $briefFields = $briefDraft['structured_fields'];
        $briefId = $marketing->createCampaignBrief([
            'title' => 'AI Release Brief',
            'objective' => $briefFields['objective'],
            'audience' => $briefFields['audience'],
            'offer_text' => $briefFields['offer_text'],
            'key_message' => $briefFields['key_message'],
            'channels' => $briefFields['channels'],
            'channel_plan' => $briefFields['channel_plan'],
            'launch_timeline' => $briefFields['launch_timeline'],
            'success_metrics' => $briefFields['success_metrics'],
            'campaign_id' => $campaignId,
            'persona_id' => $personaId,
            'offer_context_item_id' => $offerId,
            'content_pillar_context_item_id' => $pillarId,
            'owner_user_id' => $this->userId,
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $briefId);

        $landingCountBefore = (int) (Database::queryOne(
            'SELECT COUNT(*) AS count FROM marketing_landing_pages WHERE workspace_id = ?',
            [$this->workspaceId]
        )['count'] ?? 0);
        $landingDraft = $marketing->generateLandingPageCopyDraft([
            'title' => 'AI Release Landing',
            'conversion_goal' => 'demo_request',
            'campaign_id' => $campaignId,
            'form_id' => $formId,
        ]);
        $this->assertSame($landingCountBefore, (int) (Database::queryOne(
            'SELECT COUNT(*) AS count FROM marketing_landing_pages WHERE workspace_id = ?',
            [$this->workspaceId]
        )['count'] ?? 0));
        $this->assertTrue((bool) ($landingDraft['ai_context']['provider']['fallback_used'] ?? false));
        $this->assertSame('not_attempted', (string) ($landingDraft['ai_context']['provider']['json_decode_status'] ?? ''));

        $landingFields = $landingDraft['structured_fields'];
        $landingPageId = $marketing->createLandingPage([
            'title' => 'AI Release Landing',
            'slug' => 'ai-release-landing-' . uniqid(),
            'headline' => $landingFields['headline'],
            'seo_title' => $landingFields['seo_title'],
            'meta_description' => $landingFields['meta_description'],
            'body_sections' => $landingFields['body_sections'],
            'cta_blocks' => $landingFields['cta_blocks'],
            'proof_blocks' => $landingFields['proof_blocks'],
            'faq_blocks' => $landingFields['faq_blocks'],
            'thank_you_copy' => $landingFields['thank_you_copy'],
            'conversion_goal' => 'demo_request',
            'form_id' => $formId,
            'campaign_id' => $campaignId,
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);
        $this->assertGreaterThan(0, $landingPageId);

        $originalDraft = 'Original manual-first AI release draft. Book a readiness review before launch.';
        $contentId = $marketing->createContentItem([
            'title' => 'AI Release Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'status' => 'draft',
            'funnel_stage' => 'consideration',
            'objective' => 'Explain deterministic Marketing AI release readiness.',
            'target_audience' => 'Marketing operations leaders',
            'draft_body' => $originalDraft,
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'brand_profile_id' => $brandId,
            'persona_id' => $personaId,
            'seo_topic_id' => $seoTopicId,
            'landing_page_id' => $landingPageId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);

        $toolRun = $marketing->runContentTool($contentId, 'expand', ['created_by' => $this->userId]);
        $this->assertSame('completed', $toolRun['status']);
        $this->assertSame($originalDraft, (string) ($marketing->getContentItem($contentId)['draft_body'] ?? ''));
        $this->assertStringContainsString('Why this matters:', (string) ($toolRun['result']['body'] ?? ''));

        $applied = $marketing->applyContentToolRun((int) $toolRun['id'], $this->userId);
        $appliedDraft = (string) ($applied['content_item']['draft_body'] ?? '');
        $this->assertStringContainsString('Why this matters:', $appliedDraft);
        $versions = $marketing->listContentVersions($contentId);
        $this->assertSame('Applied content tool suggestion: Expand', (string) ($versions[0]['change_summary'] ?? ''));

        $quality = $marketing->runContentQualityCheck($contentId, $this->userId);
        $this->assertSame($contentId, (int) $quality['content_item_id']);
        $this->assertSame('deterministic_quality_fallback', (string) ($quality['metadata_json']['engine'] ?? ''));
        $this->assertFalse((bool) ($quality['metadata_json']['draft_overwritten'] ?? true));
        $this->assertSame($appliedDraft, (string) ($marketing->getContentItem($contentId)['draft_body'] ?? ''));

        $assistant = $marketing->runMarketingAssistant('copywriter', [
            'prompt' => 'Create a reviewable copy suggestion only.',
            'content_item_id' => $contentId,
            'created_by' => $this->userId,
        ]);
        $this->assertSame('completed', $assistant['status']);
        $this->assertNotNull($assistant['created_tool_run_id']);
        $this->assertSame($appliedDraft, (string) ($marketing->getContentItem($contentId)['draft_body'] ?? ''));
        $assistantToolRun = $marketing->getContentToolRun((int) $assistant['created_tool_run_id']);
        $this->assertSame('first_draft', (string) ($assistantToolRun['action'] ?? ''));
        $this->assertNotEmpty((string) ($assistantToolRun['result_json']['body'] ?? ''));
        $assistantRuns = $marketing->listMarketingAssistantRuns(['content_item_id' => $contentId], 10, 0);
        $assistantRun = array_values(array_filter(
            $assistantRuns,
            static fn(array $run): bool => (int) ($run['id'] ?? 0) === (int) $assistant['id']
        ))[0] ?? [];
        $this->assertFalse((bool) ($assistantRun['provider_json']['side_effects']['external_publish'] ?? true));
        $this->assertFalse((bool) ($assistantRun['provider_json']['side_effects']['external_send'] ?? true));

        $diagnostics = $marketing->getMarketingDiagnostics();
        $this->assertSame('ready', (string) ($diagnostics['ai_readiness']['status'] ?? ''));
        $this->assertSame('ready', (string) ($diagnostics['ai_readiness']['operating_status'] ?? ''));
        $this->assertSame('ready', (string) ($diagnostics['ai_readiness']['creative_status'] ?? ''));
        $this->assertSame([], (array) ($diagnostics['ai_readiness']['missing_prompt_keys'] ?? ['missing']));
        $this->assertSame([], (array) ($diagnostics['ai_readiness']['missing_operating_prompt_keys'] ?? ['missing']));
        $this->assertSame([], (array) ($diagnostics['ai_readiness']['missing_creative_prompt_keys'] ?? ['missing']));
        $this->assertTrue((bool) ($diagnostics['ai_readiness']['deterministic_fallback_ready'] ?? false));
        $this->assertFalse((bool) ($diagnostics['ai_readiness']['live_provider_required'] ?? true));
    }

    public function testMarketingDiagnosticsReportAiReadinessWithoutRequiringLiveProvider(): void
    {
        $diagnostics = $this->marketing->getMarketingDiagnostics();
        $aiReadiness = (array) ($diagnostics['ai_readiness'] ?? []);

        $this->assertSame('marketing', (string) ($aiReadiness['surface'] ?? ''));
        $this->assertTrue((bool) ($aiReadiness['runtime_surface_available'] ?? false));
        $this->assertTrue((bool) ($aiReadiness['prompt_registry_table_exists'] ?? false));
        $this->assertSame(6, (int) ($aiReadiness['prompt_count'] ?? 0));
        $this->assertSame(4, (int) ($aiReadiness['operating_prompt_count'] ?? 0));
        $this->assertSame(7, (int) ($aiReadiness['creative_prompt_count'] ?? 0));
        $this->assertSame([], (array) ($aiReadiness['missing_prompt_keys'] ?? ['missing']));
        $this->assertSame([], (array) ($aiReadiness['missing_operating_prompt_keys'] ?? ['missing']));
        $this->assertSame([], (array) ($aiReadiness['missing_creative_prompt_keys'] ?? ['missing']));
        $this->assertContains('creative_image_prompt', (array) ($aiReadiness['active_creative_prompt_keys'] ?? []));
        $this->assertTrue((bool) ($aiReadiness['prompt_seed_migration_recorded'] ?? false));
        $this->assertTrue((bool) ($aiReadiness['operating_prompt_seed_migration_recorded'] ?? false));
        $this->assertTrue((bool) ($aiReadiness['creative_prompt_seed_migration_recorded'] ?? false));
        $this->assertTrue((bool) ($aiReadiness['deterministic_fallback_ready'] ?? false));
        $this->assertFalse((bool) ($aiReadiness['live_provider_required'] ?? true));
        $this->assertSame('ready', (string) ($aiReadiness['status'] ?? ''));
        $this->assertSame('ready', (string) ($aiReadiness['creative_status'] ?? ''));
        $this->assertNotContains('Configure a live AI provider before release.', $diagnostics['recommendations']);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $response = $this->runWebEndpoint('public/marketing_admin.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($response, 200, 'marketing_admin.php AI readiness');
        $body = (string) ($response['body'] ?? '');
        $this->assertStringContainsString('Marketing AI Readiness', $body);
        $this->assertStringContainsString('6/6 active', $body);
        $this->assertStringContainsString('4/4 active', $body);
        $this->assertStringContainsString('7/7 active', $body);
        $this->assertStringContainsString('Live provider required: No', $body);
        $this->assertStringContainsString('Deterministic Fallback', $body);
    }

    public function testPhaseFiveStrategyGapAnalysisCreatesManualFirstQueueSuggestions(): void
    {
        $before = [
            'briefs' => (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_campaign_briefs WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0),
            'content' => (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_content_items WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0),
            'landing' => (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_landing_pages WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0),
        ];

        $result = $this->marketing->runMarketingStrategyGapAnalysis(['focus' => 'context and campaign gaps', 'created_by' => $this->userId]);

        $this->assertGreaterThan(0, (int) $result['assistant_run_id']);
        $this->assertNotEmpty($result['queue_item_ids']);
        $this->assertNotEmpty($result['opportunities']);
        $this->assertFalse((bool) ($result['provider']['side_effects']['external_publish'] ?? true));
        $this->assertFalse((bool) ($result['provider']['side_effects']['external_send'] ?? true));
        $this->assertSame($before['briefs'], (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_campaign_briefs WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0));
        $this->assertSame($before['content'], (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_content_items WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0));
        $this->assertSame($before['landing'], (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_landing_pages WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0));

        $suggestions = $this->marketing->listMarketingAiQueueSuggestions('phase_5', 20, 0);
        $this->assertGreaterThanOrEqual(count($result['queue_item_ids']), count($suggestions));
        $metadata = (array) ($suggestions[0]['metadata_json'] ?? []);
        $this->assertSame('marketing_ai', (string) ($metadata['source'] ?? ''));
        $this->assertSame('phase_5', (string) ($metadata['ai_phase'] ?? ''));
        $this->assertSame('strategy_gap_analysis', (string) ($metadata['prompt_key'] ?? ''));
        $this->assertTrue((bool) ($metadata['manual_first'] ?? false));
        $this->assertFalse((bool) ($metadata['external_publish'] ?? true));
        $this->assertFalse((bool) ($metadata['external_send'] ?? true));

        $dashboard = $this->marketing->getDashboardSummary();
        $this->assertNotEmpty($dashboard['ai_strategy_gaps']);
    }

    public function testPhaseFiveStrategyGapAnalysisFallsBackWhenAiJsonIsInvalid(): void
    {
        $ai = new class extends AIService {
            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                return [
                    'surface' => $surface,
                    'prompt_key' => $promptKey,
                    'prompt_version' => 20,
                    'rendered_prompt' => 'strategy gap prompt',
                    'context_bundle' => $bundle,
                ];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return 'not json';
            }

            public function getLastProviderStatus(): array
            {
                return ['success' => true, 'mode' => 'fake_ai'];
            }
        };
        $marketing = new Marketing(null, $ai);

        $result = $marketing->runMarketingStrategyGapAnalysis(['created_by' => $this->userId]);

        $this->assertSame('invalid_json', (string) ($result['provider']['json_decode_status'] ?? ''));
        $this->assertTrue((bool) ($result['provider']['fallback_used'] ?? false));
        $this->assertNotEmpty($result['queue_item_ids']);
        $run = $marketing->listMarketingAssistantRuns(['assistant_type' => 'strategist'], 1, 0)[0] ?? [];
        $this->assertSame('strategy_gap_analysis', (string) ($run['provider_json']['prompt_key'] ?? ''));
        $this->assertSame(20, (int) ($run['provider_json']['prompt_version'] ?? 0));
    }

    public function testPhaseFiveStrategyGapSuggestionsRespectWorkspaceIsolation(): void
    {
        $this->marketing->runMarketingStrategyGapAnalysis(['created_by' => $this->userId]);
        $this->assertNotEmpty($this->marketing->listMarketingAiQueueSuggestions('phase_5', 10, 0));

        $otherWorkspaceId = $this->createWorkspace('Other AI Strategy Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $this->assertSame([], $otherMarketing->listMarketingAiQueueSuggestions('phase_5', 10, 0));
    }

    public function testPhaseFiveStrategyGapPagesRender(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $session = $this->webSession('owner');

        foreach ([
            'public/marketing.php' => 'AI Strategy Gaps',
            'public/marketing_operations.php' => 'AI Queue Suggestions',
            'public/marketing_assistants.php' => 'Run Strategy Gap Analysis',
            'public/marketing_admin.php' => 'Operating Loop Prompts',
        ] as $endpoint => $expected) {
            $response = $this->runWebEndpoint($endpoint, $session, ['method' => 'GET']);
            $this->assertEndpointHealthy($response, 200, $endpoint);
            $this->assertStringContainsString($expected, (string) ($response['body'] ?? ''), $endpoint);
        }
    }

    public function testPhaseSixCampaignPlannerCreatesQueueSuggestionsOnly(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Phase Six Campaign');
        $personaId = $this->marketing->createPersona(['name' => 'Phase Six Persona', 'created_by' => $this->userId]);
        $offerId = $this->marketing->createContextItem([
            'item_type' => 'offer',
            'title' => 'Phase Six Offer',
            'body' => 'A planning-focused offer.',
            'persona_id' => $personaId,
            'created_by' => $this->userId,
        ]);
        $before = [
            'briefs' => (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_campaign_briefs WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0),
            'content' => (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_content_items WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0),
            'landing' => (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_landing_pages WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0),
        ];

        $result = $this->marketing->runMarketingCampaignPlanner([
            'campaign_id' => $campaignId,
            'persona_id' => $personaId,
            'offer_context_item_id' => $offerId,
            'planning_goal' => 'Launch a manual-first AI campaign',
            'created_by' => $this->userId,
        ]);

        $this->assertGreaterThan(0, (int) $result['assistant_run_id']);
        $this->assertNotEmpty($result['campaign_concept']);
        $this->assertNotEmpty($result['queue_item_ids']);
        $this->assertSame($before['briefs'], (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_campaign_briefs WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0));
        $this->assertSame($before['content'], (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_content_items WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0));
        $this->assertSame($before['landing'], (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_landing_pages WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0));

        $suggestions = $this->marketing->listMarketingAiQueueSuggestions('phase_6', 10, 0);
        $this->assertNotEmpty($suggestions);
        $metadata = (array) ($suggestions[0]['metadata_json'] ?? []);
        $this->assertSame('campaign_planner', (string) ($metadata['prompt_key'] ?? ''));
        $this->assertSame('phase_6', (string) ($metadata['ai_phase'] ?? ''));
        $this->assertFalse((bool) ($metadata['external_publish'] ?? true));
        $this->assertFalse((bool) ($metadata['external_send'] ?? true));
    }

    public function testPhaseSixCampaignPlannerNormalizesFakeAiJson(): void
    {
        $ai = new class extends AIService {
            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                return [
                    'surface' => $surface,
                    'prompt_key' => $promptKey,
                    'prompt_version' => 21,
                    'rendered_prompt' => 'campaign planner prompt',
                    'context_bundle' => $bundle,
                ];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return json_encode([
                    'summary' => 'AI campaign plan ready.',
                    'campaign_concept' => 'Revenue readiness sprint',
                    'recommended_audience' => 'Operators',
                    'offer_angle' => 'Audit the launch workflow',
                    'suggested_channels' => ['email', 'linkedin'],
                    'content_set' => ['Brief', 'Landing page review'],
                    'landing_page_need' => 'Use an existing CRM landing page plan.',
                    'queue_suggestions' => [
                        [
                            'title' => 'Review campaign plan',
                            'type' => 'campaign',
                            'priority' => 'high',
                            'reason' => 'The campaign needs approval before drafting.',
                            'recommended_action' => 'Review the AI plan and assign a human owner.',
                            'week_offset' => 0,
                        ],
                    ],
                ]);
            }

            public function getLastProviderStatus(): array
            {
                return ['success' => true, 'mode' => 'fake_ai'];
            }
        };
        $marketing = new Marketing(null, $ai);

        $result = $marketing->runMarketingCampaignPlanner(['planning_goal' => 'Revenue readiness sprint', 'created_by' => $this->userId]);

        $this->assertSame('Revenue readiness sprint', (string) $result['result']['campaign_concept']);
        $this->assertContains('email', $result['result']['suggested_channels']);
        $this->assertContains('linkedin', $result['result']['suggested_channels']);
        $this->assertSame('valid', (string) ($result['provider']['json_decode_status'] ?? ''));
        $run = $marketing->listMarketingAssistantRuns(['assistant_type' => 'content_planner'], 1, 0)[0] ?? [];
        $this->assertSame('campaign_planner', (string) ($run['provider_json']['prompt_key'] ?? ''));
        $this->assertSame(21, (int) ($run['provider_json']['prompt_version'] ?? 0));
        $this->assertNull($run['created_content_item_id']);
    }

    public function testPhaseSixCampaignPlannerRejectsCrossWorkspaceLinks(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Foreign Planner Campaign');
        $otherWorkspaceId = $this->createWorkspace('Other Planner Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();

        $this->expectExceptionMessage('Linked record was not found in the active workspace.');
        $otherMarketing->runMarketingCampaignPlanner(['campaign_id' => $campaignId, 'created_by' => $this->userId]);
    }

    public function testPhaseSixCampaignPlannerPageRenders(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $response = $this->runWebEndpoint('public/marketing_assistants.php', $this->webSession('owner'), ['method' => 'GET']);

        $this->assertEndpointHealthy($response, 200, 'marketing_assistants.php campaign planner');
        $this->assertStringContainsString('AI Campaign Planner', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Generate Campaign Plan', (string) ($response['body'] ?? ''));
    }

    public function testPhaseSevenPerformanceAnalysisWorksWithSparseData(): void
    {
        $result = $this->marketing->runMarketingPerformanceAnalysis(['created_by' => $this->userId]);

        $this->assertGreaterThan(0, (int) $result['assistant_run_id']);
        $this->assertNotEmpty($result['weak_spots']);
        $this->assertNotEmpty($result['queue_item_ids']);
        $this->assertFalse((bool) ($result['provider']['side_effects']['analytics_snapshot_modified'] ?? true));
        $this->assertFalse((bool) ($result['provider']['side_effects']['draft_overwritten'] ?? true));
        $this->assertFalse((bool) ($result['provider']['side_effects']['external_publish'] ?? true));
        $this->assertFalse((bool) ($result['provider']['side_effects']['external_send'] ?? true));

        $suggestions = $this->marketing->listMarketingAiQueueSuggestions('phase_7', 10, 0);
        $this->assertNotEmpty($suggestions);
        $metadata = (array) ($suggestions[0]['metadata_json'] ?? []);
        $this->assertSame('marketing_ai', (string) ($metadata['source'] ?? ''));
        $this->assertSame('phase_7', (string) ($metadata['ai_phase'] ?? ''));
        $this->assertSame('performance_analysis', (string) ($metadata['prompt_key'] ?? ''));
        $this->assertTrue((bool) ($metadata['manual_first'] ?? false));
    }

    public function testPhaseSevenPerformanceAnalysisUsesSeededAnalyticsSignals(): void
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Phase Seven ROI Campaign');
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase Seven Performance Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'campaign_id' => $campaignId,
            'draft_body' => 'Performance source copy.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createCampaignBrief([
            'title' => 'Phase Seven ROI Brief',
            'campaign_id' => $campaignId,
            'budget_estimate' => '100.00',
            'created_by' => $this->userId,
        ]);
        $assetId = $this->createAssetForWorkspace($this->workspaceId, 'Phase Seven Asset');
        $postId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'asset_id' => $assetId,
            'channel' => 'linkedin',
            'planned_copy' => 'Performance test copy',
            'created_by' => $this->userId,
        ]);
        $this->marketing->exportDistributionPost($postId);
        $this->marketing->createUtmLink([
            'url' => 'https://example.com/phase-seven',
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'utm_source' => 'linkedin',
            'utm_medium' => 'social',
            'utm_campaign' => 'phase-seven',
            'created_by' => $this->userId,
        ]);
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, assigned_to)
             VALUES (?, UUID(), 'Phase', 'phase-seven@example.com', ?)",
            [$this->workspaceId, $this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        $model = Database::queryOne("SELECT id FROM attribution_models WHERE slug = 'last_touch' LIMIT 1");
        Database::execute(
            "INSERT INTO attribution_results
             (workspace_id, model_id, contact_id, campaign_id, conversion_event, conversion_at, touch_type, channel, attribution_weight, credited_value)
             VALUES (?, ?, ?, ?, 'deal_won', NOW(), 'manual_export', 'linkedin', 1, 250.00)",
            [$this->workspaceId, (int) ($model['id'] ?? 1), $contactId, $campaignId]
        );
        $this->marketing->createMarketingAnalyticsSnapshot('weekly', null, $this->userId);

        $result = $this->marketing->runMarketingPerformanceAnalysis(['created_by' => $this->userId]);

        $this->assertNotEmpty($result['working']);
        $this->assertNotEmpty($result['queue_item_ids']);
        $this->assertStringContainsString('UTM', implode(' ', $result['working']));
        $this->assertFalse((bool) ($result['provider']['side_effects']['analytics_snapshot_modified'] ?? true));
    }

    public function testPhaseSevenPerformanceAnalystAssistantUsesPerformancePromptAndDraftSideEffects(): void
    {
        $ai = new class extends AIService {
            public array $promptKeys = [];

            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                $this->promptKeys[] = $promptKey;
                return [
                    'surface' => $surface,
                    'prompt_key' => $promptKey,
                    'prompt_version' => 23,
                    'rendered_prompt' => 'performance analyst prompt',
                    'context_bundle' => $bundle,
                ];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return 'Performance recommendation from ' . (string) ($resolvedPrompt['prompt_key'] ?? 'unknown');
            }

            public function getLastProviderStatus(): array
            {
                return ['success' => true, 'mode' => 'fake_ai'];
            }
        };
        $marketing = new Marketing(null, $ai);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase Seven Analyst Content',
            'draft_body' => 'Do not change this performance draft.',
            'created_by' => $this->userId,
        ]);

        $assistant = $marketing->runMarketingAssistant('performance_analyst', [
            'prompt' => 'Analyze performance without changing the draft.',
            'content_item_id' => $contentId,
            'created_by' => $this->userId,
        ]);

        $this->assertSame('completed', $assistant['status']);
        $this->assertContains('performance_analysis', $ai->promptKeys);
        $this->assertNotNull($assistant['created_comment_id']);
        $this->assertSame('Do not change this performance draft.', (string) $this->marketing->getContentItem($contentId)['draft_body']);
        $run = $marketing->listMarketingAssistantRuns(['assistant_type' => 'performance_analyst'], 1, 0)[0] ?? [];
        $this->assertSame('performance_analysis', (string) ($run['provider_json']['prompt_key'] ?? ''));
        $this->assertSame(23, (int) ($run['provider_json']['prompt_version'] ?? 0));
        $this->assertFalse((bool) ($run['provider_json']['side_effects']['external_publish'] ?? true));
        $this->assertFalse((bool) ($run['provider_json']['side_effects']['external_send'] ?? true));
    }

    public function testPhaseSevenPerformancePageRenders(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $response = $this->runWebEndpoint('public/marketing_performance.php', $this->webSession('owner'), ['method' => 'GET']);

        $this->assertEndpointHealthy($response, 200, 'marketing_performance.php phase seven');
        $this->assertStringContainsString('AI Performance Analysis', (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Run Performance Analysis', (string) ($response['body'] ?? ''));
    }

    public function testPhaseEightIntegrationReadinessReportsMissingManualPrerequisites(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase Eight Readiness Content',
            'content_type' => 'social_post',
            'channel' => 'instagram',
            'draft_body' => 'Manual export readiness copy.',
            'created_by' => $this->userId,
        ]);
        $this->marketing->requestContentReview($contentId, $this->userId, $this->userId, date('Y-m-d\TH:i', strtotime('+1 day')));
        $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'instagram',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createLandingPage([
            'title' => 'Phase Eight Draft Landing',
            'slug' => 'phase-eight-draft-' . uniqid(),
            'headline' => '',
            'body_sections' => [],
            'cta_blocks' => [],
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);
        $this->marketing->createEmailCampaignRun([
            'name' => 'Phase Eight Email Run',
            'content_item_id' => $contentId,
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);

        $readiness = $this->marketing->getMarketingIntegrationReadiness();

        $this->assertSame('attention', (string) ($readiness['status'] ?? ''));
        $this->assertTrue((bool) ($readiness['manual_first'] ?? false));
        $this->assertTrue((bool) ($readiness['readiness_only'] ?? false));
        $this->assertFalse((bool) ($readiness['external_publish'] ?? true));
        $this->assertFalse((bool) ($readiness['external_send'] ?? true));
        $this->assertFalse((bool) ($readiness['checks']['channel_exports']['ready'] ?? true));
        $this->assertFalse((bool) ($readiness['checks']['assets']['ready'] ?? true));
        $this->assertFalse((bool) ($readiness['checks']['utm_links']['ready'] ?? true));
        $this->assertFalse((bool) ($readiness['checks']['approvals']['ready'] ?? true));
        $this->assertFalse((bool) ($readiness['checks']['landing_page_tokens']['ready'] ?? true));
        $this->assertFalse((bool) ($readiness['checks']['email_exports']['ready'] ?? true));
        $this->assertNotEmpty($readiness['recommendations']);
    }

    public function testPhaseEightReadinessReviewCreatesQueueSuggestionsOnly(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Phase Eight Queue Content',
            'content_type' => 'social_post',
            'channel' => 'instagram',
            'draft_body' => 'Queue-only readiness copy.',
            'created_by' => $this->userId,
        ]);
        $distributionPostId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'channel' => 'instagram',
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);
        $emailRunId = $this->marketing->createEmailCampaignRun([
            'name' => 'Phase Eight Queue Email',
            'content_item_id' => $contentId,
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Phase Eight Queue Landing',
            'slug' => 'phase-eight-queue-' . uniqid(),
            'headline' => 'Readiness queue',
            'body_sections' => [['heading' => 'Plan', 'body' => 'Prepare review.']],
            'cta_blocks' => [['heading' => 'CTA', 'body' => 'Book review']],
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);
        $before = [
            'content_status' => (string) ($this->marketing->getContentItem($contentId)['status'] ?? ''),
            'distribution_status' => (string) ($this->marketing->getDistributionPost($distributionPostId)['status'] ?? ''),
            'email_status' => (string) ($this->marketing->getEmailCampaignRun($emailRunId)['status'] ?? ''),
            'landing_status' => (string) ($this->marketing->getLandingPage($landingPageId)['status'] ?? ''),
            'publications' => (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_landing_page_publications WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0),
            'channel_bundles' => (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_channel_export_bundles WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0),
        ];

        $result = $this->marketing->runMarketingIntegrationReadinessReview(['created_by' => $this->userId]);

        $this->assertGreaterThan(0, (int) $result['assistant_run_id']);
        $this->assertNotEmpty($result['queue_item_ids']);
        $this->assertFalse((bool) ($result['provider']['side_effects']['external_publish'] ?? true));
        $this->assertFalse((bool) ($result['provider']['side_effects']['external_send'] ?? true));
        $this->assertFalse((bool) ($result['provider']['side_effects']['landing_publication_changed'] ?? true));
        $this->assertFalse((bool) ($result['provider']['side_effects']['channel_export_status_changed'] ?? true));
        $this->assertFalse((bool) ($result['provider']['side_effects']['email_run_status_changed'] ?? true));
        $this->assertSame($before['content_status'], (string) ($this->marketing->getContentItem($contentId)['status'] ?? ''));
        $this->assertSame($before['distribution_status'], (string) ($this->marketing->getDistributionPost($distributionPostId)['status'] ?? ''));
        $this->assertSame($before['email_status'], (string) ($this->marketing->getEmailCampaignRun($emailRunId)['status'] ?? ''));
        $this->assertSame($before['landing_status'], (string) ($this->marketing->getLandingPage($landingPageId)['status'] ?? ''));
        $this->assertSame($before['publications'], (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_landing_page_publications WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0));
        $this->assertSame($before['channel_bundles'], (int) (Database::queryOne('SELECT COUNT(*) AS count FROM marketing_channel_export_bundles WHERE workspace_id = ?', [$this->workspaceId])['count'] ?? 0));

        $suggestions = $this->marketing->listMarketingAiQueueSuggestions('phase_8', 10, 0);
        $this->assertNotEmpty($suggestions);
        $metadata = (array) ($suggestions[0]['metadata_json'] ?? []);
        $this->assertSame('integration_readiness', (string) ($metadata['prompt_key'] ?? ''));
        $this->assertSame('phase_8', (string) ($metadata['ai_phase'] ?? ''));
        $this->assertTrue((bool) ($metadata['manual_first'] ?? false));
        $this->assertFalse((bool) ($metadata['external_publish'] ?? true));
        $this->assertFalse((bool) ($metadata['external_send'] ?? true));
    }

    public function testPhaseEightReadinessPanelsRender(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $session = $this->webSession('owner');

        foreach ([
            'public/marketing_admin.php' => 'Integration Readiness',
            'public/marketing_channel_exports.php' => 'Run Readiness Review',
        ] as $endpoint => $expected) {
            $response = $this->runWebEndpoint($endpoint, $session, ['method' => 'GET']);
            $this->assertEndpointHealthy($response, 200, $endpoint);
            $this->assertStringContainsString($expected, (string) ($response['body'] ?? ''));
        }
    }

    public function testPhaseTwentyFiveReleaseUxPolishRendersActionableEmptyStates(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'owner', $this->userId);
        $session = $this->webSession('owner');

        $checks = [
            ['public/marketing.php', 'Start And Plan'],
            ['public/marketing.php', 'Distribute And Measure'],
            ['public/marketing_content.php', 'Reset filters, open setup, or create the first draft'],
            ['public/marketing_calendar.php', 'Use a broader date window or add a launch, review, publishing, or operations milestone.'],
            ['public/marketing_reviews.php', 'request review from a content detail page'],
            ['public/marketing_operations.php', 'open setup to create a starter operating rhythm'],
            ['public/marketing_admin.php', 'Manager-only controls for demo/starter data'],
            ['public/marketing_admin.php', 'No hard delete'],
        ];

        foreach ($checks as [$endpoint, $expected]) {
            $response = $this->runWebEndpoint($endpoint, $session, ['method' => 'GET']);
            $this->assertEndpointHealthy($response, 200, $endpoint);
            $this->assertStringContainsString($expected, (string) ($response['body'] ?? ''), $endpoint);
        }
    }

    public function testPhaseTwelveMarketingAssistantsRunWithAllowedDraftSideEffects(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Assistant Source Content',
            'draft_body' => 'Assistant source copy.',
            'created_by' => $this->userId,
        ]);

        $planner = $this->marketing->runMarketingAssistant('content_planner', [
            'prompt' => 'Plan a launch content calendar',
            'create_draft' => '1',
            'created_by' => $this->userId,
        ]);
        $this->assertSame('completed', $planner['status']);
        $this->assertNotNull($planner['created_content_item_id']);
        $this->assertNotNull($this->marketing->getContentItem((int) $planner['created_content_item_id']));

        $copywriter = $this->marketing->runMarketingAssistant('copywriter', [
            'prompt' => 'Write a stronger first draft',
            'content_item_id' => $contentId,
            'created_by' => $this->userId,
        ]);
        $this->assertSame('completed', $copywriter['status']);
        $this->assertNotNull($copywriter['created_tool_run_id']);

        $analyst = $this->marketing->runMarketingAssistant('performance_analyst', [
            'prompt' => 'Comment with performance recommendations',
            'content_item_id' => $contentId,
            'created_by' => $this->userId,
        ]);
        $this->assertSame('completed', $analyst['status']);
        $this->assertNotNull($analyst['created_comment_id']);

        $runs = $this->marketing->listMarketingAssistantRuns([], 10, 0);
        $this->assertGreaterThanOrEqual(3, count($runs));
        $this->assertArrayHasKey('context_completeness', $runs[0]['context_used_json']);
        $this->assertContains($runs[0]['provider_json']['prompt_key'], ['assistant_strategist', 'assistant_copywriter', 'performance_analysis']);
        $this->assertFalse((bool) ($runs[0]['provider_json']['side_effects']['external_publish'] ?? true));
    }

    public function testPhaseSeventyEightAssistantCommandCenterSurfacesSafeModes(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Assistant Command Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'draft_body' => 'Draft for assistant command testing.',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Assistant Command Landing',
            'slug' => 'assistant-command-landing',
            'headline' => 'Use assistant context safely',
            'created_by' => $this->userId,
        ]);

        $this->marketing->runMarketingAssistant('copywriter', [
            'prompt' => 'Improve the hero and CTA copy without overwriting the draft.',
            'content_item_id' => $contentId,
            'landing_page_id' => $landingPageId,
            'created_by' => $this->userId,
        ]);

        $center = $this->marketing->getMarketingAssistantCommandCenter($this->userId);
        $this->assertCount(count(Marketing::ASSISTANT_TYPES), (array) ($center['commands'] ?? []));
        $labels = array_column((array) ($center['commands'] ?? []), 'label');
        $this->assertContains('Copywriter', $labels);
        $this->assertContains('Landing Page Optimizer', $labels);
        $copywriterCommand = array_values(array_filter(
            (array) ($center['commands'] ?? []),
            static fn(array $command): bool => (string) ($command['assistant_type'] ?? '') === 'copywriter'
        ))[0] ?? [];
        $this->assertGreaterThanOrEqual(1, (int) ($copywriterCommand['recent_runs'] ?? 0));
        $this->assertStringContainsString('No assistant publishes', implode(' ', (array) ($center['guardrails'] ?? [])));
        $this->assertNotEmpty($center['next_best_actions']['actions'] ?? []);

        $page = $this->runWebEndpoint('public/marketing_assistants.php', $this->webSession('owner'), ['method' => 'GET']);
        $this->assertEndpointHealthy($page, 200, 'marketing_assistants.php assistant command center');
        $body = (string) ($page['body'] ?? '');
        $this->assertStringContainsString('Specialists', $body);
        $this->assertStringContainsString('Landing Page Optimizer', $body);
        $this->assertStringContainsString('Linked Landing Page', $body);
        $this->assertStringContainsString('No assistant publishes, sends email, posts to social, changes ads, or overwrites user drafts automatically.', $body);
    }

    public function testMarketingAssistantsUseAssistantSpecificPromptRuns(): void
    {
        $ai = new class extends AIService {
            public array $promptKeys = [];

            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                $this->promptKeys[] = $promptKey;
                return [
                    'surface' => $surface,
                    'prompt_key' => $promptKey,
                    'prompt_version' => $promptKey === 'assistant_copywriter' ? 22 : 21,
                    'rendered_prompt' => 'assistant prompt',
                    'context_bundle' => $bundle,
                ];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return 'AI recommendation from ' . (string) ($resolvedPrompt['prompt_key'] ?? 'unknown');
            }

            public function getLastProviderStatus(): array
            {
                return ['success' => true, 'mode' => 'fake_ai'];
            }
        };
        $marketing = new Marketing(null, $ai);
        $contentId = $this->marketing->createContentItem([
            'title' => 'Assistant Prompt Content',
            'draft_body' => 'Original assistant prompt draft.',
            'created_by' => $this->userId,
        ]);

        $strategist = $marketing->runMarketingAssistant('strategist', [
            'prompt' => 'Recommend the safest launch move',
            'created_by' => $this->userId,
        ]);
        $copywriter = $marketing->runMarketingAssistant('copywriter', [
            'prompt' => 'Draft copy suggestion',
            'content_item_id' => $contentId,
            'created_by' => $this->userId,
        ]);

        $runs = $marketing->listMarketingAssistantRuns([], 10, 0);
        $runById = [];
        foreach ($runs as $run) {
            $runById[(int) $run['id']] = $run;
        }

        $this->assertContains('assistant_strategist', $ai->promptKeys);
        $this->assertContains('assistant_copywriter', $ai->promptKeys);
        $this->assertSame('assistant_strategist', $runById[(int) $strategist['id']]['provider_json']['prompt_key']);
        $this->assertSame('assistant_copywriter', $runById[(int) $copywriter['id']]['provider_json']['prompt_key']);
        $this->assertSame(22, (int) $runById[(int) $copywriter['id']]['provider_json']['prompt_version']);
        $this->assertNotNull($copywriter['created_tool_run_id']);
        $this->assertSame('Original assistant prompt draft.', (string) $this->marketing->getContentItem($contentId)['draft_body']);
    }

    public function testPhaseTwelveMarketingAssistantsRespectWorkspaceIsolation(): void
    {
        $contentId = $this->marketing->createContentItem(['title' => 'Private Assistant Content']);
        $otherWorkspaceId = $this->createWorkspace('Other Assistant Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();

        $this->expectExceptionMessage('Linked record was not found in the active workspace.');
        $otherMarketing->runMarketingAssistant('copywriter', ['content_item_id' => $contentId]);
    }

    public function testPhaseFifteenSavedViewsPreferencesAndWorkbenchSummary(): void
    {
        $contentId = $this->marketing->createContentItem([
            'title' => 'Workbench Draft',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'status' => 'draft',
            'owner_user_id' => $this->userId,
            'scheduled_at' => date('Y-m-d\TH:i', strtotime('+3 days')),
            'created_by' => $this->userId,
        ]);
        $this->marketing->createCalendarMilestone([
            'title' => 'Draft publish review',
            'milestone_type' => 'review',
            'milestone_date' => date('Y-m-d', strtotime('+2 days')),
            'content_item_id' => $contentId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);

        $viewId = $this->marketing->createSavedView([
            'name' => 'My Draft Blog Work',
            'view_type' => 'content',
            'scope' => 'private',
            'view_mode' => 'board',
            'filters' => ['status' => 'draft', 'channel' => 'blog'],
            'sort' => ['field' => 'scheduled_at', 'direction' => 'asc'],
            'is_default' => true,
            'user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);

        $views = $this->marketing->listSavedViews('content', $this->userId);
        $this->assertCount(1, $views);
        $this->assertSame('My Draft Blog Work', $views[0]['name']);
        $this->assertSame('draft', $views[0]['filters_json']['status']);
        $this->assertSame('board', $views[0]['view_mode']);

        $preferences = $this->marketing->updateWorkbenchPreferences([
            'default_view_mode' => 'board',
            'default_saved_view_id' => $viewId,
            'preferences' => ['density' => 'compact'],
        ], $this->userId);
        $this->assertSame('board', $preferences['default_view_mode']);
        $this->assertSame('compact', $preferences['preferences_json']['density']);

        $summary = $this->marketing->getMarketingWorkbenchSummary($this->userId);
        $this->assertNotEmpty($summary['assigned_items']);
        $this->assertNotEmpty($summary['upcoming_calendar']);
        $this->assertNotEmpty($summary['saved_views']);
        $this->assertSame('board', $summary['preferences']['default_view_mode']);

        $this->marketing->updateSavedView($viewId, ['name' => 'Updated Draft Blog Work']);
        $updated = $this->marketing->getSavedView($viewId, $this->userId);
        $this->assertSame('Updated Draft Blog Work', $updated['name']);
    }

    public function testPhaseFifteenBulkContentUpdatesAreScopedAndLogged(): void
    {
        $firstId = $this->marketing->createContentItem(['title' => 'Bulk One', 'owner_user_id' => $this->userId]);
        $secondId = $this->marketing->createContentItem(['title' => 'Bulk Two', 'owner_user_id' => $this->userId]);

        $result = $this->marketing->bulkUpdateContentItems([$firstId, $secondId], [
            'status' => 'scheduled',
            'scheduled_at' => date('Y-m-d\TH:i', strtotime('+5 days')),
        ], $this->userId);

        $this->assertSame(2, $result['updated_count']);
        $this->assertSame('scheduled', $this->marketing->getContentItem($firstId)['status']);
        $this->assertSame('scheduled', $this->marketing->getContentItem($secondId)['status']);
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM marketing_bulk_action_log WHERE workspace_id = ? AND action = 'content_bulk_update'",
            [$this->workspaceId]
        )['count'] ?? 0));

        $otherWorkspaceId = $this->createWorkspace('Other Bulk Workspace');
        WorkspaceContext::activateRuntimeWorkspace($otherWorkspaceId, $this->userId, 'owner');
        $otherMarketing = new Marketing();
        $otherContentId = $otherMarketing->createContentItem(['title' => 'Other Workspace Bulk']);

        WorkspaceContext::activateRuntimeWorkspace($this->workspaceId, $this->userId, 'owner');
        $this->expectExceptionMessage('Marketing content item not found.');
        $this->marketing->bulkUpdateContentItems([$otherContentId], ['status' => 'approved'], $this->userId);
    }

    public function testPhaseFifteenSavedViewPermissions(): void
    {
        $workspaceViewId = $this->marketing->createSavedView([
            'name' => 'Workspace View',
            'scope' => 'workspace',
            'view_type' => 'content',
            'created_by' => $this->userId,
            'user_id' => $this->userId,
        ]);

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'marketing', $this->userId);
        try {
            $this->marketing->deleteSavedView($workspaceViewId);
            $this->fail('Marketing role should not delete shared workspace views.');
        } catch (\RuntimeException $e) {
            $this->assertSame('You do not have permission to perform this marketing action.', $e->getMessage());
        }

        $privateViewId = $this->marketing->createSavedView([
            'name' => 'Private Marketing View',
            'scope' => 'private',
            'view_type' => 'content',
            'created_by' => $this->userId,
            'user_id' => $this->userId,
        ]);
        $this->assertTrue($this->marketing->deleteSavedView($privateViewId));

        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);
        $this->expectExceptionMessage('You do not have permission to perform this marketing action.');
        $this->marketing->createSavedView([
            'name' => 'Viewer View',
            'scope' => 'private',
            'view_type' => 'content',
            'created_by' => $this->userId,
            'user_id' => $this->userId,
        ]);
    }

    public function testMarketingPagesRenderForPermittedUser(): void
    {
        $marketingProAccess = (new \CRM\Services\MarketingMarketplaceGateService())->accessForUser(
            ['id' => $this->userId],
            \CRM\Services\MarketingMarketplaceGateService::FEATURE_MARKETING_PRO
        );
        $this->assertTrue(!empty($marketingProAccess['can_run']), json_encode($marketingProAccess, JSON_UNESCAPED_SLASHES));

        $response = $this->runWebEndpoint('public/marketing.php', $this->webSession(), ['method' => 'GET']);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), json_encode([
            'headers' => $response['headers'] ?? [],
            'stderr' => $response['stderr'] ?? '',
        ], JSON_UNESCAPED_SLASHES));
        $this->assertStringContainsString('Campaign Manager', $body);
        $this->assertStringContainsString('marketing-founder-shell', $body);
        $this->assertStringContainsString('Advanced Marketing Operations', $body);

        $listResponse = $this->runWebEndpoint('public/marketing_content.php', $this->webSession(), ['method' => 'GET']);
        $listBody = (string) ($listResponse['body'] ?? '');
        $this->assertSame(200, (int) ($listResponse['status'] ?? 0), (string) ($listResponse['stderr'] ?? ''));
        $this->assertStringContainsString('No marketing content matches this view', $listBody);
        $this->assertStringContainsString('Save Current View', $listBody);
    }

    public function testPhaseTwoPagesRenderForPermittedUser(): void
    {
        $contentId = $this->marketing->createContentItem(['title' => 'Reviewable Content']);
        $this->marketing->requestContentReview($contentId, $this->userId);

        foreach ([
            'public/marketing_briefs.php' => 'Campaign Briefs',
            'public/marketing_calendar.php' => 'Marketing Calendar',
            'public/marketing_reviews.php' => 'Marketing Approval Workbench',
            'public/marketing_onboarding.php' => 'Marketing Setup',
        ] as $endpoint => $expectedText) {
            $response = $this->runWebEndpoint($endpoint, $this->webSession(), ['method' => 'GET']);
            $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
            $this->assertStringContainsString($expectedText, (string) ($response['body'] ?? ''));
        }
    }

    public function testPhaseFourPagesRenderForPermittedUser(): void
    {
        foreach ([
            'public/marketing_assets.php' => 'Marketing Assets',
            'public/marketing_distribution.php' => 'Distribution Queue',
            'public/marketing_utm_links.php' => 'UTM Links',
            'public/marketing_performance.php' => 'Marketing Performance',
            'public/marketing_weekly_report.php' => 'Weekly Marketing Report',
            'public/marketing_monthly_report.php' => 'Monthly Marketing Report',
            'public/marketing_assistants.php' => 'Marketing Assistants',
            'public/marketing_quality.php' => 'Marketing Quality Checks',
            'public/marketing_creative.php' => 'Creative Production',
            'public/marketing_operations.php' => 'Marketing Operations',
            'public/marketing_admin.php' => 'Marketing Admin Diagnostics',
        ] as $endpoint => $expectedText) {
            $response = $this->runWebEndpoint($endpoint, $this->webSession(), ['method' => 'GET']);
            $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
            $this->assertStringContainsString($expectedText, (string) ($response['body'] ?? ''));
        }
    }

    public function testPhaseThreePagesRenderForPermittedUser(): void
    {
        foreach ([
            'public/marketing_brand.php' => 'Brand Library',
            'public/marketing_personas.php' => 'Personas',
            'public/marketing_seo.php' => 'SEO Topics',
            'public/marketing_segments.php' => 'Audience Segments',
            'public/marketing_landing_pages.php' => 'Landing Page Plans',
            'public/marketing_context.php' => 'Marketing Context Hub',
            'public/marketing_roadmap.php' => 'Campaign Roadmap',
            'public/marketing_persona_offer_matrix.php' => 'Persona Offer Matrix',
        ] as $endpoint => $expectedText) {
            $response = $this->runWebEndpoint($endpoint, $this->webSession(), ['method' => 'GET']);
            $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
            $this->assertStringContainsString($expectedText, (string) ($response['body'] ?? ''));
        }
    }

    public function testViewerCannotOpenEditPage(): void
    {
        Authorization::assignWorkspaceUserRoleBySlug($this->workspaceId, $this->userId, 'viewer', $this->userId);

        $response = $this->runWebEndpoint('public/marketing_content_edit.php', $this->webSession('viewer'), ['method' => 'GET']);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));

        $onboardingResponse = $this->runWebEndpoint('public/marketing_onboarding.php', $this->webSession('viewer'), ['method' => 'GET']);
        $this->assertSame(200, (int) ($onboardingResponse['status'] ?? 0), (string) ($onboardingResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Marketing Setup', (string) ($onboardingResponse['body'] ?? ''));
        $this->assertStringContainsString('Read-only access', (string) ($onboardingResponse['body'] ?? ''));
    }

    private function createCampaign(int $workspaceId, string $name): int
    {
        Database::execute(
            "INSERT INTO campaigns (workspace_id, uuid, name, description, objective, channel_mix, status, created_by)
             VALUES (?, UUID(), ?, '', 'outbound', '[\"email\"]', 'active', ?)",
            [$workspaceId, $name, $this->userId]
        );

        return (int) Database::lastInsertId();
    }

    private function createForm(int $workspaceId, string $name): int
    {
        Database::execute(
            "INSERT INTO forms (workspace_id, uuid, name, fields, success_message, created_by)
             VALUES (?, UUID(), ?, '[]', 'Thanks', ?)",
            [$workspaceId, $name, $this->userId]
        );

        return (int) Database::lastInsertId();
    }

    private function createTask(int $workspaceId, string $title): int
    {
        Database::execute(
            "INSERT INTO tasks (workspace_id, title, description, created_by, status, priority)
             VALUES (?, ?, '', ?, 'pending', 'medium')",
            [$workspaceId, $title, $this->userId]
        );

        return (int) Database::lastInsertId();
    }

    private function createAssetForWorkspace(int $workspaceId, string $title): int
    {
        Database::execute(
            "INSERT INTO marketing_assets (workspace_id, uuid, title, asset_type, asset_url)
             VALUES (?, UUID(), ?, 'image', 'https://example.com/asset.png')",
            [$workspaceId, $title]
        );

        return (int) Database::lastInsertId();
    }

    private function createWorkspace(string $name): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (UUID(), ?, ?, 'active', 'trialing', ?)",
            [$name, strtolower(str_replace(' ', '-', $name)) . '-' . uniqid(), $this->userId]
        );
        $workspaceId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'owner', 'active', 1, NOW(), ?)",
            [$workspaceId, $this->userId, $this->userId]
        );
        Authorization::assignWorkspaceUserRoleBySlug($workspaceId, $this->userId, 'owner', $this->userId);

        return $workspaceId;
    }

    private function countStarterRows(string $table): int
    {
        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM {$table}
             WHERE workspace_id = ?
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source')) = 'marketing_onboarding_starter_pack'",
            [$this->workspaceId]
        )['count'] ?? 0);
    }

    private function countActiveStarterContentRows(int $workspaceId): int
    {
        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM marketing_content_items
             WHERE workspace_id = ?
               AND status <> 'archived'
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source')) = 'marketing_onboarding_starter_pack'",
            [$workspaceId]
        )['count'] ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    private function createMarketingReleaseQaFixture(): array
    {
        $campaignId = $this->createCampaign($this->workspaceId, 'Release QA Campaign');
        $formId = $this->createForm($this->workspaceId, 'Release QA Form');
        $segmentId = $this->marketing->createAudienceSegment([
            'name' => 'Release QA Segment',
            'description' => 'Release validation audience.',
            'status' => 'active',
            'rules' => [],
            'created_by' => $this->userId,
        ]);
        $brandId = $this->marketing->createBrandProfile([
            'name' => 'Release QA Brand',
            'voice' => 'Clear, practical, confident',
            'tone' => 'Helpful',
            'cta_defaults' => 'Book a planning session',
            'created_by' => $this->userId,
        ]);
        $personaId = $this->marketing->createPersona([
            'name' => 'Release QA Persona',
            'segment' => 'Operations leaders',
            'pains' => 'Scattered campaign execution',
            'goals' => 'Predictable marketing cadence',
            'created_by' => $this->userId,
        ]);
        $offerId = $this->marketing->createContextItem([
            'item_type' => 'offer',
            'title' => 'Release QA Offer',
            'body' => 'A guided marketing operations review.',
            'persona_id' => $personaId,
            'created_by' => $this->userId,
        ]);
        $pillarId = $this->marketing->createContextItem([
            'item_type' => 'content_pillar',
            'title' => 'Release QA Pillar',
            'body' => 'Marketing operating rhythm.',
            'created_by' => $this->userId,
        ]);
        $seoTopicId = $this->marketing->createSeoTopic([
            'keyword' => 'marketing release readiness',
            'intent' => 'commercial',
            'priority' => 'high',
            'created_by' => $this->userId,
        ]);
        $landingPageId = $this->marketing->createLandingPage([
            'title' => 'Release QA Landing',
            'slug' => 'release-qa-landing-' . uniqid(),
            'headline' => 'Release QA Landing',
            'meta_description' => 'A safe release QA landing page preview.',
            'body_sections' => [['heading' => 'Plan', 'body' => 'Coordinate the release workflow.']],
            'cta_blocks' => [['heading' => 'Next Step', 'body' => 'Book the readiness review.']],
            'proof_blocks' => [['heading' => 'Proof', 'body' => 'Connected content and pipeline workflows.']],
            'faq_blocks' => [['heading' => 'Is this published externally?', 'body' => 'No. This is a controlled release QA path.']],
            'conversion_goal' => 'demo_request',
            'form_id' => $formId,
            'campaign_id' => $campaignId,
            'audience_segment_id' => $segmentId,
            'status' => 'approved',
            'created_by' => $this->userId,
        ]);
        $landingPage = $this->marketing->getLandingPage($landingPageId);
        $publication = $this->marketing->publishLandingPage($landingPageId, $this->userId);
        $briefId = $this->marketing->createCampaignBrief([
            'title' => 'Release QA Brief',
            'objective' => 'Validate release readiness across Marketing.',
            'audience' => 'Marketing operators',
            'audience_segment_id' => $segmentId,
            'campaign_id' => $campaignId,
            'persona_id' => $personaId,
            'offer_context_item_id' => $offerId,
            'content_pillar_context_item_id' => $pillarId,
            'landing_page_id' => $landingPageId,
            'owner_user_id' => $this->userId,
            'status' => 'active',
            'created_by' => $this->userId,
        ]);
        $draftBody = 'Release QA draft body with a clear CTA to book a readiness review.';
        $contentId = $this->marketing->createContentItem([
            'title' => 'Release QA Content',
            'content_type' => 'blog_post',
            'channel' => 'blog',
            'status' => 'draft',
            'funnel_stage' => 'consideration',
            'objective' => 'Explain how to validate the marketing release.',
            'target_audience' => 'Marketing operators',
            'draft_body' => $draftBody,
            'campaign_id' => $campaignId,
            'campaign_brief_id' => $briefId,
            'brand_profile_id' => $brandId,
            'persona_id' => $personaId,
            'seo_topic_id' => $seoTopicId,
            'landing_page_id' => $landingPageId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->createCalendarMilestone([
            'title' => 'Release QA review milestone',
            'milestone_type' => 'review',
            'milestone_date' => date('Y-m-d', strtotime('+3 days')),
            'content_item_id' => $contentId,
            'campaign_brief_id' => $briefId,
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $assetId = $this->marketing->createAsset([
            'title' => 'Release QA Asset',
            'asset_type' => 'image',
            'asset_url' => 'https://example.com/release-qa-asset.png',
            'channel' => 'blog',
            'created_by' => $this->userId,
        ]);
        $distributionPostId = $this->marketing->createDistributionPost([
            'content_item_id' => $contentId,
            'asset_id' => $assetId,
            'channel' => 'blog',
            'planned_copy' => $draftBody,
            'publishing_checklist' => ['Review copy', 'Confirm CTA'],
            'required_fields' => ['copy', 'destination_url'],
            'created_by' => $this->userId,
        ]);
        $emailRunId = $this->marketing->createEmailCampaignRun([
            'name' => 'Release QA Email Run',
            'content_item_id' => $contentId,
            'campaign_id' => $campaignId,
            'audience_segment_id' => $segmentId,
            'subject' => 'Release QA readiness',
            'recipient_count' => 5,
            'created_by' => $this->userId,
        ]);
        $journeyId = $this->marketing->createJourney([
            'name' => 'Release QA Journey',
            'journey_goal' => 'Move release QA audience through manual review and export.',
            'audience_segment_id' => $segmentId,
            'campaign_id' => $campaignId,
            'owner_user_id' => $this->userId,
            'steps' => [
                ['step_type' => 'email', 'title' => 'Send release readiness email', 'content_item_id' => $contentId, 'email_run_id' => $emailRunId],
                ['step_type' => 'wait', 'title' => 'Wait for review signals', 'wait_days' => 2],
                ['step_type' => 'task', 'title' => 'Manual follow-up task'],
            ],
            'created_by' => $this->userId,
        ]);
        $this->marketing->createUtmLink([
            'url' => 'https://example.com/release-qa',
            'campaign_id' => $campaignId,
            'content_item_id' => $contentId,
            'utm_source' => 'qa',
            'utm_medium' => 'manual',
            'created_by' => $this->userId,
        ]);
        $rhythmId = $this->marketing->createRecurringRhythm([
            'name' => 'Release QA Weekly Planning',
            'rhythm_type' => 'planning',
            'cadence' => 'weekly',
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->createPlanningQueueItem([
            'title' => 'Release QA queue item',
            'rhythm_id' => $rhythmId,
            'content_item_id' => $contentId,
            'campaign_brief_id' => $briefId,
            'campaign_id' => $campaignId,
            'week_start' => date('Y-m-d', strtotime('monday this week')),
            'owner_user_id' => $this->userId,
            'created_by' => $this->userId,
        ]);
        $this->marketing->createChecklistTemplate([
            'name' => 'Release QA Checklist',
            'template_type' => 'content',
            'checklist' => "Review\nApprove\nExport",
            'created_by' => $this->userId,
        ]);
        $creativeBriefId = $this->marketing->createCreativeBrief([
            'title' => 'Release QA Creative Brief',
            'content_item_id' => $contentId,
            'asset_type' => 'image',
            'created_by' => $this->userId,
        ]);
        $assetRequestId = $this->marketing->createAssetRequest([
            'title' => 'Release QA Asset Request',
            'creative_brief_id' => $creativeBriefId,
            'content_item_id' => $contentId,
            'created_by' => $this->userId,
        ]);

        return [
            'campaign_id' => $campaignId,
            'form_id' => $formId,
            'segment_id' => $segmentId,
            'brand_id' => $brandId,
            'persona_id' => $personaId,
            'offer_id' => $offerId,
            'pillar_id' => $pillarId,
            'seo_topic_id' => $seoTopicId,
            'landing_page_id' => $landingPageId,
            'preview_token' => (string) ($landingPage['preview_token'] ?? ''),
            'public_token' => (string) ($publication['public_token'] ?? ''),
            'brief_id' => $briefId,
            'content_id' => $contentId,
            'journey_id' => $journeyId,
            'draft_body' => $draftBody,
            'asset_id' => $assetId,
            'distribution_post_id' => $distributionPostId,
            'email_run_id' => $emailRunId,
            'creative_brief_id' => $creativeBriefId,
            'asset_request_id' => $assetRequestId,
        ];
    }

    /**
     * @param array<string,mixed> $fixture
     * @return array<string,array<string,mixed>>
     */
    private function marketingReleaseQaRoutes(array $fixture): array
    {
        $routes = array_fill_keys(MarketingUi::internalPageFilenames(), []);
        $routes['marketing_action_router.php'] = ['campaign_id' => $fixture['campaign_id']];
        $routes['marketing_campaign_workspace.php'] = ['campaign_id' => $fixture['campaign_id']];
        $routes['marketing_execution.php'] = ['campaign_id' => $fixture['campaign_id']];
        $routes['marketing_decisions.php'] = ['campaign_id' => $fixture['campaign_id']];
        $routes['marketing_content_edit.php'] = ['id' => $fixture['content_id']];
        $routes['marketing_content_view.php'] = ['id' => $fixture['content_id']];
        $routes['marketing_brief_edit.php'] = ['id' => $fixture['brief_id']];
        $routes['marketing_brief_view.php'] = ['id' => $fixture['brief_id']];
        $routes['marketing_segment_edit.php'] = ['id' => $fixture['segment_id']];
        $routes['marketing_segment_view.php'] = ['id' => $fixture['segment_id']];
        $routes['marketing_audience_activation.php'] = ['campaign_id' => $fixture['campaign_id'], 'audience_segment_id' => $fixture['segment_id']];
        $routes['marketing_journey_edit.php'] = ['id' => $fixture['journey_id']];
        $routes['marketing_journey_view.php'] = ['id' => $fixture['journey_id']];
        $routes['marketing_playbook_edit.php'] = ['id' => $fixture['playbook_id']];
        $routes['marketing_playbook_view.php'] = ['id' => $fixture['playbook_id']];
        $routes['marketing_landing_page_edit.php'] = ['id' => $fixture['landing_page_id']];
        $routes['marketing_landing_page_view.php'] = ['id' => $fixture['landing_page_id']];
        $routes['marketing_landing_page_preview.php'] = ['token' => $fixture['preview_token']];
        $routes['marketing_launch_readiness.php'] = ['campaign_id' => $fixture['campaign_id']];
        $routes['marketing_launch_control.php'] = ['campaign_id' => $fixture['campaign_id']];
        $routes['marketing_launch_checklists.php'] = ['campaign_id' => $fixture['campaign_id']];
        $routes['marketing_distribution.php'] = ['status' => ''];
        $routes['marketing_distribution_bundle.php'] = ['id' => $fixture['distribution_post_id']];
        $routes['marketing_operator_export_packs.php'] = ['id' => $fixture['operator_pack_id']];
        $routes['marketing_channel_exports.php'] = ['status' => ''];
        $routes['marketing_utm_links.php'] = ['campaign_id' => $fixture['campaign_id']];
        $routes['marketing_email_runs.php'] = ['campaign_id' => $fixture['campaign_id']];
        $routes['marketing_handoffs.php'] = ['campaign_id' => $fixture['campaign_id']];
        $routes['marketing_performance.php'] = ['campaign_id' => $fixture['campaign_id']];
        $routes['marketing_quality.php'] = ['content_item_id' => $fixture['content_id']];
        $routes['marketing_creative.php'] = ['content_item_id' => $fixture['content_id']];

        ksort($routes);

        return $routes;
    }

    private function assertEndpointHealthy(array $response, int $expectedStatus, string $label): void
    {
        $body = (string) ($response['body'] ?? '');
        $stderr = (string) ($response['stderr'] ?? '');
        $this->assertSame($expectedStatus, (int) ($response['status'] ?? 0), $label . ' stderr: ' . $stderr);
        $this->assertStringNotContainsString('Fatal error', $body, $label);
        $this->assertStringNotContainsString('Uncaught', $body, $label);
        $this->assertStringNotContainsString('Stack trace', $body, $label);
        $this->assertStringNotContainsString('Fatal error', $stderr, $label);
        $this->assertStringNotContainsString('Uncaught', $stderr, $label);
    }

    /**
     * @return array<string,mixed>
     */
    private function webSession(string $role = 'owner'): array
    {
        return [
            'user_id' => $this->userId,
            'user_uuid' => 'marketing-user',
            'user_email' => 'marketing@example.com',
            'user_role' => 'marketing',
            'active_workspace_id' => $this->workspaceId,
            'active_workspace_uuid' => $this->workspaceUuid,
            'active_workspace_slug' => 'marketing-tenant',
            'active_workspace_name' => 'Marketing Tenant Workspace',
            'active_workspace_role' => $role,
            'active_workspace_membership_id' => $this->membershipId,
            'csrf_token' => 'csrf-marketing',
            '__remember_restore_attempted' => true,
        ];
    }
}
