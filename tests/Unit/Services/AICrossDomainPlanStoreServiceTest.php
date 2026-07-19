<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AICrossDomainPlanStoreService;
use CRM\Tests\DatabaseTestCase;

class AICrossDomainPlanStoreServiceTest extends DatabaseTestCase
{
    public function testCreateRunAndStepHydratesJsonFields(): void
    {
        $service = new AICrossDomainPlanStoreService();
        $runId = $service->createRun([
            'tenant_key' => 'workspace:1',
            'objective_key' => 'progress_deal_to_next_stage',
            'primary_entity_type' => 'deal',
            'primary_entity_id' => 17,
            'related_entities' => ['contact_id' => 77],
            'execution_mode' => 'plan_only',
            'plan' => ['objective_key' => 'progress_deal_to_next_stage'],
            'summary' => ['blocked_candidates' => []],
            'created_by' => 5,
        ]);

        $stepId = $service->addStep($runId, [
            'step_order' => 1,
            'domain_key' => 'deal_followthrough',
            'action_key' => 'progress_stage',
            'target_entity_type' => 'deal',
            'target_entity_id' => 17,
            'customer_facing' => false,
            'assistant_confidence' => 0.85,
            'precheck_status' => 'pending',
            'step_status' => 'planned',
            'plan_context' => ['trigger' => 'cross_domain'],
            'result' => ['note' => 'planned'],
        ]);

        $run = $service->getRun($runId);
        $step = $service->getStep($stepId);

        $this->assertSame(1, (int) ($run['workspace_id'] ?? 0));
        $this->assertSame('workspace:1', $run['tenant_key']);
        $this->assertSame(77, (int) ($run['related_entities']['contact_id'] ?? 0));
        $this->assertSame(1, (int) ($step['workspace_id'] ?? 0));
        $this->assertSame('cross_domain', $step['plan_context']['trigger'] ?? null);
        $this->assertSame('planned', $step['result']['note'] ?? null);
    }
}
