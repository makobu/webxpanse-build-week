<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIAutonomyIncidentService;
use CRM\Services\AICrossDomainPlanStoreService;
use CRM\Services\AICrossDomainResumeService;
use CRM\Tests\DatabaseTestCase;

class AICrossDomainResumeServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['resume@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (uuid, first_name, last_name, email, created_at) VALUES (?, ?, ?, ?, NOW())",
            [uniqid('resume_', true), 'Resume', 'Flow', 'resume@example.com']
        );
        $this->contactId = (int) Database::lastInsertId();
    }

    public function testMatchingSignalMarksWaitingRunReadyToResume(): void
    {
        $store = new AICrossDomainPlanStoreService();
        $runId = $store->createRun([
            'tenant_key' => 'contact:' . $this->contactId,
            'objective_key' => 'recover_stalled_customer_thread',
            'primary_entity_type' => 'contact',
            'primary_entity_id' => $this->contactId,
            'execution_mode' => 'plan_only',
            'run_status' => 'waiting',
            'created_by' => $this->userId,
            'wait_state' => [
                'waiting_reason' => 'waiting_on_contact_response',
                'waiting_on_type' => 'thread_state',
                'resume_conditions' => [
                    'contact_id' => $this->contactId,
                    'thread_statuses' => ['waiting_on_us', 'open'],
                ],
                'timeout_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            ],
        ]);

        $service = new AICrossDomainResumeService();
        $result = $service->handleSignal([
            'source_domain' => 'customer_thread',
            'related_entity_ids' => ['contact_id' => $this->contactId],
            'metadata' => ['thread_status' => 'waiting_on_us'],
        ]);

        $this->assertCount(1, $result['updated_runs']);
        $this->assertSame('ready_to_resume', $result['updated_runs'][0]['run_status']);
    }

    public function testTimedOutWaitingRunQueuesRecovery(): void
    {
        $store = new AICrossDomainPlanStoreService();
        $runId = $store->createRun([
            'tenant_key' => 'contact:' . $this->contactId,
            'objective_key' => 'recover_stalled_customer_thread',
            'primary_entity_type' => 'contact',
            'primary_entity_id' => $this->contactId,
            'execution_mode' => 'plan_only',
            'run_status' => 'waiting',
            'created_by' => $this->userId,
            'wait_state' => [
                'waiting_reason' => 'waiting_on_contact_response',
                'waiting_on_type' => 'thread_state',
                'resume_conditions' => [
                    'contact_id' => $this->contactId,
                    'thread_statuses' => ['waiting_on_us'],
                ],
                'timeout_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
            ],
        ]);

        $service = new AICrossDomainResumeService();
        $service->refreshWaitingTimeouts('contact:' . $this->contactId, 10);

        $incidents = new AIAutonomyIncidentService();
        $queue = $incidents->listRecoveryQueue('workspace:1', 'cross_domain_orchestrator', 10);

        $this->assertNotEmpty($queue);
        $this->assertSame($runId, (int) ($queue[0]['payload']['orchestration_run_id'] ?? 0));
    }
}
