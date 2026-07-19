<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\WorkflowGraphService;
use PHPUnit\Framework\TestCase;

class WorkflowGraphServiceTest extends TestCase
{
    private WorkflowGraphService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowGraphService();
    }

    public function testMigrateLegacyWorkflowCreatesCanonicalGraph(): void
    {
        $graph = $this->service->migrateLegacyWorkflow([
            'name' => 'Legacy Workflow',
            'trigger_config' => json_encode(['type' => 'contact_created']),
            'conditions' => json_encode(['field' => 'stage', 'operator' => 'equals', 'value' => 'qualified']),
            'actions' => json_encode([
                ['type' => 'change_stage', 'stage' => 'contacted'],
                ['type' => 'wait_for_days', 'days' => 2],
            ]),
        ]);

        $this->assertSame(2, $graph['version']);
        $this->assertCount(4, $graph['nodes']);
        $this->assertSame('trigger', $graph['nodes'][0]['type']);
        $this->assertSame('delay', $graph['nodes'][3]['type']);
    }

    public function testValidateGraphRejectsMultipleTriggers(): void
    {
        $result = $this->service->validateGraph([
            'nodes' => [
                ['id' => 'trigger_1', 'type' => 'trigger', 'subtype' => 'contact_created', 'config' => []],
                ['id' => 'trigger_2', 'type' => 'trigger', 'subtype' => 'form_submitted', 'config' => []],
            ],
            'edges' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['issues']);
    }
}
