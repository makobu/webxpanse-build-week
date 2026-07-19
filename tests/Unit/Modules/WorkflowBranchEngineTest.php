<?php
/**
 * Workflow Branch Engine Unit Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\WorkflowBranchEngine;

class WorkflowBranchEngineTest extends DatabaseTestCase
{
    private WorkflowBranchEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new WorkflowBranchEngine();
    }

    public function testBuildExecutionGraphWithEmptyVisualData(): void
    {
        $workflow = ['visual_data' => null];
        $graph = $this->engine->buildExecutionGraph($workflow, []);
        $this->assertEmpty($graph);
    }

    public function testBuildExecutionGraphWithNodes(): void
    {
        $workflow = [
            'visual_data' => json_encode([
                'nodes' => [
                    ['id' => 'node_1', 'type' => 'trigger', 'key' => 'contact_created', 'data' => []],
                    ['id' => 'node_2', 'type' => 'action', 'key' => 'send_email', 'data' => ['type' => 'send_email', 'subject' => 'Test']]
                ],
                'connections' => [
                    ['from' => 'node_1', 'to' => 'node_2', 'branchType' => null]
                ]
            ])
        ];
        $branches = [['source_node_id' => 'node_1', 'target_node_id' => 'node_2', 'branch_type' => 'success', 'condition_value' => null]];
        $graph = $this->engine->buildExecutionGraph($workflow, $branches);
        $this->assertCount(2, $graph);
        $this->assertArrayHasKey('node_1', $graph);
        $this->assertArrayHasKey('node_2', $graph);
        $this->assertEquals('trigger', $graph['node_1']['type']);
        $this->assertEquals('action', $graph['node_2']['type']);
    }

    public function testGetExecutionPathWithCondition(): void
    {
        $workflow = [
            'visual_data' => json_encode([
                'nodes' => [
                    ['id' => 'node_1', 'type' => 'trigger', 'key' => 'contact_created', 'data' => []],
                    ['id' => 'node_2', 'type' => 'condition', 'key' => 'condition', 'data' => ['field' => 'stage', 'operator' => 'equals', 'value' => 'qualified']],
                    ['id' => 'node_3', 'type' => 'action', 'key' => 'send_email', 'data' => ['type' => 'send_email']]
                ],
                'connections' => [
                    ['from' => 'node_1', 'to' => 'node_2'],
                    ['from' => 'node_2', 'to' => 'node_3', 'branchType' => 'true']
                ]
            ])
        ];
        $branches = [
            ['source_node_id' => 'node_1', 'target_node_id' => 'node_2', 'branch_type' => 'success', 'condition_value' => null],
            ['source_node_id' => 'node_2', 'target_node_id' => 'node_3', 'branch_type' => 'conditional', 'condition_value' => 'true']
        ];
        $graph = $this->engine->buildExecutionGraph($workflow, $branches);
        $context = ['stage' => 'qualified', 'contact_id' => 1];
        $result = $this->engine->getExecutionPath($graph, $context, false);
        $this->assertNotEmpty($result['path']);
        $this->assertArrayHasKey('node_2', $result['conditionResults']);
        $this->assertTrue($result['conditionResults']['node_2']);
    }
}
