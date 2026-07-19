<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIAutonomyIncidentService;
use CRM\Services\AICrossDomainPlanStoreService;
use CRM\Services\AIAutonomyRecoveryWorkbenchService;
use CRM\Tests\DatabaseTestCase;

class AIAutonomyRecoveryWorkbenchServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['operator@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testAssignAndResolveRecoveryItem(): void
    {
        $incidents = new AIAutonomyIncidentService();
        $incidentId = (int) $incidents->recordIncident([
            'tenant_key' => 'workspace:1',
            'domain_key' => 'workflow_execution',
            'incident_key' => 'workflow_action_failed',
            'severity' => 'high',
        ]);
        $recoveryId = (int) $incidents->queueRecovery([
            'incident_id' => $incidentId,
            'tenant_key' => 'workspace:1',
            'domain_key' => 'workflow_execution',
            'action_key' => 'send_email',
            'suggested_manual_action' => 'Review failed workflow send',
            'payload' => [
                'workflow_id' => 8,
                'workflow_execution_id' => 13,
                'workflow_node_id' => 'node-1',
            ],
        ]);

        $service = new AIAutonomyRecoveryWorkbenchService();
        $assigned = $service->handleRecoveryAction('assign_recovery_item', [
            'recovery_queue_id' => $recoveryId,
            'assigned_to' => $this->userId,
            'reason' => 'Assigning to operator',
        ], $this->userId);
        $this->assertSame('assigned', $assigned['status']);
        $this->assertSame($this->userId, (int) $assigned['assigned_to']);

        $resolved = $service->handleRecoveryAction('resolve_recovery_item', [
            'recovery_queue_id' => $recoveryId,
            'reason' => 'Handled manually',
        ], $this->userId);
        $this->assertSame('resolved', $resolved['status']);

        $incident = $incidents->getIncident($incidentId);
        $this->assertSame('resolved', $incident['status']);
    }

    public function testSuppressIncidentLogsOperatorAction(): void
    {
        $incidents = new AIAutonomyIncidentService();
        $incidentId = (int) $incidents->recordIncident([
            'tenant_key' => 'workspace:1',
            'domain_key' => 'customer_thread',
            'incident_key' => 'governance_review_required',
            'severity' => 'medium',
        ]);

        $service = new AIAutonomyRecoveryWorkbenchService();
        $updated = $service->handleRecoveryAction('suppress_incident', [
            'incident_id' => $incidentId,
            'reason' => 'Accepted risk for this tenant',
        ], $this->userId);

        $this->assertSame('suppressed', $updated['status']);

        $actions = $incidents->listOperatorActions('workspace:1', 'customer_thread', 10);
        $this->assertNotEmpty($actions);
        $this->assertSame('suppress_incident', $actions[0]['action_key']);
    }

    public function testCancelOrchestrationRunFromWorkbench(): void
    {
        $store = new AICrossDomainPlanStoreService();
        $runId = $store->createRun([
            'tenant_key' => 'workspace:1',
            'objective_key' => 'progress_deal_to_next_stage',
            'primary_entity_type' => 'deal',
            'primary_entity_id' => 12,
            'execution_mode' => 'plan_only',
            'run_status' => 'planned',
            'created_by' => $this->userId,
        ]);

        $service = new AIAutonomyRecoveryWorkbenchService();
        $updated = $service->handleRecoveryAction('cancel_orchestration_run', [
            'orchestration_run_id' => $runId,
            'reason' => 'Unsafe to proceed',
        ], $this->userId);

        $this->assertSame('canceled', $updated['run_status']);

        $actions = (new AIAutonomyIncidentService())->listOperatorActions('workspace:1', 'cross_domain_orchestrator', 10);
        $this->assertNotEmpty($actions);
    }
}
