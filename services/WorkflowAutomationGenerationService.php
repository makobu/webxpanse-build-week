<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\AutomationEngine;

class WorkflowAutomationGenerationService
{
    private const ACTION_THRESHOLDS = [
        'send_email' => 0.90,
        'send_whatsapp' => 0.88,
        'send_sms' => 0.87,
        'create_task' => 0.84,
        'assign_to_user' => 0.82,
        'update_contact_field' => 0.86,
        'change_stage' => 0.83,
        'update_deal_stage' => 0.85,
        'add_tag' => 0.80,
        'remove_tag' => 0.80,
        'create_activity' => 0.78,
        'add_note' => 0.78,
        'update_lead_score' => 0.82,
        'create_deal' => 0.84,
        'add_to_deal' => 0.84,
        'send_in_app_notification' => 0.76,
        'call_webhook' => 0.80,
        'apply_smart_tags' => 0.76,
        'remove_from_workflow' => 0.70,
    ];
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?AIService $ai = null,
        private ?WorkflowGraphService $graphService = null,
        private ?WorkflowAutomationControlService $controls = null,
        private ?WorkflowAutomationProposalService $proposals = null,
        private ?AIAutonomyGovernanceService $governance = null,
        private ?AutomationEngine $automationEngine = null
    ) {
        $this->ai = $this->ai ?? new AIService();
        $this->graphService = $this->graphService ?? new WorkflowGraphService();
        $this->controls = $this->controls ?? new WorkflowAutomationControlService();
        $this->proposals = $this->proposals ?? new WorkflowAutomationProposalService();
        $this->governance = $this->governance ?? new AIAutonomyGovernanceService();
        $this->automationEngine = $this->automationEngine ?? new AutomationEngine();
        $this->workspaceScope = new AIWorkspaceScopeService();
    }

    public function generate(array $input, ?int $userId = null): array
    {
        $prompt = trim((string) ($input['prompt'] ?? ''));
        $targetWorkflowId = !empty($input['target_workflow_id']) ? (int) $input['target_workflow_id'] : 0;
        $sourceSurface = trim((string) ($input['source_surface'] ?? 'workflow_generation')) ?: 'workflow_generation';

        if ($prompt === '') {
            return ['status' => 'blocked', 'error' => 'prompt is required', 'reasons' => ['missing_prompt'], 'issues' => []];
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $currentWorkflow = $targetWorkflowId > 0
            ? Database::queryOne("SELECT * FROM workflows WHERE workspace_id = ? AND id = ?", [$workspaceId, $targetWorkflowId])
            : null;
        if ($targetWorkflowId > 0 && !$currentWorkflow) {
            return ['status' => 'blocked', 'error' => 'workflow not found', 'reasons' => ['workflow_not_found'], 'issues' => []];
        }
        $currentGraph = $currentWorkflow ? $this->graphService->loadGraphFromWorkflowRow($currentWorkflow) : [];
        $aiPayload = $this->generateGraphPayload($prompt, $currentWorkflow ?: []);
        $graph = $this->normalizeCandidateGraph((array) ($aiPayload['graph'] ?? []), $currentWorkflow ?: [], $prompt);
        $validation = $this->graphService->validateGraph($graph);
        $legacyPayload = $this->graphService->deriveLegacyPayload($graph);
        $legacyPayload['is_active'] = !array_key_exists('is_active', $input) || !empty($input['is_active']);
        $actionSummary = $this->buildActionSummary($legacyPayload);
        $riskSummary = $this->buildRiskSummary($actionSummary);
        $diffSummary = $this->buildDiffSummary($currentGraph, $graph);
        $confidence = $this->scoreConfidence($legacyPayload, $validation, !empty($aiPayload['fallback_used']), $prompt);
        $settings = $this->controls->getWorkspaceControl();
        $mode = (string) ($settings['autonomy_mode'] ?? 'suggest_only');
        $decision = $this->evaluateDeploymentDecision($legacyPayload, $confidence, $mode);

        $proposalPayload = [
            'workflow_id' => $targetWorkflowId > 0 ? $targetWorkflowId : null,
            'proposal_type' => $targetWorkflowId > 0 ? 'update' : 'create',
            'source_surface' => $sourceSurface,
            'requested_by_type' => 'ai',
            'requested_by_id' => $userId,
            'target_workflow_name' => $this->resolveWorkflowName($graph, $currentWorkflow ?: [], $prompt),
            'prompt_text' => $prompt,
            'decision_mode' => $mode,
            'governance_decision' => $decision['governance_decision'] ?? null,
            'confidence_score' => $confidence,
            'proposal_graph' => $graph,
            'current_graph' => $currentGraph,
            'legacy_payload' => $legacyPayload,
            'validation_issues' => $validation['issues'] ?? [],
            'risk_summary' => $riskSummary,
            'action_summary' => $actionSummary,
            'diff_summary' => $diffSummary,
            'decision_snapshot' => $decision,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];

        if (!$validation['valid']) {
            return [
                'status' => 'blocked',
                'proposal_id' => null,
                'workflow_id' => null,
                'confidence' => $confidence,
                'reasons' => ['invalid_graph'],
                'issues' => $validation['issues'],
                'graph' => $graph,
            ];
        }

        if ($mode === 'suggest_only') {
            return [
                'status' => 'suggested',
                'proposal_id' => null,
                'workflow_id' => null,
                'confidence' => $confidence,
                'reasons' => array_values(array_unique((array) ($decision['reasons'] ?? ['suggest_only_mode']))),
                'issues' => $validation['issues'],
                'graph' => $graph,
            ];
        }

        if (($decision['status'] ?? '') === 'blocked') {
            return [
                'status' => 'blocked',
                'proposal_id' => null,
                'workflow_id' => null,
                'confidence' => $confidence,
                'reasons' => array_values(array_unique((array) ($decision['reasons'] ?? ['workflow_generation_blocked']))),
                'issues' => $validation['issues'],
                'graph' => $graph,
            ];
        }

        if ($mode === 'auto_safe' || ($decision['status'] ?? '') === 'pending_approval') {
            $proposalId = $this->proposals->createProposal($proposalPayload);
            return [
                'status' => 'pending_approval',
                'proposal_id' => $proposalId,
                'workflow_id' => null,
                'confidence' => $confidence,
                'reasons' => array_values(array_unique((array) ($decision['reasons'] ?? ['approval_required']))),
                'issues' => $validation['issues'],
                'graph' => $graph,
            ];
        }

        $proposalId = $this->proposals->createProposal($proposalPayload + ['status' => 'approved']);
        $applied = $this->proposals->applyProposal($proposalId, $userId ?? 0, true);

        return [
            'status' => 'applied',
            'proposal_id' => $proposalId,
            'workflow_id' => (int) ($applied['workflow_id'] ?? 0) ?: null,
            'confidence' => $confidence,
            'reasons' => array_values(array_unique((array) ($decision['reasons'] ?? []))),
            'issues' => $validation['issues'],
            'graph' => $graph,
        ];
    }

    private function generateGraphPayload(string $prompt, array $currentWorkflow = []): array
    {
        try {
            $response = $this->ai->process('workflow_generation', [
                'prompt' => $prompt,
                'text' => $prompt,
                'current_workflow' => [
                    'name' => (string) ($currentWorkflow['name'] ?? ''),
                    'trigger_config' => is_string($currentWorkflow['trigger_config'] ?? null)
                        ? json_decode((string) $currentWorkflow['trigger_config'], true)
                        : (array) ($currentWorkflow['trigger_config'] ?? []),
                    'actions' => is_string($currentWorkflow['actions'] ?? null)
                        ? json_decode((string) $currentWorkflow['actions'], true)
                        : (array) ($currentWorkflow['actions'] ?? []),
                ],
                'available_triggers' => $this->automationEngine->getTriggers(),
                'available_actions' => $this->automationEngine->getActions(),
            ]);

            $decoded = json_decode(trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $response)), true);
            if (is_array($decoded) && !empty($decoded['graph'])) {
                return ['graph' => $decoded['graph'], 'fallback_used' => false];
            }
        } catch (\Throwable $e) {
        }

        return ['graph' => $this->buildFallbackGraph($prompt, $currentWorkflow), 'fallback_used' => true];
    }

    private function buildFallbackGraph(string $prompt, array $currentWorkflow = []): array
    {
        $lower = strtolower($prompt);
        $trigger = 'contact_created';
        if (str_contains($lower, 'form')) {
            $trigger = 'form_submitted';
        } elseif (str_contains($lower, 'deal')) {
            $trigger = 'deal_stage_changed';
        } elseif (str_contains($lower, 'email received') || str_contains($lower, 'incoming email')) {
            $trigger = 'email_received';
        } elseif (str_contains($lower, 'task')) {
            $trigger = 'task_completed';
        } elseif (str_contains($lower, 'webhook')) {
            $trigger = 'webhook_received';
        }

        $actions = [];
        if (str_contains($lower, 'email')) {
            $actions[] = ['type' => 'send_email', 'subject' => 'Automated follow-up', 'body' => 'Thanks for your interest. We will follow up shortly.'];
        }
        if (str_contains($lower, 'whatsapp')) {
            $actions[] = ['type' => 'send_whatsapp', 'message' => 'Thanks for reaching out. We will follow up shortly.'];
        }
        if (str_contains($lower, 'task')) {
            $actions[] = ['type' => 'create_task', 'title' => 'Follow up on workflow event', 'description' => 'Review the latest workflow trigger and follow up.'];
        }
        if (str_contains($lower, 'tag')) {
            $actions[] = ['type' => 'add_tag', 'tag_name' => 'workflow-generated'];
        }
        if (str_contains($lower, 'stage')) {
            $actions[] = ['type' => 'change_stage', 'stage' => 'contacted'];
        }
        if ($actions === []) {
            $actions[] = ['type' => 'create_task', 'title' => 'Review new workflow trigger', 'description' => 'Check the triggered contact and follow up.'];
        }

        $nodes = [[
            'id' => 'trigger_1',
            'type' => 'trigger',
            'subtype' => $trigger,
            'position' => ['x' => 120, 'y' => 140],
            'config' => ['type' => $trigger],
        ]];
        $edges = [];
        $previousId = 'trigger_1';

        foreach ($actions as $index => $action) {
            $nodeType = $action['type'] === 'wait_for_days' ? 'delay' : 'action';
            $nodeId = ($nodeType === 'delay' ? 'delay_' : 'action_') . ($index + 1);
            $nodes[] = [
                'id' => $nodeId,
                'type' => $nodeType,
                'subtype' => $nodeType === 'delay' ? 'wait_for_days' : $action['type'],
                'position' => ['x' => 440, 'y' => 140 + (($index + 1) * 140)],
                'config' => $action,
            ];
            $edges[] = [
                'id' => 'edge_' . ($index + 1),
                'source' => $previousId,
                'target' => $nodeId,
                'branch' => 'default',
                'order' => $index,
            ];
            $previousId = $nodeId;
        }

        return [
            'version' => 2,
            'meta' => [
                'name' => $this->resolveWorkflowName([], $currentWorkflow, $prompt),
                'mode' => 'mixed',
            ],
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    private function normalizeCandidateGraph(array $graph, array $currentWorkflow, string $prompt): array
    {
        if ($graph === []) {
            $graph = $this->buildFallbackGraph($prompt, $currentWorkflow);
        }
        $graph['meta']['name'] = $this->resolveWorkflowName($graph, $currentWorkflow, $prompt);
        $graph['meta']['mode'] = in_array((string) ($graph['meta']['mode'] ?? 'mixed'), ['crm', 'journey', 'mixed'], true)
            ? (string) $graph['meta']['mode']
            : 'mixed';

        return $this->graphService->loadGraphFromWorkflowRow([
            'name' => $graph['meta']['name'],
            'workflow_mode' => $graph['meta']['mode'],
            'graph_json' => json_encode($graph),
        ]);
    }

    private function resolveWorkflowName(array $graph, array $currentWorkflow, string $prompt): string
    {
        $name = trim((string) ($graph['meta']['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        if (!empty($currentWorkflow['name'])) {
            return (string) $currentWorkflow['name'];
        }
        $words = preg_split('/\s+/', trim($prompt)) ?: [];
        $slice = array_slice($words, 0, 6);
        return $slice !== [] ? ucfirst(strtolower(implode(' ', $slice))) : 'Workflow automation';
    }

    private function buildActionSummary(array $legacyPayload): array
    {
        $actions = (array) ($legacyPayload['actions'] ?? []);
        $types = array_values(array_filter(array_map(static fn(array $action): string => (string) ($action['type'] ?? ''), $actions)));
        $customerFacingTypes = array_values(array_filter($types, static fn(string $type): bool => in_array($type, ['send_email', 'send_whatsapp', 'send_sms'], true)));
        $highRiskTypes = array_values(array_filter($types, static fn(string $type): bool => in_array($type, ['call_webhook', 'update_deal_stage', 'create_deal'], true)));

        return [
            'trigger_type' => (string) (($legacyPayload['trigger']['type'] ?? '')),
            'action_count' => count($actions),
            'action_types' => array_values(array_unique($types)),
            'customer_facing_types' => array_values(array_unique($customerFacingTypes)),
            'high_risk_types' => array_values(array_unique($highRiskTypes)),
        ];
    }

    private function buildRiskSummary(array $actionSummary): array
    {
        return [
            'customer_facing' => !empty($actionSummary['customer_facing_types']),
            'high_risk' => !empty($actionSummary['high_risk_types']),
            'customer_facing_types' => $actionSummary['customer_facing_types'] ?? [],
            'high_risk_types' => $actionSummary['high_risk_types'] ?? [],
        ];
    }

    private function buildDiffSummary(array $currentGraph, array $proposedGraph): array
    {
        $currentActionTypes = $this->actionTypesFromGraph($currentGraph);
        $proposedActionTypes = $this->actionTypesFromGraph($proposedGraph);

        return [
            'current_node_count' => count((array) ($currentGraph['nodes'] ?? [])),
            'proposed_node_count' => count((array) ($proposedGraph['nodes'] ?? [])),
            'current_edge_count' => count((array) ($currentGraph['edges'] ?? [])),
            'proposed_edge_count' => count((array) ($proposedGraph['edges'] ?? [])),
            'added_action_types' => array_values(array_diff($proposedActionTypes, $currentActionTypes)),
            'removed_action_types' => array_values(array_diff($currentActionTypes, $proposedActionTypes)),
            'changed' => $currentGraph !== [] ? ($currentGraph != $proposedGraph) : true,
        ];
    }

    private function actionTypesFromGraph(array $graph): array
    {
        $types = [];
        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            if (($node['type'] ?? '') === 'action') {
                $types[] = (string) ($node['subtype'] ?? '');
            } elseif (($node['type'] ?? '') === 'delay') {
                $types[] = 'wait_for_days';
            }
        }
        return array_values(array_unique(array_filter($types)));
    }

    private function scoreConfidence(array $legacyPayload, array $validation, bool $fallbackUsed, string $prompt): float
    {
        $score = 0.70;
        if (!empty($validation['valid'])) {
            $score += 0.12;
        }
        $actionCount = count((array) ($legacyPayload['actions'] ?? []));
        if ($actionCount >= 1 && $actionCount <= 4) {
            $score += 0.05;
        }
        if (strlen(trim($prompt)) >= 30) {
            $score += 0.03;
        }
        if (!$fallbackUsed) {
            $score += 0.05;
        }
        foreach ((array) ($legacyPayload['actions'] ?? []) as $action) {
            $type = (string) ($action['type'] ?? '');
            if (in_array($type, ['send_email', 'send_whatsapp', 'send_sms'], true)) {
                $score -= 0.05;
            }
            if (in_array($type, ['call_webhook', 'update_deal_stage', 'create_deal'], true)) {
                $score -= 0.04;
            }
            if ($type === 'send_email' && (trim((string) ($action['subject'] ?? '')) === '' || trim((string) ($action['body'] ?? '')) === '')) {
                $score -= 0.06;
            }
            if ($type === 'create_task' && trim((string) ($action['title'] ?? '')) === '') {
                $score -= 0.04;
            }
        }

        return max(0.50, min(0.99, round($score, 4)));
    }

    private function evaluateDeploymentDecision(array $legacyPayload, float $confidence, string $mode): array
    {
        $reasons = [];
        $status = $mode === 'suggest_only' ? 'suggested' : 'auto_apply';
        $governanceDecision = 'allow';

        if ($mode === 'suggest_only') {
            return ['status' => 'suggested', 'reasons' => ['suggest_only_mode'], 'governance_decision' => 'suggest_only'];
        }

        foreach ((array) ($legacyPayload['actions'] ?? []) as $action) {
            $actionType = (string) ($action['type'] ?? '');
            if ($actionType === '') {
                continue;
            }
            $threshold = (float) (self::ACTION_THRESHOLDS[$actionType] ?? 0.84);
            $policyDecision = $confidence >= $threshold
                ? ['decision' => 'auto_apply', 'reasons' => [], 'threshold' => $threshold]
                : ['decision' => 'approval_required', 'reasons' => ['confidence_below_threshold'], 'threshold' => $threshold];

            $context = [
                'assistant_confidence' => $confidence,
                'requires_customer_send' => in_array($actionType, ['send_email', 'send_whatsapp', 'send_sms'], true),
                'workflow_customer_facing' => in_array($actionType, ['send_email', 'send_whatsapp', 'send_sms'], true),
                'recipient' => in_array($actionType, ['send_email', 'send_whatsapp', 'send_sms'], true) ? 'dynamic_contact' : '',
                'action_config' => $action,
            ];
            $governance = $this->governance->evaluate(
                $this->workspaceScope->currentTenantKey(),
                WorkflowAutomationControlService::DOMAIN_KEY,
                $actionType,
                $context,
                $policyDecision
            );

            if (($governance['decision'] ?? 'allow') === 'blocked') {
                $status = 'blocked';
                $governanceDecision = 'blocked';
                $reasons = array_merge($reasons, (array) ($governance['reason_codes'] ?? ['workflow_generation_blocked']));
                break;
            }

            if (($governance['decision'] ?? 'allow') === 'approval_required' || $policyDecision['decision'] !== 'auto_apply') {
                $status = 'pending_approval';
                $governanceDecision = 'approval_required';
                $reasons = array_merge($reasons, (array) ($governance['reason_codes'] ?? []), (array) ($policyDecision['reasons'] ?? []));
            }
        }

        if ($mode === 'auto_safe' && $status !== 'blocked') {
            $status = 'pending_approval';
            $governanceDecision = $governanceDecision === 'allow' ? 'approval_required' : $governanceDecision;
            $reasons[] = 'auto_safe_requires_approval';
        }

        return [
            'status' => $status,
            'reasons' => array_values(array_unique(array_filter($reasons))),
            'governance_decision' => $governanceDecision,
        ];
    }
}
