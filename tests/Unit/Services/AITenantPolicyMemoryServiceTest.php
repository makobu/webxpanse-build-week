<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AITenantPolicyMemoryService;
use CRM\Tests\DatabaseTestCase;

class AITenantPolicyMemoryServiceTest extends DatabaseTestCase
{
    private AITenantPolicyMemoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AITenantPolicyMemoryService();
    }

    public function testRecordActionObservationAggregatesTenantMemory(): void
    {
        $this->service->recordActionObservation([
            'tenant_key' => 'contact:55',
            'scope_key' => 'commercial_mvp',
            'action_key' => 'send_document',
            'channel' => 'email',
            'document_type' => 'quote',
            'actor_user_id' => 10,
            'was_successful' => true,
        ]);
        $this->service->recordActionObservation([
            'tenant_key' => 'contact:55',
            'scope_key' => 'commercial_mvp',
            'action_key' => 'send_document',
            'channel' => 'email',
            'document_type' => 'quote',
            'actor_user_id' => 10,
            'was_successful' => false,
            'was_reversed' => true,
        ]);

        $memory = $this->service->getScopeMemory('contact:55', 'commercial_mvp');

        $this->assertSame(2, $memory['action:send_document']['evidence_count']);
        $this->assertSame(1, $memory['action:send_document']['success_count']);
        $this->assertSame(1, $memory['action:send_document']['reversal_count']);
        $this->assertSame(2, $memory['preferred_channel:email']['evidence_count']);
        $this->assertSame('quote', $memory['document_type:quote']['value']['document_type']);
    }
}
