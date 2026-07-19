<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\DealAutomationConfig;
use CRM\Services\AIAutonomyDomainControlService;
use CRM\Services\AIAutonomyIncidentService;
use CRM\Services\AutomationJobHealthService;
use CRM\Services\BusinessTakeoverConfidenceAuditService;
use CRM\Tests\DatabaseTestCase;

class BusinessTakeoverConfidenceAuditIntegrationTest extends DatabaseTestCase
{
    public function testAuditPassesWhenHealthyEvidenceExistsAcrossDomains(): void
    {
        $userId = $this->createAdminUser('takeover-pass@example.test');
        $tenantKey = 'contact:' . $userId;

        $this->makeSetupReady($userId);
        [$contactIds, $dealIds] = $this->seedContactsAndDeals($userId, 5);
        $this->seedHealthyConfigurations();
        $this->seedHealthyJobs();
        $this->seedAutoResponderLogs($contactIds[0], 5);
        $this->seedCommercialRuns($dealIds[0], $contactIds[0], 5);
        $this->seedWorkflowProposals($userId, 5);
        $this->seedDealAutomationAudit($dealIds[0], $contactIds[0], 5);
        $this->seedDecisionOutcomes($userId, 20);
        $this->seedHealthyRollouts($tenantKey, $userId);
        $this->seedHealthyDemonstrations($tenantKey, $userId, $contactIds, $dealIds);

        $service = $this->makeAuditableService([
            'generated_at' => '2026-04-08T12:00:00+03:00',
            'database_probes' => [
                'configured' => ['success' => true, 'host' => 'localhost', 'message' => 'Connection succeeded.'],
                'localhost' => ['success' => false, 'host' => 'localhost', 'message' => 'Transient timeout.'],
                'loopback' => ['success' => true, 'host' => '127.0.0.1', 'message' => 'Connection succeeded.'],
            ],
            'provider_probe' => [
                'core_ai_provider_ready' => true,
                'message' => 'Provider ready.',
            ],
            'transient_environment_risk' => true,
        ]);

        $audit = $service->audit([
            'scope' => 'all',
            'user_id' => $userId,
        ]);

        $this->assertSame('pass', $audit['overall_status']);
        $this->assertSame('none', $audit['failure_class']);
        $this->assertTrue($audit['takeover_ready']);
        $this->assertGreaterThanOrEqual(80, (int) ($audit['platform_score'] ?? 0));
        $this->assertTrue((bool) ($audit['verification_snapshot']['transient_environment_risk'] ?? false));
        $this->assertSame(0, BusinessTakeoverConfidenceAuditService::exitCodeFor($audit));

        foreach ((array) ($audit['hard_gates'] ?? []) as $gate) {
            $this->assertSame('pass', (string) ($gate['status'] ?? 'block'));
        }
    }

    public function testAuditBlocksWhenCriticalGovernanceIncidentBacklogExists(): void
    {
        $userId = $this->createAdminUser('takeover-block@example.test');
        $tenantKey = 'contact:' . $userId;

        $this->makeSetupReady($userId);
        [$contactIds, $dealIds] = $this->seedContactsAndDeals($userId, 5);
        $this->seedHealthyConfigurations();
        $this->seedHealthyJobs();
        $this->seedAutoResponderLogs($contactIds[0], 5);
        $this->seedCommercialRuns($dealIds[0], $contactIds[0], 5);
        $this->seedWorkflowProposals($userId, 5);
        $this->seedDealAutomationAudit($dealIds[0], $contactIds[0], 5);
        $this->seedDecisionOutcomes($userId, 20);
        $this->seedHealthyRollouts($tenantKey, $userId);
        $this->seedHealthyDemonstrations($tenantKey, $userId, $contactIds, $dealIds);

        $incidents = new AIAutonomyIncidentService();
        $incidentId = $incidents->recordIncident([
            'tenant_key' => $tenantKey,
            'domain_key' => 'workflow_execution',
            'incident_key' => 'critical_recovery_needed',
            'severity' => 'critical',
            'status' => 'open',
            'reason_codes' => ['operational_backlog'],
            'details' => ['source' => 'integration_test'],
        ]);
        $this->assertNotNull($incidentId);
        $incidents->queueRecovery([
            'incident_id' => $incidentId,
            'tenant_key' => $tenantKey,
            'domain_key' => 'workflow_execution',
            'suggested_manual_action' => 'Clear the blocked workflow backlog.',
        ]);

        $service = $this->makeAuditableService([
            'generated_at' => '2026-04-08T12:00:00+03:00',
            'database_probes' => [
                'configured' => ['success' => true, 'host' => 'localhost', 'message' => 'Connection succeeded.'],
                'localhost' => ['success' => true, 'host' => 'localhost', 'message' => 'Connection succeeded.'],
                'loopback' => ['success' => true, 'host' => '127.0.0.1', 'message' => 'Connection succeeded.'],
            ],
            'provider_probe' => [
                'core_ai_provider_ready' => true,
                'message' => 'Provider ready.',
            ],
            'transient_environment_risk' => false,
        ]);

        $audit = $service->audit([
            'scope' => 'all',
            'user_id' => $userId,
        ]);

        $this->assertSame('block', $audit['overall_status']);
        $this->assertSame('product_regression', $audit['failure_class']);
        $this->assertFalse($audit['takeover_ready']);
        $this->assertSame('block', $audit['domains']['governance']['status']);
        $this->assertSame('block', $audit['domains']['internal_ops']['status']);
        $this->assertSame('block', $this->gateStatus($audit['hard_gates'], 'governance_clear'));
        $this->assertSame(3, BusinessTakeoverConfidenceAuditService::exitCodeFor($audit));
        $this->assertStringContainsString(
            'high-severity autonomy incidents',
            strtolower(implode(' ', (array) ($audit['domains']['governance']['blockers'] ?? [])))
        );
    }

    private function makeAuditableService(array $verification): BusinessTakeoverConfidenceAuditService
    {
        return new class($verification) extends BusinessTakeoverConfidenceAuditService {
            public function __construct(private array $verification)
            {
                parent::__construct();
            }

            protected function buildVerificationSnapshot(array $dbConfig): array
            {
                return $this->verification;
            }
        };
    }

    private function createAdminUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, first_name, last_name, email, password_hash, role, created_at)
             VALUES (?, 'Takeover', 'Operator', ?, ?, 'admin', NOW())",
            [sha1($email . microtime(true)), $email, password_hash('password', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function makeSetupReady(int $userId): void
    {
        Database::execute(
            "UPDATE company_profile
             SET company_name = ?, company_description = ?, is_active = 1
             WHERE id = 1",
            ['Takeover CRM', 'Automation-ready operating profile']
        );
        Database::execute(
            "UPDATE invoice_settings
             SET enabled = 1, company_legal_name = ?
             WHERE id = 1",
            ['Takeover CRM LLC']
        );
        Database::execute(
            "INSERT INTO products (name, description, category, unit_price, is_active, display_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, 1, NOW(), NOW()), (?, ?, ?, ?, 1, 2, NOW(), NOW())",
            ['Core Retainer', 'Core automation engagement', 'service', 1250.00, 'Growth Retainer', 'Expanded automation engagement', 'service', 2250.00]
        );
        Database::execute(
            "INSERT INTO user_strategy_profiles (user_id, target_market_focus, ideal_customer_profile, offer_angle, sales_motion, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                target_market_focus = VALUES(target_market_focus),
                ideal_customer_profile = VALUES(ideal_customer_profile),
                offer_angle = VALUES(offer_angle),
                sales_motion = VALUES(sales_motion),
                updated_at = NOW()",
            [$userId, 'Growth-stage SMEs', 'Owner-led teams', 'AI-led operations', 'Consultative']
        );
        Database::execute(
            "INSERT INTO idea_validation_context (user_id, value_proposition, target_market, pain_points, differentiator, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                value_proposition = VALUES(value_proposition),
                target_market = VALUES(target_market),
                pain_points = VALUES(pain_points),
                differentiator = VALUES(differentiator),
                updated_at = NOW()",
            [$userId, 'Faster autonomous revenue ops', 'Scaling SMEs', 'Slow follow-up', 'CRM-native controls']
        );
    }

    private function seedContactsAndDeals(int $userId, int $count): array
    {
        $contactIds = [];
        $dealIds = [];

        for ($i = 0; $i < $count; $i++) {
            Database::execute(
                "INSERT INTO contacts (uuid, first_name, last_name, email, assigned_to, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    sha1('contact:' . $userId . ':' . $i . ':' . microtime(true)),
                    'Buyer',
                    (string) ($i + 1),
                    sprintf('takeover-buyer-%d-%d@example.test', $userId, $i + 1),
                    $userId,
                ]
            );
            $contactIds[] = (int) Database::lastInsertId();

            Database::execute(
                "INSERT INTO deals (title, contact_id, assigned_to, created_by, stage, value, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'proposal', ?, NOW(), NOW())",
                [
                    sprintf('Takeover Deal %d', $i + 1),
                    $contactIds[$i],
                    $userId,
                    $userId,
                    1500 + ($i * 100),
                ]
            );
            $dealIds[] = (int) Database::lastInsertId();
        }

        return [$contactIds, $dealIds];
    }

    private function seedHealthyConfigurations(): void
    {
        (new AIAutoResponderConfig())->save([
            'enabled' => true,
            'mode' => 'full_auto',
            'default_confidence_threshold' => 0.88,
        ]);

        (new CommercialAutomationConfig())->save([
            'enabled' => true,
            'mode' => 'full_auto',
            'followup_reminders_enabled' => true,
            'auto_send_enabled' => true,
        ]);

        (new DealAutomationConfig())->save([
            'enabled' => true,
            'mode' => 'full_auto',
            'dry_run' => false,
            'require_approval_terminal' => true,
            'min_terminal_confidence' => 0.95,
            'transitions' => [
                ['from' => 'proposal', 'to' => 'negotiation', 'trigger' => 'proposal_sent'],
                ['from' => 'negotiation', 'to' => 'closed_won', 'trigger' => 'invoice_paid'],
            ],
        ]);
    }

    private function seedHealthyJobs(): void
    {
        $jobs = new AutomationJobHealthService();
        $jobs->markSuccess('ai_confidence_calibration', 'Calibration healthy.');
        $jobs->markSuccess('ai_incident_check', 'Incident sweep healthy.');
    }

    private function seedAutoResponderLogs(int $contactId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Database::execute(
                "INSERT INTO ai_autoresponder_logs
                    (contact_id, channel, decision, status, confidence, reason_code, reply_subject, reply_text, request_payload, response_payload, dispatch_payload, latency_ms, created_at)
                 VALUES (?, 'email', 'auto_sent', 'sent', 0.960, 'ready', ?, ?, '{}', '{}', '{}', 320, NOW())",
                [
                    $contactId,
                    sprintf('Takeover Reply %d', $i + 1),
                    sprintf('Automated follow-up %d', $i + 1),
                ]
            );
        }
    }

    private function seedCommercialRuns(int $dealId, int $contactId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Database::execute(
                "INSERT INTO commercial_automation_runs
                    (deal_id, contact_id, trigger_type, trigger_ref_id, decision, action_plan_json, evidence_json, policy_snapshot_json, created_at)
                 VALUES (?, ?, 'deal_stage_changed', ?, 'auto_apply', '{}', '{}', '{}', NOW())",
                [$dealId, $contactId, $i + 1]
            );
        }
    }

    private function seedWorkflowProposals(int $userId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Database::execute(
                "INSERT INTO workflow_automation_proposals
                    (workflow_id, applied_workflow_id, proposal_type, source_surface, status, requested_by_type, requested_by_id,
                     target_workflow_name, decision_mode, governance_decision, confidence_score, proposal_graph_json, current_graph_json,
                     legacy_payload_json, validation_issues_json, risk_summary_json, action_summary_json, diff_summary_json, decision_snapshot_json,
                     notes, created_at, updated_at)
                 VALUES
                    (NULL, ?, 'create', 'integration_test', 'applied', 'user', ?, ?, 'full_auto', 'allow', 0.9700, '{}', '{}', '{}', '[]', '{}', '{}', '{}', '{}', NULL, NOW(), NOW())",
                [$i + 1, $userId, sprintf('Workflow %d', $i + 1)]
            );
        }
    }

    private function seedDealAutomationAudit(int $dealId, int $contactId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Database::execute(
                "INSERT INTO deal_automation_audit
                    (deal_id, contact_id, trigger_type, trigger_ref_id, from_stage, to_stage, decision, confidence, applied, reason, evidence_summary, checklist_results, config_version, created_at)
                 VALUES (?, ?, 'proposal', ?, 'proposal', 'negotiation', 'auto_apply', 0.96, 1, 'Stable automation', '{}', '{}', 1, NOW())",
                [$dealId, $contactId, $i + 1]
            );
        }
    }

    private function seedDecisionOutcomes(int $userId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $surface = $i % 2 === 0 ? 'commercial' : 'workflow';
            $action = $surface === 'commercial' ? 'send_document' : 'create_task';
            Database::execute(
                "INSERT INTO ai_decision_outcomes
                    (user_id, surface, decision_type, action_type, predicted_confidence, context_quality_score, goal_relevance_score,
                     policy_decision, threshold_snapshot_json, outcome_label, outcome_score, outcome_metadata_json, measured_at)
                 VALUES (?, ?, 'auto_apply', ?, 0.9700, 0.9100, 0.9100, 'auto_apply', '{}', 'accepted', 1.0000, '{}', NOW())",
                [$userId, $surface, $action]
            );
        }
    }

    private function seedHealthyRollouts(string $tenantKey, int $userId): void
    {
        $controls = new AIAutonomyDomainControlService();
        foreach (['commercial_mvp', 'customer_thread', 'workflow_execution', 'deal_followthrough', 'task_followthrough'] as $domainKey) {
            $controls->save($tenantKey, $domainKey, [
                'autonomy_mode' => 'full_auto',
                'promotion_status' => 'full_auto',
                'fast_promotion_enabled' => true,
                'metadata' => [
                    'min_sample_size_to_promote' => 5,
                    'min_eval_runs_to_promote' => 1,
                    'manual_freeze' => false,
                    'paused' => false,
                    'forced_safe_mode' => false,
                ],
            ], $userId);

            Database::execute(
                "INSERT INTO ai_autonomy_eval_runs
                    (tenant_key, domain_key, autonomy_mode, run_status, scenario_count, metrics_json, summary_json, started_at, completed_at, created_by)
                 VALUES (?, ?, 'full_auto', 'completed', 12, ?, ?, NOW(), NOW(), ?)",
                [
                    $tenantKey,
                    $domainKey,
                    json_encode([
                        'sample_size' => 12,
                        'precision_at_threshold' => 0.95,
                        'reversal_rate' => 0.02,
                        'edit_after_autonomy_rate' => 0.02,
                        'duplicate_action_rate' => 0.01,
                        'approval_override_rate' => 0.01,
                        'business_completion_rate' => 0.92,
                        'confidence_calibration_error' => 0.03,
                    ]),
                    json_encode([
                        'promotion_recommended' => true,
                        'unstable_reasons' => [],
                    ]),
                    $userId,
                ]
            );
        }
    }

    private function seedHealthyDemonstrations(string $tenantKey, int $userId, array $contactIds, array $dealIds): void
    {
        foreach ($contactIds as $index => $contactId) {
            $dealId = $dealIds[$index] ?? $dealIds[0];

            $this->insertDemonstration($tenantKey, $userId, 'commercial_mvp', 'deal', $dealId, [
                'assistant_confidence' => 0.97,
                'time_to_outcome_minutes' => 8,
                'human_baseline_minutes' => 25,
            ], $dealId, 'deal');

            $this->insertDemonstration($tenantKey, $userId, 'customer_thread', 'contact', $contactId, [
                'assistant_confidence' => 0.95,
                'time_to_outcome_minutes' => 6,
                'human_baseline_minutes' => 18,
            ]);

            $this->insertDemonstration($tenantKey, $userId, 'deal_followthrough', 'deal', $dealId, [
                'assistant_confidence' => 0.96,
                'time_to_outcome_minutes' => 12,
                'human_baseline_minutes' => 30,
            ]);

            $this->insertDemonstration($tenantKey, $userId, 'task_followthrough', 'task', 1000 + $index, [
                'assistant_confidence' => 0.94,
                'time_to_outcome_minutes' => 10,
                'human_baseline_minutes' => 22,
            ]);

            $this->insertDemonstration($tenantKey, $userId, 'workflow_execution', 'workflow', 2000 + $index, [
                'assistant_confidence' => 0.98,
                'workflow_id' => 3000 + $index,
                'workflow_execution_id' => 4000 + $index,
                'time_to_outcome_minutes' => 5,
                'human_baseline_minutes' => 20,
            ]);
        }
    }

    private function insertDemonstration(
        string $tenantKey,
        int $userId,
        string $domainKey,
        string $entityType,
        int $entityId,
        array $metadata,
        ?int $relatedEntityId = null,
        ?string $relatedEntityType = null
    ): void {
        Database::execute(
            "INSERT INTO ai_operator_demonstrations
                (tenant_key, actor_user_id, actor_type, source_surface, domain_key, entity_type, entity_id, related_entity_type, related_entity_id,
                 action_key, action_payload_json, metadata_json, outcome_state_json, outcome_label, free_text_reason, demonstration_hash,
                 was_successful, was_reversed, was_edited, observed_at, created_at)
             VALUES (?, ?, 'ai', 'integration_test', ?, ?, ?, ?, ?, ?, '{}', ?, '{}', 'accepted', 'healthy replay seed', ?, 1, 0, 0, NOW(), NOW())",
            [
                $tenantKey,
                $userId,
                $domainKey,
                $entityType,
                $entityId,
                $relatedEntityType,
                $relatedEntityId,
                $this->actionKeyForDomain($domainKey),
                json_encode($metadata),
                sha1($tenantKey . ':' . $domainKey . ':' . $entityType . ':' . $entityId . ':' . microtime(true)),
            ]
        );
    }

    private function actionKeyForDomain(string $domainKey): string
    {
        return match ($domainKey) {
            'commercial_mvp' => 'send_document',
            'customer_thread' => 'send_reply',
            'workflow_execution' => 'create_task',
            'deal_followthrough' => 'advance_stage',
            'task_followthrough' => 'complete_task',
            default => 'act',
        };
    }

    private function gateStatus(array $hardGates, string $key): ?string
    {
        foreach ($hardGates as $gate) {
            if (($gate['key'] ?? null) === $key) {
                return (string) ($gate['status'] ?? 'block');
            }
        }

        return null;
    }
}
