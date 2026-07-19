<?php

namespace CRM\Services;

use CRM\Modules\Tasks;

class TaskFollowthroughAutonomyService
{
    private const ACTION_THRESHOLDS = [
        'mark_complete' => 0.94,
        'create_followup_task' => 0.84,
        'escalate_task' => 0.82,
    ];

    private Tasks $tasks;
    private AITaskCompletionService $completion;
    private AIAutonomyScoringService $scoring;
    private AIAutonomyGovernanceService $governance;
    private AIAutonomyDomainControlService $controls;
    private AIDemonstrationCaptureService $capture;
    private TaskAssignmentAccessService $assignmentAccess;
    private AIWorkspaceScopeService $aiWorkspaceScope;
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->tasks = new Tasks();
        $this->completion = new AITaskCompletionService();
        $this->scoring = new AIAutonomyScoringService();
        $this->governance = new AIAutonomyGovernanceService();
        $this->controls = new AIAutonomyDomainControlService();
        $this->capture = new AIDemonstrationCaptureService();
        $this->assignmentAccess = new TaskAssignmentAccessService();
        $this->aiWorkspaceScope = new AIWorkspaceScopeService();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function runForTask(int $taskId, string $trigger = 'manual', ?int $actorUserId = null): ?array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $task = $this->tasks->getById($taskId);
        if (!$task) {
            return null;
        }
        $tenantKey = $this->aiWorkspaceScope->workspaceTenantKey((int) ($task['workspace_id'] ?? $workspaceId));
        $domainControl = $this->controls->get($tenantKey, 'task_followthrough');
        $actions = $this->plan($task);
        $results = [];
        $finalDecision = 'suggest_only';

        foreach ($actions as $action) {
            $context = [
                'task' => $task,
                'surface' => 'task_followthrough',
                'domain_key' => 'task_followthrough',
                'task_status' => (string) ($task['status'] ?? ''),
                'task_priority' => (string) ($task['priority'] ?? ''),
                'staleness_days' => $this->stalenessDays((string) ($task['due_date'] ?? '')),
                'task_evidence_strength' => (float) ($action['evidence_strength'] ?? 0.0),
                'assistant_confidence' => (float) ($action['heuristic_confidence'] ?? 0.8),
                'trigger_type' => $trigger,
                'domain_control' => $domainControl,
            ];
            $score = $this->scoring->score($tenantKey, 'task_followthrough', (string) $action['action'], array_merge($context, [
                'heuristic_confidence' => (float) ($action['heuristic_confidence'] ?? 0.8),
            ]));
            $context['assistant_confidence'] = (float) ($score['assistant_confidence'] ?? 0.0);
            $context['confidence_basis'] = (array) ($score['confidence_basis'] ?? []);
            $policy = $this->evaluateActionPolicy((string) $action['action'], $context);
            $governance = $this->governance->evaluate($tenantKey, 'task_followthrough', (string) $action['action'], $context, $policy);
            $decision = $this->normalizeDecision($policy, $governance);

            if (($decision['decision'] ?? '') === 'auto_apply') {
                $result = $this->executeAction($task, $action, $actorUserId);
                $result['policy'] = $decision;
                $results[] = $result;
                $this->captureExecution($tenantKey, $task, $action, $context, $result, $actorUserId);
                $finalDecision = 'auto_apply';
            } else {
                $results[] = [
                    'action' => $action['action'],
                    'status' => ($decision['decision'] ?? '') === 'approval_required' ? 'approval_required' : 'blocked',
                    'policy' => $decision,
                ];
                if ($finalDecision !== 'auto_apply') {
                    $finalDecision = ($decision['decision'] ?? '') === 'approval_required' ? 'approval_required' : 'suggest_only';
                }
            }
        }

        $response = ['decision' => $finalDecision, 'actions' => $results];
        if ($trigger !== 'cross_domain') {
            try {
                (new AICrossDomainEventIntakeService())->processEvent([
                    'tenant_key' => $tenantKey,
                    'source_domain' => 'task_followthrough',
                    'trigger_key' => $trigger === 'task_completed' ? 'task_completed' : 'task_milestone',
                    'trigger_entity_type' => 'task',
                    'trigger_entity_id' => $taskId,
                    'related_entity_ids' => [
                        'task_id' => $taskId,
                        'contact_id' => !empty($task['contact_id']) ? (int) $task['contact_id'] : null,
                    ],
                    'metadata' => [
                        'decision' => $finalDecision,
                        'task_status' => (string) ($task['status'] ?? ''),
                        'actions' => array_column($results, 'action'),
                    ],
                ], $actorUserId);
            } catch (\Throwable $e) {
                error_log('TaskFollowthroughAutonomyService orchestration intake failed: ' . $e->getMessage());
            }
        }

        return $response;
    }

    public function plan(array $task): array
    {
        $actions = [];
        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        $evidence = $this->completion->findMatchingEvidence($task);
        if ($evidence !== [] && $this->completion->shouldAutoComplete($task, $evidence, [])) {
            $actions[] = [
                'action' => 'mark_complete',
                'heuristic_confidence' => (float) ($evidence['confidence_score'] ?? 0.95),
                'evidence_strength' => (float) ($evidence['confidence_score'] ?? 0.95),
                'evidence' => $evidence,
            ];
        }

        if (($task['status'] ?? '') === 'completed' && !empty($metadata['next_action_title'])) {
            $actions[] = [
                'action' => 'create_followup_task',
                'heuristic_confidence' => 0.86,
                'next_action_title' => (string) $metadata['next_action_title'],
                'evidence_strength' => 0.82,
            ];
        }

        if (in_array((string) ($task['status'] ?? ''), ['pending', 'in_progress'], true) && $this->stalenessDays((string) ($task['due_date'] ?? '')) >= 2) {
            $actions[] = [
                'action' => 'escalate_task',
                'heuristic_confidence' => 0.83,
                'evidence_strength' => 0.76,
            ];
        }

        return $actions;
    }

    private function executeAction(array $task, array $action, ?int $actorUserId): array
    {
        $actionKey = (string) ($action['action'] ?? '');
        if ($actionKey === 'mark_complete') {
            $evidence = (array) ($action['evidence'] ?? []);
            $completed = $this->completion->autoCompleteTask((int) $task['id'], $evidence);
            return ['action' => $actionKey, 'status' => $completed ? 'completed' : 'failed', 'task_id' => (int) $task['id']];
        }
        if ($actionKey === 'create_followup_task') {
            $hasContact = !empty($task['contact_id']);
            $nextTitle = (string) ($action['next_action_title'] ?? ('Next step for ' . ($task['title'] ?? 'task')));
            $assignment = $this->assignmentAccess->resolveAiAssignee([
                (int) ($task['assigned_to'] ?? 0),
                (int) ($task['created_by'] ?? 0),
                (int) ($actorUserId ?? 0),
            ], [
                'title' => $nextTitle,
                'description' => 'Autonomous follow-up task created from completed task evidence.',
                'metadata_json' => [
                    'source_surface' => 'task_followthrough',
                    'task_intent' => 'follow_up',
                ],
            ]);
            $taskId = $this->tasks->create([
                'title' => $nextTitle,
                'description' => 'Autonomous follow-up task created from completed task evidence.',
                'contact_id' => !empty($task['contact_id']) ? (int) $task['contact_id'] : null,
                'assigned_to' => $assignment['assigned_to'],
                'assignment_mode' => 'ai',
                'created_by' => !empty($task['created_by']) ? (int) $task['created_by'] : ($actorUserId ?? 0),
                'status' => 'pending',
                'priority' => 'medium',
                'completion_mode' => $hasContact ? 'auto' : 'review',
                'automation_dedupe_key' => 'task_followthrough:' . (int) $task['id'] . ':' . hash('sha256', strtolower(trim($nextTitle))),
                'metadata_json' => [
                    'source_surface' => 'task_followthrough',
                    'origin_task_id' => (int) $task['id'],
                    'completion_evidence_types' => $hasContact ? ['email_reply_received'] : [],
                    'auto_complete_allowed' => $hasContact,
                    'protected_from_auto_complete' => !$hasContact,
                    'task_intent' => 'follow_up',
                    'assignment_resolution' => $assignment,
                ],
            ]);
            return ['action' => $actionKey, 'status' => 'created_task', 'task_id' => $taskId];
        }
        if ($actionKey === 'escalate_task') {
            $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
            $metadata['escalated_by_autonomy'] = true;
            $this->tasks->update((int) $task['id'], [
                'priority' => 'urgent',
                'metadata_json' => $metadata,
            ]);
            return ['action' => $actionKey, 'status' => 'escalated', 'task_id' => (int) $task['id']];
        }

        return ['action' => $actionKey, 'status' => 'skipped', 'task_id' => (int) $task['id']];
    }

    private function captureExecution(string $tenantKey, array $task, array $action, array $context, array $result, ?int $actorUserId): void
    {
        $this->capture->capture([
            'tenant_key' => $tenantKey,
            'actor_user_id' => $actorUserId,
            'actor_type' => 'system',
            'source_surface' => 'task_followthrough',
            'domain_key' => 'task_followthrough',
            'entity_type' => 'task',
            'entity_id' => (int) $task['id'],
            'linked_task_id' => (int) $task['id'],
            'action_key' => (string) $action['action'],
            'prior_state' => ['status' => $task['status'] ?? null, 'priority' => $task['priority'] ?? null],
            'action_payload' => $action,
            'outcome_state' => $result,
            'outcome_label' => in_array((string) ($result['status'] ?? ''), ['completed', 'created_task', 'escalated'], true) ? 'accepted' : 'observed',
            'metadata' => [
                'assistant_confidence' => $context['assistant_confidence'] ?? null,
                'confidence_basis' => $context['confidence_basis'] ?? [],
                'task_status' => $task['status'] ?? null,
            ],
            'was_successful' => in_array((string) ($result['status'] ?? ''), ['completed', 'created_task', 'escalated'], true),
        ]);
    }

    private function evaluateActionPolicy(string $actionKey, array $context): array
    {
        $threshold = (float) (self::ACTION_THRESHOLDS[$actionKey] ?? 0.85);
        $mode = (string) (($context['domain_control']['autonomy_mode'] ?? 'auto_safe'));
        $reasons = [];
        if (($context['assistant_confidence'] ?? 0) < $threshold) {
            $reasons[] = 'confidence_below_threshold';
        }
        if ($actionKey === 'mark_complete' && ($context['task_evidence_strength'] ?? 0) < 0.9) {
            $reasons[] = 'insufficient_completion_evidence';
        }
        if ($mode === 'suggest_only') {
            return ['decision' => 'suggest_only', 'reasons' => ['suggest_only_mode'], 'threshold' => $threshold];
        }
        if ($reasons !== []) {
            return ['decision' => $mode === 'full_auto' ? 'reject' : 'approval_required', 'reasons' => $reasons, 'threshold' => $threshold];
        }
        return ['decision' => 'auto_apply', 'reasons' => [], 'threshold' => $threshold];
    }

    private function normalizeDecision(array $policy, array $governance): array
    {
        $policy['envelope_decision'] = $governance['decision'] ?? 'allow';
        $policy['promotion_gate_status'] = $governance['promotion'] ?? [];
        $policy['drift_status'] = $governance['drift'] ?? [];
        $policy['incident_id'] = $governance['incident_id'] ?? null;
        if (($governance['decision'] ?? 'allow') === 'allow') {
            return $policy;
        }
        return [
            'decision' => ($governance['decision'] ?? '') === 'approval_required' ? 'approval_required' : 'reject',
            'reasons' => (array) ($governance['reason_codes'] ?? ['governance_blocked']),
            'threshold' => $policy['threshold'] ?? null,
            'envelope_decision' => $governance['decision'] ?? 'blocked',
            'promotion_gate_status' => $governance['promotion'] ?? [],
            'drift_status' => $governance['drift'] ?? [],
            'incident_id' => $governance['incident_id'] ?? null,
        ];
    }

    private function stalenessDays(string $dueDate): int
    {
        if ($dueDate === '') {
            return 0;
        }
        $timestamp = strtotime($dueDate);
        return $timestamp ? max(0, (int) floor((time() - $timestamp) / 86400)) : 0;
    }

    private function decodeMetadata($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
