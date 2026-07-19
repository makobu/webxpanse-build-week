<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIAutonomyIncidentService;
use CRM\Services\AICrossDomainOrchestratorService;
use CRM\Tests\DatabaseTestCase;

class AICrossDomainOrchestratorServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;
    private int $dealId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['orchestrator@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())",
            [$this->userId]
        );

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at) VALUES (1, ?, ?, ?, ?, NOW())",
            [uniqid('orchestrator_', true), 'Cross', 'Domain', 'cross-domain@example.com']
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, currency, created_at)
             VALUES (1, ?, ?, ?, ?, 'proposal', 800, 'USD', NOW())",
            ['Cross Domain Deal', $this->contactId, $this->userId, $this->userId]
        );
        $this->dealId = (int) Database::lastInsertId();
    }

    public function testStartObjectiveCreatesSequentialPlan(): void
    {
        $service = new AICrossDomainOrchestratorService();
        $run = $service->startObjective([
            'objective_key' => 'progress_deal_to_next_stage',
            'primary_entity_type' => 'deal',
            'primary_entity_id' => $this->dealId,
            'related_entity_ids' => ['contact_id' => $this->contactId],
        ], $this->userId);

        $this->assertSame('planned', $run['run_status']);
        $this->assertSame(1, (int) ($run['workspace_id'] ?? 0));
        $this->assertSame('workspace:1', (string) ($run['tenant_key'] ?? ''));
        $this->assertNotEmpty($run['steps']);
        $this->assertSame('deal_followthrough', $run['steps'][0]['domain_key']);
    }

    public function testExecuteBlockedRunCreatesRecoveryItem(): void
    {
        $service = new AICrossDomainOrchestratorService();
        $run = $service->startObjective([
            'objective_key' => 'close_task_followthrough_after_milestone',
            'primary_entity_type' => 'task',
            'primary_entity_id' => 0,
        ], $this->userId);

        $executed = $service->executeRun((int) $run['id'], $this->userId);

        $this->assertSame('blocked', $executed['run_status']);
        $blockedSteps = array_values(array_filter($executed['steps'], static fn(array $step): bool => ($step['step_status'] ?? '') === 'blocked'));
        $this->assertNotEmpty($blockedSteps);

        $incidents = new AIAutonomyIncidentService();
        $queue = $incidents->listRecoveryQueue('workspace:1', 'cross_domain_orchestrator', 10);
        $this->assertNotEmpty($queue);
        $this->assertSame((int) $executed['id'], (int) (($queue[0]['payload']['orchestration_run_id'] ?? 0)));
    }
}
