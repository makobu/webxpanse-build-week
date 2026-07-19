<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AICoachRecommendationControlService;
use CRM\Services\AIAutomationDiagnosticsService;
use CRM\Services\WorkspaceMarketplaceActivationBundleEventService;
use CRM\Services\WorkspaceMarketplaceActivationBundleService;
use CRM\Services\WorkspaceMarketplaceRecommendationControlService;
use CRM\Services\WorkspaceMarketplaceRecommendationEventService;
use CRM\Services\WorkspaceMarketplaceSetupJourneyEventService;
use CRM\Tests\DatabaseTestCase;

class AIAutomationDiagnosticsServiceTest extends DatabaseTestCase
{
    private int $workspaceId = 1;
    private int $userId;
    private int $contactId;
    private int $dealId;
    private int $invoiceId;
    private int $taskId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['diag@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at) VALUES (1, ?, ?, ?, ?, NOW())",
            [uniqid('contact_', true), 'Diag', 'Contact', 'diag-contact@example.com']
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, currency, created_at)
             VALUES (1, ?, ?, ?, ?, 'proposal', 500, 'USD', NOW())",
            ['Diagnostics Deal', $this->contactId, $this->userId, $this->userId]
        );
        $this->dealId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO invoices
                (workspace_id, document_type, status, invoice_number, revision_number, deal_id, contact_id, assigned_to, created_by, currency, issue_date, subtotal, discount_total, tax_total, grand_total, balance_due, tax_mode, tax_rate, billing_email, created_at, updated_at)
             VALUES (1, 'quote', 'draft', 'Q-DIAG-1', 1, ?, ?, ?, ?, 'USD', CURDATE(), 500, 0, 0, 500, 500, 'none', 0, 'buyer@example.com', NOW(), NOW())",
            [$this->dealId, $this->contactId, $this->userId, $this->userId]
        );
        $this->invoiceId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO tasks (workspace_id, title, status, contact_id, created_by, assigned_to, created_at, metadata_json)
             VALUES (1, ?, 'completed', ?, ?, ?, NOW(), ?)",
            ['Diagnostics Task', $this->contactId, $this->userId, $this->userId, json_encode(['source_surface' => 'ai_coach'])]
        );
        $this->taskId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO email_assistant_runs
                (workspace_id, mode, source, user_id, contact_id, deal_id, invoice_id, thread_type, intent, resolution_status, execution_status, confidence_score, context_snapshot_json, plan_json, result_json, created_at)
             VALUES (1, 'customer_thread', 'conversation_ui', ?, ?, ?, ?, 'customer_email', 'draft_customer_reply', 'blocked', 'planned', 0.71, ?, ?, ?, NOW())",
            [
                $this->userId,
                $this->contactId,
                $this->dealId,
                $this->invoiceId,
                json_encode(['surface' => 'conversation']),
                json_encode(['policy_decision' => 'blocked', 'qualification_snapshot' => ['decision' => 'blocked', 'mode' => 'operations']]),
                json_encode([
                    'summary_text' => 'Assistant blocked a customer reply due to insufficient thread context.',
                    'policy' => [
                        'decision' => 'blocked',
                        'reasons' => ['missing_context'],
                        'confidence_score' => 0.71,
                        'context_quality_score' => 0.42,
                        'goal_relevance_score' => 0.60,
                        'mode' => 'operations',
                    ],
                ]),
            ]
        );

        Database::execute(
            "INSERT INTO ai_guidance_runs
                (workspace_id, user_id, surface, mode, decision, confidence_score, context_quality_score, goal_relevance_score, policy_snapshot_json, input_snapshot_json, output_snapshot_json, created_at)
             VALUES (?, ?, 'coach', 'foundation', 'allow_with_warning', 0.88, 0.70, 0.65, ?, '{}', ?, NOW())",
            [
                $this->workspaceId,
                $this->userId,
                json_encode(['reasons' => ['feature_unready'], 'role_profile' => ['role_profile' => 'founder', 'summary' => 'Founder profile']]),
                json_encode([
                    'summary' => 'Coach downgraded invoicing guidance because setup is incomplete.',
                    'deal_id' => $this->dealId,
                    'role_profile' => ['role_profile' => 'founder', 'summary' => 'Founder profile'],
                    'role_summary' => 'Founder profile',
                    'user_work_context_summary' => ['role' => 'admin', 'open_tasks' => 1],
                    'role_threshold_recommendations' => ['threshold_key' => 'ai_advice_min_confidence'],
                ]),
            ]
        );

        Database::execute(
            "INSERT INTO commercial_automation_runs
                (workspace_id, deal_id, contact_id, invoice_id, trigger_type, trigger_ref_id, decision, action_plan_json, evidence_json, policy_snapshot_json, created_at)
             VALUES (1, ?, ?, ?, 'stage_change', 1, 'approval_required', ?, '{}', ?, NOW())",
            [
                $this->dealId,
                $this->contactId,
                $this->invoiceId,
                json_encode(['reasons' => ['missing_recipient']]),
                json_encode(['reasons' => ['missing_recipient'], 'mode' => 'auto_safe']),
            ]
        );

        Database::execute(
            "INSERT INTO commercial_automation_approvals
                (workspace_id, deal_id, invoice_id, action_key, status, reason, requested_by_type, requested_by_id, payload_json, created_at)
             VALUES (1, ?, ?, 'send_document', 'pending', ?, 'ai', ?, ?, NOW())",
            [
                $this->dealId,
                $this->invoiceId,
                'Recipient is required before sending.',
                $this->userId,
                json_encode([
                    'action' => ['action' => 'send_document'],
                    'context' => [
                        'surface' => 'assistant',
                        'assistant_confidence' => 0.83,
                        'document_type' => 'quote',
                        'channel' => 'email',
                        'recipient' => '',
                        'deal' => ['id' => $this->dealId, 'stage' => 'proposal'],
                    ],
                ]),
            ]
        );

        Database::execute(
            "INSERT INTO workflows (workspace_id, name, trigger_config, actions, created_at, updated_at) VALUES (1, ?, ?, ?, NOW(), NOW())",
            ['Diagnostics Workflow', json_encode(['type' => 'contact_created']), json_encode([['type' => 'send_email']])]
        );
        $workflowId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workflow_executions (workflow_id, contact_id, status, executed_at) VALUES (?, ?, 'failed', NOW())",
            [$workflowId, $this->contactId]
        );
        $workflowExecutionId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workflow_node_runs
                (workflow_execution_id, workflow_id, node_id, node_type, node_label, status, attempt_count, started_at, created_at, error_message)
             VALUES (?, ?, 'action_1', 'action', 'Send Email', 'failed', 1, NOW(), NOW(), 'SMTP timeout')",
            [$workflowExecutionId, $workflowId]
        );

        Database::execute(
            "INSERT INTO workflow_retry_queue
                (workflow_execution_id, workflow_id, node_id, action_index, retry_after, retry_count, last_error, payload_json, status, created_at)
             VALUES (?, ?, 'action_1', 0, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 1, 'SMTP timeout', '{}', 'pending', NOW())",
            [$workflowExecutionId, $workflowId]
        );

        Database::execute(
            "INSERT INTO ai_capability_state_log (workspace_id, user_id, surface, capability_key, status, reason, metadata_json, created_at)
             VALUES (?, ?, 'coach', 'idea_validation_context', 'degraded', 'Missing optional table', '{}', NOW())",
            [$this->workspaceId, $this->userId]
        );
        Database::execute(
            "INSERT INTO ai_capability_state_log (workspace_id, user_id, surface, capability_key, status, reason, metadata_json, created_at)
             VALUES (?, ?, 'coach', 'idea_validation_context', 'degraded', 'Missing optional table', '{}', DATE_ADD(NOW(), INTERVAL 5 SECOND))",
            [$this->workspaceId, $this->userId]
        );

        Database::execute(
            "INSERT INTO ai_task_evidence (workspace_id, task_id, evidence_type, entity_type, entity_id, confidence_score, evidence_json, created_at)
             VALUES (?, ?, 'email_reply_received', 'communication', 1, 0.98, ?, NOW())",
            [$this->workspaceId, $this->taskId, json_encode(['deal_id' => $this->dealId, 'contact_id' => $this->contactId])]
        );

        Database::execute(
            "INSERT INTO ai_runtime_control_log
                (workspace_id, surface, previous_mode, new_mode, reason, set_by, set_at, metadata_json)
             VALUES (?, 'assistant', 'normal', 'suggest_only', 'Operator safe mode', ?, NOW(), '{}')",
            [$this->workspaceId, $this->userId]
        );

        Database::execute(
            "INSERT INTO automation_job_health
                (job_key, status, last_run_at, last_success_at, last_duration_ms, last_message, metadata_json)
             VALUES ('ai_outcome_reconciliation', 'ok', NOW(), NOW(), 1200, 'Reconciled outcomes.', '{}')"
        );

        Database::execute(
            "INSERT INTO ai_advice_feedback
                (workspace_id, guidance_run_id, user_id, surface, feedback_type, recommendation_key, linked_task_id, linked_contact_id, linked_deal_id, metadata_json, created_at)
             VALUES (?, 1, ?, 'coach', 'useful', 'coach_1_diag', ?, ?, ?, '{}', NOW())",
            [$this->workspaceId, $this->userId, $this->taskId, $this->contactId, $this->dealId]
        );

        Database::execute(
            "INSERT INTO ai_decision_outcomes
                (workspace_id, assistant_run_id, task_id, user_id, surface, decision_type, action_type, predicted_confidence, context_quality_score, goal_relevance_score, policy_decision, threshold_snapshot_json, outcome_label, outcome_score, outcome_metadata_json, measured_at, created_at)
             VALUES (?, 55, ?, ?, 'assistant', 'task', 'draft_customer_reply', 0.93, 0.82, 0.78, 'allow', '{}', 'accepted', 1.0, ?, NOW(), NOW())",
            [
                $this->workspaceId,
                $this->taskId,
                $this->userId,
                json_encode([
                    'manual_followthrough' => true,
                    'linked_deal_id' => $this->dealId,
                    'linked_contact_id' => $this->contactId,
                    'linked_invoice_id' => $this->invoiceId,
                ]),
            ]
        );

        Database::execute(
            "INSERT INTO ai_cross_domain_runs
                (tenant_key, objective_key, primary_entity_type, primary_entity_id, related_entities_json, execution_mode, run_status, reason_note, plan_json, summary_json, created_by, created_at)
             VALUES ('contact:" . $this->contactId . "', 'progress_deal_to_next_stage', 'deal', ?, ?, 'sequential', 'blocked', 'Blocked in diagnostics test', ?, ?, ?, NOW())",
            [
                $this->dealId,
                json_encode(['contact_id' => $this->contactId, 'deal_id' => $this->dealId, 'invoice_id' => $this->invoiceId, 'task_id' => $this->taskId]),
                json_encode(['objective_key' => 'progress_deal_to_next_stage']),
                json_encode(['reasons' => ['domain_not_ready']]),
                $this->userId,
            ]
        );
        $crossRunId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO ai_cross_domain_steps
                (run_id, step_order, domain_key, action_key, target_entity_type, target_entity_id, customer_facing, assistant_confidence, precheck_status, step_status, plan_context_json, result_json, created_at)
             VALUES (?, 1, 'deal_followthrough', 'progress_stage', 'deal', ?, 0, 0.84, 'ready', 'completed', '{}', '{}', NOW())",
            [$crossRunId, $this->dealId]
        );

        Database::execute(
            "INSERT INTO ai_cross_domain_intake_events
                (tenant_key, source_domain, trigger_key, trigger_entity_type, trigger_entity_id, objective_key, intake_decision, linked_run_id, reason_text, metadata_json, created_at)
             VALUES (?, 'customer_thread', 'reply_needed', 'contact', ?, 'recover_stalled_customer_thread', 'suppressed', ?, 'customer_facing_contained', ?, NOW())",
            [
                'contact:' . $this->contactId,
                $this->contactId,
                $crossRunId,
                json_encode(['event' => ['source_domain' => 'customer_thread'], 'suppression_reason' => 'customer_facing_contained']),
            ]
        );

        (new WorkspaceMarketplaceRecommendationEventService())->recordEvent(
            $this->workspaceId,
            $this->userId,
            'whatsapp_assistant',
            'coach',
            'task_created',
            [
                'recommendation_score' => 88,
                'priority' => 'high',
                'reason_codes' => ['selected_channel_whatsapp'],
                'metadata' => ['label' => 'WhatsApp Assistant', 'task_id' => $this->taskId],
            ]
        );
    }

    public function testSummaryAndTimelineAggregateAcrossSources(): void
    {
        $service = new AIAutomationDiagnosticsService();

        $summary = $service->getSummary(['date_from' => date('Y-m-d', strtotime('-1 day')), 'date_to' => date('Y-m-d')]);
        $this->assertSame(1, $summary['blocked_ai_actions']);
        $this->assertGreaterThanOrEqual(1, $summary['approval_required']);
        $this->assertSame(1, $summary['workflow_failures']);
        $this->assertSame(1, $summary['workflow_retries_pending']);
        $this->assertSame(1, $summary['degraded_capabilities']);
        $this->assertSame(1, $summary['tasks_auto_completed']);

        $events = $service->getRecentEvents(['deal_id' => $this->dealId, 'date_from' => date('Y-m-d', strtotime('-1 day')), 'date_to' => date('Y-m-d')]);
        $this->assertNotEmpty($events);
        $this->assertArrayHasKey('source', $events[0]);
        $this->assertArrayHasKey('reason_bucket', $events[0]);

        $capabilityEvents = array_values(array_filter(
            $service->getRecentEvents(['source' => 'capability', 'date_from' => date('Y-m-d', strtotime('-1 day')), 'date_to' => date('Y-m-d')]),
            static fn(array $event): bool => ($event['source'] ?? '') === 'capability'
        ));
        $this->assertCount(1, $capabilityEvents);
        $this->assertSame('feature_unready', $capabilityEvents[0]['reason_bucket']);

        $contextHealth = $service->getContextHealth(['date_from' => date('Y-m-d', strtotime('-1 day')), 'date_to' => date('Y-m-d')]);
        $this->assertSame(1, $contextHealth['degraded']);
    }

    public function testMarketplaceRecommendationAnalyticsAppearInSummaryAndTimeline(): void
    {
        $service = new AIAutomationDiagnosticsService();

        $summary = $service->getMarketplaceRecommendationSummary([
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertSame(1, (int) $summary['counts']['task_created']);
        $this->assertSame('whatsapp_assistant', (string) ($summary['top_skills'][0]['skill_key'] ?? ''));

        $events = $service->getRecentEvents([
            'source' => 'marketplace_recommendation',
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertNotEmpty($events);
        $this->assertSame('marketplace_recommendation', (string) ($events[0]['source'] ?? ''));
        $this->assertSame('marketplace_recommendation_task_created', (string) ($events[0]['event_type'] ?? ''));
        $this->assertSame($this->taskId, (int) ($events[0]['linked_records']['task_id'] ?? 0));
        $this->assertStringNotContainsString('secret', strtolower(json_encode($events[0]['payload'] ?? []) ?: ''));

        $insights = $service->getMarketplaceRecommendationInsights([
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertNotEmpty($insights);
        $this->assertSame('whatsapp_assistant', (string) ($insights[0]['skill_key'] ?? ''));
        $this->assertArrayHasKey('metric_snapshot', $insights[0]);
        $this->assertStringNotContainsString('secret', strtolower(json_encode($insights) ?: ''));

        $this->assertSame([], $service->getMarketplaceRecommendationInsights(['source' => 'coach']));

        $adaptive = $service->getMarketplaceAdaptiveSignalSummary([
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertGreaterThanOrEqual(1, (int) ($adaptive['positive_boosts'] ?? 0));
        $this->assertSame('whatsapp_assistant', (string) ($adaptive['top_skills'][0]['skill_key'] ?? ''));
        $this->assertStringNotContainsString('secret', strtolower(json_encode($adaptive) ?: ''));

        (new WorkspaceMarketplaceRecommendationControlService())->setControl($this->workspaceId, $this->userId, 'whatsapp_assistant', 'pinned', 'all', true, 'test', [
            'source' => 'diagnostics_test',
            'secret_token' => 'do-not-store',
        ]);
        $controls = $service->getMarketplaceRecommendationControlSummary([
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertSame(1, (int) ($controls['counts']['pinned'] ?? 0));
        $this->assertSame('whatsapp_assistant', (string) ($controls['active_controls'][0]['skill_key'] ?? ''));
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($controls) ?: ''));

        (new WorkspaceMarketplaceActivationBundleService())->updateState($this->workspaceId, $this->userId, 'whatsapp_led_growth', 'selected', [
            'source' => 'diagnostics_test',
            'secret_token' => 'do-not-store',
        ]);
        $bundles = $service->getMarketplaceActivationBundleSummary([
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertSame(1, (int) ($bundles['counts']['selected'] ?? 0));
        $this->assertArrayHasKey('active_bundles', $bundles);
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($bundles) ?: ''));
        $this->assertSame(['counts' => ['selected' => 0, 'dismissed' => 0, 'completed' => 0], 'active_bundles' => [], 'total_state_rows' => 0], $service->getMarketplaceActivationBundleSummary(['source' => 'coach']));

        $bundleEvents = new WorkspaceMarketplaceActivationBundleEventService();
        $bundleEvents->recordEvent($this->workspaceId, $this->userId, 'whatsapp_led_growth', 'bundle_impression', [
            'bundle_status' => 'selected',
            'priority' => 'high',
            'included_skill_keys' => ['lean_canvas', 'whatsapp_assistant'],
            'recommended_skill_keys' => ['whatsapp_assistant'],
            'progress' => ['total' => 2, 'installed' => 0, 'recommended' => 1],
            'metadata' => ['label' => 'WhatsApp-led growth', 'secret_token' => 'do-not-store'],
        ]);
        $bundleEvents->recordEvent($this->workspaceId, $this->userId, 'whatsapp_led_growth', 'cta_clicked', [
            'metadata' => ['target_url' => 'workspace_skills.php?activation_bundle_key=whatsapp_led_growth'],
        ]);
        $bundleEventSummary = $service->getMarketplaceActivationBundleEventSummary([
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertSame(1, (int) ($bundleEventSummary['counts']['bundle_impression'] ?? 0));
        $this->assertSame(1, (int) ($bundleEventSummary['counts']['cta_clicked'] ?? 0));
        $this->assertSame('whatsapp_led_growth', (string) ($bundleEventSummary['top_bundles'][0]['bundle_key'] ?? ''));
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($bundleEventSummary) ?: ''));

        $bundleInsights = $service->getMarketplaceActivationBundleInsights([
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertNotEmpty($bundleInsights);
        $this->assertSame('whatsapp_led_growth', (string) ($bundleInsights[0]['bundle_key'] ?? ''));
        $this->assertArrayHasKey('metric_snapshot', $bundleInsights[0]);
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($bundleInsights) ?: ''));
        $this->assertSame([], $service->getMarketplaceActivationBundleInsights(['source' => 'coach']));

        $bundleAdaptive = $service->getMarketplaceActivationBundleAdaptiveSignalSummary([
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertGreaterThanOrEqual(1, (int) ($bundleAdaptive['positive_boosts'] ?? 0));
        $this->assertSame('whatsapp_led_growth', (string) ($bundleAdaptive['top_bundles'][0]['bundle_key'] ?? ''));
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($bundleAdaptive) ?: ''));
        $this->assertSame([
            'positive_boosts' => 0,
            'negative_dampening' => 0,
            'top_bundles' => [],
            'reason_counts' => [],
            'total_signals' => 0,
        ], $service->getMarketplaceActivationBundleAdaptiveSignalSummary(['source' => 'coach']));

        $bundleTimeline = $service->getRecentEvents([
            'source' => 'marketplace_activation_bundle',
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertNotEmpty($bundleTimeline);
        $this->assertSame('marketplace_activation_bundle', (string) ($bundleTimeline[0]['source'] ?? ''));
        $this->assertStringStartsWith('marketplace_activation_bundle_', (string) ($bundleTimeline[0]['event_type'] ?? ''));
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($bundleTimeline) ?: ''));
    }

    public function testCoachFeedbackInsightsAggregateMetadataAndActiveControls(): void
    {
        Database::execute(
            "INSERT INTO ai_advice_feedback
                (workspace_id, user_id, surface, feedback_type, recommendation_key, metadata_json, created_at)
             VALUES
                (?, ?, 'coach', 'dismissed', 'coach_sig_review_key', ?, NOW()),
                (?, ?, 'coach', 'acted_on', 'coach_sig_review_key', ?, NOW())",
            [
                $this->workspaceId,
                $this->userId,
                json_encode([
                    'feedback_signature' => 'coach_sig_review',
                    'source_recommendation_type' => 'crm_activity',
                    'source_section' => 'priorities',
                    'why_signals' => ['CRM activity', 'Target linked'],
                    'trust_signals' => [['label' => 'Context', 'value' => 'Ready']],
                ]),
                $this->workspaceId,
                $this->userId,
                json_encode([
                    'feedback_signature' => 'coach_sig_review',
                    'source_recommendation_type' => 'crm_activity',
                    'source_section' => 'priorities',
                    'why_signals' => ['CRM activity'],
                    'trust_signals' => [['label' => 'Context', 'value' => 'Ready']],
                ]),
            ]
        );

        $service = new AIAutomationDiagnosticsService();
        $insights = $service->getCoachFeedbackInsights([
            'source' => 'coach',
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $this->assertGreaterThanOrEqual(1, (int) ($insights['counts']['acted_on'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($insights['counts']['dismissed'] ?? 0));
        $positiveSignatures = array_column((array) ($insights['top_positive_signatures'] ?? []), 'feedback_signature');
        $this->assertContains('coach_sig_review', $positiveSignatures);
        $this->assertSame('crm_activity', (string) ($insights['source_type_breakdown'][0]['label'] ?? ''));
        $this->assertSame('priorities', (string) ($insights['source_section_breakdown'][0]['label'] ?? ''));
        $this->assertSame('CRM activity', (string) ($insights['why_signal_breakdown'][0]['label'] ?? ''));
        $this->assertSame('Context: Ready', (string) ($insights['trust_signal_breakdown'][0]['label'] ?? ''));
        $this->assertNotEmpty($insights['recent_adjusted_patterns']);

        (new AICoachRecommendationControlService())->setControl(
            'feedback_signature',
            'coach_sig_review',
            'boosted',
            $this->userId,
            'diagnostics test'
        );
        $controls = $service->getCoachRecommendationControlSummary(['source' => 'coach']);
        $this->assertSame(1, (int) ($controls['counts']['boosted'] ?? 0));
        $this->assertSame('coach_sig_review', (string) ($controls['active_controls'][0]['control_value'] ?? ''));
    }

    public function testMarketplaceSetupJourneyAnalyticsAppearInSummaryAndTimeline(): void
    {
        $events = new WorkspaceMarketplaceSetupJourneyEventService();
        $stepLabel = 'Connect WhatsApp and configure the assistant phone number settings.';
        $stepKey = 'setup_' . substr(hash('sha1', $stepLabel), 0, 16);

        $events->recordEvent($this->workspaceId, $this->userId, 'whatsapp_assistant', 'journey_impression', [
            'label' => 'WhatsApp Assistant',
            'metadata' => ['label' => 'WhatsApp Assistant', 'secret_token' => 'do-not-store'],
        ]);
        $events->recordEvent($this->workspaceId, $this->userId, 'whatsapp_assistant', 'setup_opened', [
            'label' => 'WhatsApp Assistant',
            'metadata' => ['target_url' => 'settings.php?tab=whatsapp_assistant'],
        ]);
        $events->recordEvent($this->workspaceId, $this->userId, 'whatsapp_assistant', 'step_skipped', [
            'step_key' => $stepKey,
            'label' => $stepLabel,
            'step_status' => 'skipped',
        ]);

        $service = new AIAutomationDiagnosticsService();
        $summary = $service->getMarketplaceSetupJourneySummary([
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);

        $this->assertSame(1, (int) $summary['counts']['journey_impression']);
        $this->assertSame(1, (int) $summary['counts']['setup_opened']);
        $this->assertSame(1.0, (float) $summary['setup_open_rate']);
        $this->assertSame('whatsapp_assistant', (string) ($summary['top_skills'][0]['skill_key'] ?? ''));
        $this->assertSame($stepKey, (string) ($summary['top_steps'][0]['step_key'] ?? ''));

        $timeline = $service->getRecentEvents([
            'source' => 'marketplace_setup_journey',
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d'),
        ]);
        $this->assertNotEmpty($timeline);
        $this->assertSame('marketplace_setup_journey', (string) ($timeline[0]['source'] ?? ''));
        $this->assertStringStartsWith('marketplace_setup_journey_', (string) ($timeline[0]['event_type'] ?? ''));
        $this->assertStringNotContainsString('do-not-store', strtolower(json_encode($timeline) ?: ''));

        $this->assertSame([], $service->getMarketplaceSetupJourneyEvents(['source' => 'coach']));
    }

    public function testRecordTimelineAndMalformedPayloadsDegradeGracefully(): void
    {
        Database::execute(
            "INSERT INTO email_assistant_runs
                (workspace_id, mode, source, user_id, contact_id, deal_id, invoice_id, thread_type, intent, resolution_status, execution_status, confidence_score, plan_json, result_json, created_at)
             VALUES (1, 'admin_command', 'manual', ?, ?, ?, ?, 'email_assistant', 'send_customer_reply', 'blocked', 'failed', 0.10, '\"bad\"', '\"bad\"', NOW())",
            [$this->userId, $this->contactId, $this->dealId, $this->invoiceId]
        );

        $service = new AIAutomationDiagnosticsService();
        $timeline = $service->getRecordTimeline(['deal_id' => $this->dealId]);

        $sources = array_column($timeline, 'source');
        $this->assertContains('assistant', $sources);
        $this->assertContains('commercial', $sources);
        $this->assertContains('task_automation', $sources);
        $this->assertContains('coach', $sources);
        $this->assertContains('assistant', $sources);

        $linked = $service->getLinkedData(['deal_id' => $this->dealId]);
        $this->assertNotEmpty($linked['assistant_runs']);
        $this->assertNotEmpty($linked['approvals']);

        $allEvents = $service->getRecentEvents(['date_from' => date('Y-m-d', strtotime('-1 day')), 'date_to' => date('Y-m-d')]);
        $this->assertContains('control', array_column($allEvents, 'source'));
        $this->assertContains('job', array_column($allEvents, 'source'));
        $this->assertContains('orchestrator', array_column($allEvents, 'source'));
        $this->assertSame(1, $service->getJobHealthSummary()['healthy']);

        $intakeEvent = null;
        foreach ($allEvents as $event) {
            if (($event['source'] ?? '') === 'orchestrator' && ($event['event_type'] ?? '') === 'cross_domain_intake_suppressed') {
                $intakeEvent = $event;
                break;
            }
        }
        $this->assertNotNull($intakeEvent);
        $this->assertSame('suppressed', $intakeEvent['payload']['intake']['intake_decision'] ?? null);
        $this->assertSame('customer_facing_contained', $intakeEvent['payload']['intake']['reason_text'] ?? null);

        $coachEvent = null;
        foreach ($allEvents as $event) {
            if (($event['source'] ?? '') === 'coach') {
                $coachEvent = $event;
                break;
            }
        }
        $this->assertNotNull($coachEvent);
        $this->assertSame('founder', $coachEvent['payload']['role_profile']['role_profile'] ?? null);
        $this->assertSame('ai_advice_min_confidence', $coachEvent['payload']['role_threshold_recommendations']['threshold_key'] ?? null);

        $linked = $service->getLinkedData(['deal_id' => $this->dealId]);
        $this->assertNotEmpty($linked['orchestration_runs']);
    }
}
