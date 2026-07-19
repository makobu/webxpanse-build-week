<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIAutonomyDomainControlService;
use CRM\Services\AIService;
use CRM\Services\WorkflowAutomationGenerationService;
use CRM\Tests\DatabaseTestCase;

class WorkflowAutomationGenerationServiceTest extends DatabaseTestCase
{
    public function testSuggestOnlyModeReturnsSuggestionWithoutPersistingProposal(): void
    {
        $this->saveWorkflowControl([
            'autonomy_mode' => 'suggest_only',
            'promotion_status' => 'suggest_only',
            'metadata' => [
                'allowed_actions' => ['create_task'],
            ],
        ]);

        $service = $this->makeServiceWithGraph($this->buildCreateTaskGraph('Suggested workflow'));
        $result = $service->generate([
            'prompt' => 'Create a workflow that creates a follow-up task after a new contact is created.',
            'source_surface' => 'unit_test',
        ], 5);

        $this->assertSame('suggested', $result['status']);
        $this->assertNull($result['proposal_id']);
        $this->assertNull($result['workflow_id']);
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workflow_automation_proposals")['c'] ?? 0));
    }

    public function testFullAutoAppliesConfidentInternalWorkflow(): void
    {
        $this->saveWorkflowControl([
            'autonomy_mode' => 'full_auto',
            'promotion_status' => 'full_auto',
            'metadata' => [
                'allowed_actions' => ['create_task'],
                'block_customer_facing_full_auto' => false,
            ],
        ]);

        $service = $this->makeServiceWithGraph($this->buildCreateTaskGraph('Applied workflow'));
        $result = $service->generate([
            'prompt' => 'Create a workflow that adds an internal follow-up task whenever a new contact is created for the sales team.',
            'source_surface' => 'unit_test',
        ], 9);

        $proposal = Database::queryOne("SELECT * FROM workflow_automation_proposals ORDER BY id DESC LIMIT 1");
        $workflow = Database::queryOne("SELECT * FROM workflows WHERE id = ?", [(int) ($result['workflow_id'] ?? 0)]);

        $this->assertSame('applied', $result['status']);
        $this->assertNotNull($result['proposal_id']);
        $this->assertNotNull($result['workflow_id']);
        $this->assertSame('applied', $proposal['status'] ?? null);
        $this->assertSame(1, (int) ($proposal['workspace_id'] ?? 0));
        $this->assertSame('Applied workflow', $workflow['name'] ?? null);
    }

    public function testFullAutoFallsBackToPendingApprovalForCustomerFacingWorkflow(): void
    {
        $this->saveWorkflowControl([
            'autonomy_mode' => 'full_auto',
            'promotion_status' => 'full_auto',
            'metadata' => [
                'allowed_actions' => ['send_email'],
                'block_customer_facing_full_auto' => true,
            ],
        ]);

        $service = $this->makeServiceWithGraph($this->buildSendEmailGraph('Customer follow-up'));
        $result = $service->generate([
            'prompt' => 'Create a workflow that sends a follow-up email with a clear subject and body after a form submission.',
            'source_surface' => 'unit_test',
        ], 12);

        $proposal = Database::queryOne("SELECT * FROM workflow_automation_proposals ORDER BY id DESC LIMIT 1");

        $this->assertSame('pending_approval', $result['status']);
        $this->assertNotNull($result['proposal_id']);
        $this->assertNull($result['workflow_id']);
        $this->assertSame('pending', $proposal['status'] ?? null);
        $this->assertSame(1, (int) ($proposal['workspace_id'] ?? 0));
    }

    private function saveWorkflowControl(array $control): void
    {
        (new AIAutonomyDomainControlService())->save('global:default', 'workflow_execution', $control, null);
    }

    private function makeServiceWithGraph(array $graph): WorkflowAutomationGenerationService
    {
        $ai = new class($graph) extends AIService {
            public function __construct(private array $graph)
            {
            }

            public function process(string $task, array $data, array $context = []): string
            {
                return json_encode(['graph' => $this->graph], JSON_UNESCAPED_SLASHES);
            }
        };

        return new WorkflowAutomationGenerationService($ai);
    }

    private function buildCreateTaskGraph(string $name): array
    {
        return [
            'version' => 2,
            'meta' => [
                'name' => $name,
                'mode' => 'mixed',
            ],
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'trigger',
                    'subtype' => 'contact_created',
                    'position' => ['x' => 120, 'y' => 120],
                    'config' => ['type' => 'contact_created'],
                ],
                [
                    'id' => 'action_1',
                    'type' => 'action',
                    'subtype' => 'create_task',
                    'position' => ['x' => 420, 'y' => 120],
                    'config' => [
                        'type' => 'create_task',
                        'title' => 'Follow up with new contact',
                        'description' => 'Review the new contact and follow up.',
                    ],
                ],
            ],
            'edges' => [
                [
                    'id' => 'edge_1',
                    'source' => 'trigger_1',
                    'target' => 'action_1',
                    'branch' => 'default',
                    'order' => 0,
                ],
            ],
        ];
    }

    private function buildSendEmailGraph(string $name): array
    {
        return [
            'version' => 2,
            'meta' => [
                'name' => $name,
                'mode' => 'mixed',
            ],
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'trigger',
                    'subtype' => 'form_submitted',
                    'position' => ['x' => 120, 'y' => 120],
                    'config' => ['type' => 'form_submitted'],
                ],
                [
                    'id' => 'action_1',
                    'type' => 'action',
                    'subtype' => 'send_email',
                    'position' => ['x' => 420, 'y' => 120],
                    'config' => [
                        'type' => 'send_email',
                        'subject' => 'Thanks for reaching out',
                        'body' => 'We received your form submission and will follow up shortly.',
                    ],
                ],
            ],
            'edges' => [
                [
                    'id' => 'edge_1',
                    'source' => 'trigger_1',
                    'target' => 'action_1',
                    'branch' => 'default',
                    'order' => 0,
                ],
            ],
        ];
    }
}
