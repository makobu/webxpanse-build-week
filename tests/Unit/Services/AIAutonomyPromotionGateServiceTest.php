<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIAutonomyDomainControlService;
use CRM\Services\AIAutonomyPromotionGateService;
use CRM\Tests\DatabaseTestCase;

class AIAutonomyPromotionGateServiceTest extends DatabaseTestCase
{
    private int $workspaceId = 1;

    public function testAutoPromotesWhenEvaluationGatesPass(): void
    {
        (new AIAutonomyDomainControlService())->save('contact:88', 'commercial_mvp', [
            'autonomy_mode' => 'suggest_only',
            'promotion_status' => 'suggest_only',
            'fast_promotion_enabled' => true,
            'metadata' => [
                'min_sample_size_to_promote' => 5,
                'min_eval_runs_to_promote' => 1,
            ],
        ]);

        Database::execute(
            "INSERT INTO ai_autonomy_eval_runs
                (workspace_id, tenant_key, domain_key, autonomy_mode, run_status, scenario_count, metrics_json, summary_json, started_at, completed_at)
             VALUES (?, ?, ?, 'suggest_only', 'completed', 12, ?, '{}', NOW(), NOW())",
            [
                $this->workspaceId,
                'contact:88',
                'commercial_mvp',
                json_encode([
                    'sample_size' => 12,
                    'precision_at_threshold' => 0.95,
                    'reversal_rate' => 0.02,
                    'edit_after_autonomy_rate' => 0.04,
                    'duplicate_action_rate' => 0.01,
                    'approval_override_rate' => 0.03,
                ]),
            ]
        );

        $result = (new AIAutonomyPromotionGateService())->evaluate('contact:88', 'commercial_mvp');
        $control = (new AIAutonomyDomainControlService())->get('contact:88', 'commercial_mvp');

        $this->assertSame('promote', $result['decision']);
        $this->assertSame('auto_safe', $result['recommended_mode']);
        $this->assertSame('auto_safe', $control['autonomy_mode']);
    }

    public function testAutoDowngradesWhenDriftIsUnstable(): void
    {
        (new AIAutonomyDomainControlService())->save('contact:99', 'commercial_mvp', [
            'autonomy_mode' => 'full_auto',
            'promotion_status' => 'full_auto',
            'metadata' => [
                'auto_downgrade_on_drift' => true,
                'min_sample_size_to_promote' => 2,
            ],
        ]);

        Database::execute(
            "INSERT INTO ai_autonomy_eval_runs
                (workspace_id, tenant_key, domain_key, autonomy_mode, run_status, scenario_count, metrics_json, summary_json, started_at, completed_at)
             VALUES (?, ?, ?, 'full_auto', 'completed', 10, ?, '{}', NOW(), NOW())",
            [
                $this->workspaceId,
                'contact:99',
                'commercial_mvp',
                json_encode([
                    'sample_size' => 10,
                    'precision_at_threshold' => 0.92,
                    'reversal_rate' => 0.02,
                    'edit_after_autonomy_rate' => 0.02,
                    'duplicate_action_rate' => 0.01,
                    'approval_override_rate' => 0.01,
                ]),
            ]
        );

        for ($i = 0; $i < 5; $i++) {
            Database::execute(
                "INSERT INTO ai_operator_demonstrations
                    (workspace_id, tenant_key, actor_user_id, actor_type, source_surface, domain_key, entity_type, entity_id, action_key,
                     action_payload_json, metadata_json, outcome_state_json, outcome_label, demonstration_hash, was_successful, was_reversed, was_edited, observed_at)
                 VALUES (?, ?, NULL, 'ai', 'commercial_automation', 'commercial_mvp', 'invoice', ?, 'send_document', '{}', ?, '{}', 'failed', ?, 0, 1, 0, NOW())",
                [
                    $this->workspaceId,
                    'contact:99',
                    $i + 1,
                    json_encode(['assistant_confidence' => 0.95]),
                    sha1('contact:99:' . $i),
                ]
            );
        }

        $result = (new AIAutonomyPromotionGateService())->evaluate('contact:99', 'commercial_mvp');
        $control = (new AIAutonomyDomainControlService())->get('contact:99', 'commercial_mvp');

        $this->assertSame('downgrade', $result['decision']);
        $this->assertSame('auto_safe', $control['autonomy_mode']);
    }

    public function testCustomerCareRequiresEnoughEvidenceBeforePromotion(): void
    {
        (new AIAutonomyDomainControlService())->save('workspace:1', 'customer_care', [
            'autonomy_mode' => 'suggest_only',
            'promotion_status' => 'suggest_only',
            'fast_promotion_enabled' => true,
        ]);
        $this->insertCustomerCareEvaluation(10);

        $result = (new AIAutonomyPromotionGateService())->evaluate('workspace:1', 'customer_care', false);

        $this->assertSame('hold', $result['decision']);
        $this->assertSame('suggest_only', $result['recommended_mode']);
        $this->assertContains('sample_size_below_gate', $result['reasons']);
    }

    public function testCustomerCareStrongOutcomesPromoteToAutoSafe(): void
    {
        (new AIAutonomyDomainControlService())->save('workspace:1', 'customer_care', [
            'autonomy_mode' => 'suggest_only',
            'promotion_status' => 'suggest_only',
            'fast_promotion_enabled' => true,
        ]);
        $this->insertCustomerCareEvaluation(25);

        $result = (new AIAutonomyPromotionGateService())->evaluate('workspace:1', 'customer_care');
        $control = (new AIAutonomyDomainControlService())->get('workspace:1', 'customer_care');

        $this->assertSame('promote', $result['decision']);
        $this->assertSame('auto_safe', $result['recommended_mode']);
        $this->assertSame('auto_safe', $control['autonomy_mode']);
    }

    public function testCustomerCareCriticalIncidentBlocksPromotion(): void
    {
        (new AIAutonomyDomainControlService())->save('workspace:1', 'customer_care', [
            'autonomy_mode' => 'suggest_only',
            'promotion_status' => 'suggest_only',
            'fast_promotion_enabled' => true,
        ]);
        $this->insertCustomerCareEvaluation(25);
        Database::execute(
            "INSERT INTO ai_autonomy_incidents
                (workspace_id, tenant_key, domain_key, action_key, incident_key, severity, status, reason_codes_json, details_json, created_at, updated_at)
             VALUES
                (?, 'workspace:1', 'customer_care', 'record_check_in', 'unit_critical', 'critical', 'open', ?, '{}', NOW(), NOW())",
            [$this->workspaceId, json_encode(['unit_test'])]
        );

        $result = (new AIAutonomyPromotionGateService())->evaluate('workspace:1', 'customer_care');
        $control = (new AIAutonomyDomainControlService())->get('workspace:1', 'customer_care');

        $this->assertSame('block', $result['decision']);
        $this->assertSame('blocked', $result['promotion_status']);
        $this->assertSame('blocked', $control['promotion_status']);
        $this->assertContains('critical_incident_active', $result['reasons']);
    }

    private function insertCustomerCareEvaluation(int $sampleSize): void
    {
        Database::execute(
            "INSERT INTO ai_autonomy_eval_runs
                (workspace_id, tenant_key, domain_key, autonomy_mode, run_status, scenario_count, metrics_json, summary_json, started_at, completed_at)
             VALUES (?, 'workspace:1', 'customer_care', 'suggest_only', 'completed', ?, ?, '{}', NOW(), NOW())",
            [
                $this->workspaceId,
                $sampleSize,
                json_encode([
                    'sample_size' => $sampleSize,
                    'precision_at_threshold' => 0.96,
                    'reversal_rate' => 0.01,
                    'edit_after_autonomy_rate' => 0.04,
                    'duplicate_action_rate' => 0.01,
                    'approval_override_rate' => 0.02,
                ]),
            ]
        );
    }
}
