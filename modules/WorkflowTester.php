<?php
/**
 * Workflow Tester Module
 * Test workflows without executing actual actions
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\WorkflowExecutionService;
use CRM\Services\WorkspaceScopeService;

class WorkflowTester
{
    private WorkflowExecutionService $executionService;
    private ConditionEvaluator $conditionEvaluator;
    private WorkflowBranchEngine $branchEngine;
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->executionService = new WorkflowExecutionService();
        $this->conditionEvaluator = new ConditionEvaluator();
        $this->branchEngine = new WorkflowBranchEngine();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    /**
     * Test workflow execution (dry run)
     */
    public function testWorkflow(int $workflowId, array $testData): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $workflow = Database::queryOne(
            "SELECT * FROM workflows WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $workflowId]
        );

        if (!$workflow) {
            throw new \Exception("Workflow not found");
        }

        if (!empty($testData['contact_id'])) {
            $this->workspaceScope->assertSameWorkspace('contacts', (int) $testData['contact_id'], $workspaceId);
        }

        $trigger = json_decode($workflow['trigger_config'], true) ?? [];
        $conditions = json_decode($workflow['conditions'], true) ?? [];
        $actions = json_decode($workflow['actions'], true) ?? [];
        $visualData = json_decode($workflow['visual_data'] ?? 'null', true);

        $conditionsMet = $this->conditionEvaluator->evaluateConditions($conditions, $testData);

        $simulatedActions = [];
        $executionPath = [];
        $conditionResults = [];

        if (!empty($visualData['nodes'])) {
            $branches = Database::query(
                "SELECT source_node_id, target_node_id, branch_type, condition_value, branch_order 
                 FROM workflow_branches WHERE workflow_id = ? ORDER BY branch_order ASC",
                [$workflowId]
            );
            $graph = $this->branchEngine->buildExecutionGraph($workflow, $branches);
            $pathResult = $this->branchEngine->getExecutionPath($graph, $testData, true);

            foreach ($pathResult['path'] as $index => $pathItem) {
                $action = $pathItem['config'];
                $simulatedActions[] = [
                    'index' => $index,
                    'node_id' => $pathItem['nodeId'] ?? null,
                    'type' => $action['type'] ?? 'unknown',
                    'would_execute' => $conditionsMet,
                    'description' => $this->describeAction($action, $testData)
                ];
            }
            $executionPath = $pathResult['path'];
            $conditionResults = $pathResult['conditionResults'] ?? [];
        } else {
            foreach ($actions as $index => $action) {
                $simulatedActions[] = [
                    'index' => $index,
                    'type' => $action['type'] ?? 'unknown',
                    'would_execute' => $conditionsMet,
                    'description' => $this->describeAction($action, $testData)
                ];
            }
        }

        return [
            'workflow_id' => $workflowId,
            'workflow_name' => $workflow['name'],
            'trigger' => $trigger,
            'conditions' => $conditions,
            'conditions_met' => $conditionsMet,
            'actions' => $simulatedActions,
            'execution_path' => $executionPath,
            'condition_results' => $conditionResults,
            'test_data' => $testData,
            'would_execute' => $conditionsMet && !empty($simulatedActions)
        ];
    }
    
    /**
     * Describe what an action would do
     */
    private function describeAction(array $action, array $context): string
    {
        $type = $action['type'] ?? 'unknown';
        
        switch ($type) {
            case 'send_email':
                $subject = $action['subject'] ?? 'Email';
                return "Send email: " . $this->replaceTokens($subject, $context);
                
            case 'send_whatsapp':
                return "Send WhatsApp message";

            case 'send_sms':
                return "Send SMS message";

            case 'add_tag':
                $tagName = $action['tag_name'] ?? $action['tag_id'] ?? 'tag';
                return "Add tag: $tagName";

            case 'remove_tag':
                $tagName = $action['tag_name'] ?? $action['tag_id'] ?? 'tag';
                return "Remove tag: $tagName";

            case 'change_stage':
                $stage = $action['stage'] ?? 'stage';
                return "Change stage to: $stage";
                
            case 'create_task':
                $title = $action['title'] ?? 'Task';
                return "Create task: " . $this->replaceTokens($title, $context);
                
            case 'assign_to_user':
                return "Assign to user";
                
            case 'wait_for_days':
                $days = $action['days'] ?? 1;
                return "Wait for $days days";
                
            case 'update_contact_field':
                $field = $action['field'] ?? 'field';
                return "Update contact field: $field";
                
            case 'create_deal':
                $name = $action['name'] ?? $action['title'] ?? 'Deal';
                return "Create deal: " . $this->replaceTokens($name, $context);

            case 'update_deal_stage':
                $stage = $action['stage'] ?? 'stage';
                return "Update deal stage to: $stage";

            case 'add_to_deal':
                return "Add contact to deal";

            case 'add_note':
                return "Add note";

            case 'create_activity':
                $type = $action['activity_type'] ?? 'activity';
                return "Create activity: $type";

            case 'update_lead_score':
                $score = $action['score'] ?? 0;
                $operation = $action['operation'] ?? 'set';
                return "Update composite lead score: $operation $score";
                
            case 'call_webhook':
                $url = $action['url'] ?? 'webhook';
                return "Call webhook: $url";

            case 'apply_smart_tags':
                return "Apply smart tags";

            case 'send_in_app_notification':
                $title = $action['title'] ?? 'Notification';
                return "Send notification: " . $this->replaceTokens($title, $context);

            case 'remove_from_workflow':
                return "Remove contact from workflow";

            default:
                return "Execute action: $type";
        }
    }
    
    /**
     * Replace tokens in string
     */
    private function replaceTokens(string $text, array $context): string
    {
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $text = str_replace('{' . $key . '}', (string)$value, $text);
            }
        }
        return $text;
    }
}
