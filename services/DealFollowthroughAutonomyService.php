<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Tasks;

class DealFollowthroughAutonomyService
{
    private const ACTION_THRESHOLDS = [
        'progress_stage' => 0.84,
        'reopen_deal' => 0.9,
        'assign_followup_task' => 0.82,
        'schedule_check_in' => 0.8,
    ];

    private DealAutomationEvidenceBuilder $evidenceBuilder;
    private AIAutonomyScoringService $scoring;
    private AIAutonomyGovernanceService $governance;
    private AIAutonomyDomainControlService $controls;
    private AIDemonstrationCaptureService $capture;
    private Tasks $tasks;
    private TaskAssignmentAccessService $assignmentAccess;
    private AIWorkspaceScopeService $aiWorkspaceScope;
    private WorkspaceScopeService $workspaceScope;
    /** @var array<string, bool> */
    private static array $activeRuns = [];

    public function __construct()
    {
        $this->evidenceBuilder = new DealAutomationEvidenceBuilder();
        $this->scoring = new AIAutonomyScoringService();
        $this->governance = new AIAutonomyGovernanceService();
        $this->controls = new AIAutonomyDomainControlService();
        $this->capture = new AIDemonstrationCaptureService();
        $this->tasks = new Tasks();
        $this->assignmentAccess = new TaskAssignmentAccessService();
        $this->aiWorkspaceScope = new AIWorkspaceScopeService();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function runForDeal(int $dealId, string $trigger = 'manual', ?int $actorUserId = null): ?array
    {
        $runKey = $dealId . ':' . $trigger;
        if (isset(self::$activeRuns[$runKey])) {
            return [
                'decision' => 'skipped',
                'actions' => [],
                'reason' => 'reentrant_deal_followthrough_guard',
            ];
        }

        self::$activeRuns[$runKey] = true;

        try {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $deal = Database::queryOne(
            "SELECT * FROM deals WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $dealId]
        );
        if (!$deal) {
            return null;
        }

        $tenantKey = $this->aiWorkspaceScope->workspaceTenantKey((int) ($deal['workspace_id'] ?? $workspaceId));
        $domainControl = $this->controls->get($tenantKey, 'deal_followthrough');
        $evidence = $this->evidenceBuilder->buildForDeal($dealId, 14);
        $actions = $this->plan($deal, $evidence);
        $results = [];
        $finalDecision = 'suggest_only';

        foreach ($actions as $action) {
            $context = [
                'deal' => $deal,
                'evidence' => $evidence,
                'surface' => 'deal_followthrough',
                'domain_key' => 'deal_followthrough',
                'trigger_type' => $trigger,
                'stage_evidence_quality' => $this->stageEvidenceQuality($deal, $evidence),
                'deal_stage' => (string) ($deal['stage'] ?? ''),
                'staleness_days' => $this->stalenessDays((string) ($evidence['last_activity_at'] ?? '')),
                'domain_control' => $domainControl,
            ];
            $score = $this->scoring->score($tenantKey, 'deal_followthrough', (string) $action['action'], array_merge($context, [
                'heuristic_confidence' => (float) ($action['heuristic_confidence'] ?? 0.8),
            ]));
            $context['assistant_confidence'] = (float) ($score['assistant_confidence'] ?? 0.0);
            $context['confidence_basis'] = (array) ($score['confidence_basis'] ?? []);
            $context['similar_examples'] = (array) ($score['similar_examples'] ?? []);
            $context['learned_signals'] = (array) ($score['tenant_policy'] ?? []);

            $policyDecision = $this->evaluateActionPolicy((string) $action['action'], $context);
            $governance = $this->governance->evaluate($tenantKey, 'deal_followthrough', (string) $action['action'], $context, $policyDecision);
            $decision = $this->normalizeDecision($policyDecision, $governance);

            if ($decision['decision'] === 'auto_apply') {
                $result = $this->executeAction($deal, $action, $actorUserId);
                $result['policy'] = $decision;
                $results[] = $result;
                $this->captureExecution($tenantKey, $deal, $action, $context, $result, $actorUserId);
                $finalDecision = 'auto_apply';
            } else {
                $results[] = [
                    'action' => $action['action'],
                    'status' => $decision['decision'] === 'approval_required' ? 'approval_required' : 'blocked',
                    'policy' => $decision,
                ];
                if ($finalDecision !== 'auto_apply') {
                    $finalDecision = $decision['decision'] === 'approval_required' ? 'approval_required' : 'suggest_only';
                }
            }
        }

        $response = ['decision' => $finalDecision, 'actions' => $results];
        if ($trigger !== 'cross_domain') {
            try {
                (new AICrossDomainEventIntakeService())->processEvent([
                    'tenant_key' => $tenantKey,
                    'source_domain' => 'deal_followthrough',
                    'trigger_key' => $trigger === 'stage_change' ? 'stage_change' : (($finalDecision === 'auto_apply') ? 'deal_milestone' : 'deal_progression_stalled'),
                    'trigger_entity_type' => 'deal',
                    'trigger_entity_id' => $dealId,
                    'related_entity_ids' => [
                        'deal_id' => $dealId,
                        'contact_id' => !empty($deal['contact_id']) ? (int) $deal['contact_id'] : null,
                    ],
                    'metadata' => [
                        'decision' => $finalDecision,
                        'actions' => array_column($results, 'action'),
                    ],
                ], $actorUserId);
            } catch (\Throwable $e) {
                error_log('DealFollowthroughAutonomyService orchestration intake failed: ' . $e->getMessage());
            }
        }

        return $response;
        } finally {
            unset(self::$activeRuns[$runKey]);
        }
    }

    public function plan(array $deal, array $evidence): array
    {
        $actions = [];
        $stage = (string) ($deal['stage'] ?? '');
        $stalenessDays = $this->stalenessDays((string) ($evidence['last_activity_at'] ?? ''));

        if ($stage === 'qualification' && (!empty($evidence['proposal_sent']) || !empty($evidence['quote_created']))) {
            $actions[] = ['action' => 'progress_stage', 'to_stage' => 'proposal', 'heuristic_confidence' => 0.89];
        }
        if ($stage === 'closed_lost' && !empty($evidence['communications'])) {
            foreach ((array) $evidence['communications'] as $communication) {
                if (($communication['direction'] ?? '') === 'inbound') {
                    $actions[] = ['action' => 'reopen_deal', 'to_stage' => 'qualification', 'heuristic_confidence' => 0.9];
                    break;
                }
            }
        }
        if (in_array($stage, ['proposal', 'negotiation'], true) && $stalenessDays >= 3) {
            $actions[] = ['action' => 'assign_followup_task', 'heuristic_confidence' => 0.84];
        }
        if ($stage === 'closed_won' && $stalenessDays >= 7) {
            $actions[] = ['action' => 'schedule_check_in', 'heuristic_confidence' => 0.81];
        }

        return $actions;
    }

    private function executeAction(array $deal, array $action, ?int $actorUserId): array
    {
        $actionKey = (string) ($action['action'] ?? '');
        if ($actionKey === 'progress_stage' || $actionKey === 'reopen_deal') {
            Database::execute("UPDATE deals SET stage = ?, updated_at = NOW() WHERE id = ?", [(string) $action['to_stage'], (int) $deal['id']]);
            return ['action' => $actionKey, 'status' => 'applied', 'deal_id' => (int) $deal['id'], 'to_stage' => (string) $action['to_stage']];
        }

        if ($actionKey === 'assign_followup_task') {
            $assignment = $this->assignmentAccess->resolveAiAssignee([
                (int) ($deal['assigned_to'] ?? 0),
                (int) ($deal['created_by'] ?? 0),
                (int) ($actorUserId ?? 0),
            ], [
                'title' => 'Deal follow-up: ' . (string) ($deal['title'] ?? 'Deal'),
                'description' => 'Follow up on the current deal stage and capture next-step outcome.',
                'metadata_json' => [
                    'source_surface' => 'deal_followthrough',
                    'task_intent' => 'follow_up',
                ],
            ]);
            $taskId = $this->tasks->create([
                'title' => 'Deal follow-up: ' . (string) ($deal['title'] ?? 'Deal'),
                'description' => 'Follow up on the current deal stage and capture next-step outcome.',
                'contact_id' => !empty($deal['contact_id']) ? (int) $deal['contact_id'] : null,
                'assigned_to' => $assignment['assigned_to'],
                'assignment_mode' => 'ai',
                'created_by' => !empty($deal['created_by']) ? (int) $deal['created_by'] : ($actorUserId ?? 0),
                'priority' => 'high',
                'status' => 'pending',
                'completion_mode' => !empty($deal['contact_id']) ? 'auto' : 'review',
                'automation_dedupe_key' => 'deal_followthrough:' . (int) $deal['id'] . ':assign_followup_task',
                'metadata_json' => [
                    'source_surface' => 'deal_followthrough',
                    'deal_id' => (int) $deal['id'],
                    'completion_evidence_types' => array_values(array_filter([
                        !empty($deal['contact_id']) ? 'email_reply_received' : null,
                        'deal_stage_reached',
                    ])),
                    'origin_deal_stage' => (string) ($deal['stage'] ?? ''),
                    'auto_complete_allowed' => !empty($deal['contact_id']),
                    'protected_from_auto_complete' => empty($deal['contact_id']),
                    'task_intent' => 'follow_up',
                    'assignment_resolution' => $assignment,
                ],
            ]);
            return ['action' => $actionKey, 'status' => 'created_task', 'task_id' => $taskId];
        }

        if ($actionKey === 'schedule_check_in') {
            $assignment = $this->assignmentAccess->resolveAiAssignee([
                (int) ($deal['assigned_to'] ?? 0),
                (int) ($deal['created_by'] ?? 0),
                (int) ($actorUserId ?? 0),
            ], [
                'title' => 'Closed-won check-in: ' . (string) ($deal['title'] ?? 'Deal'),
                'description' => 'Schedule a post-sale check-in and record outcome.',
                'metadata_json' => [
                    'source_surface' => 'deal_followthrough',
                    'task_intent' => 'follow_up',
                ],
            ]);
            $taskId = $this->tasks->create([
                'title' => 'Closed-won check-in: ' . (string) ($deal['title'] ?? 'Deal'),
                'description' => 'Schedule a post-sale check-in and record outcome.',
                'contact_id' => !empty($deal['contact_id']) ? (int) $deal['contact_id'] : null,
                'assigned_to' => $assignment['assigned_to'],
                'assignment_mode' => 'ai',
                'created_by' => !empty($deal['created_by']) ? (int) $deal['created_by'] : ($actorUserId ?? 0),
                'priority' => 'medium',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('+3 days')),
                'completion_mode' => 'review',
                'automation_dedupe_key' => 'deal_followthrough:' . (int) $deal['id'] . ':schedule_check_in',
                'metadata_json' => [
                    'source_surface' => 'deal_followthrough',
                    'deal_id' => (int) $deal['id'],
                    'completion_evidence_types' => ['completed_event', 'email_reply_received', 'note_created'],
                    'auto_complete_allowed' => false,
                    'protected_from_auto_complete' => true,
                    'task_intent' => 'check_in',
                    'assignment_resolution' => $assignment,
                ],
            ]);
            return ['action' => $actionKey, 'status' => 'created_task', 'task_id' => $taskId];
        }

        return ['action' => $actionKey, 'status' => 'skipped'];
    }

    private function captureExecution(string $tenantKey, array $deal, array $action, array $context, array $result, ?int $actorUserId): void
    {
        $this->capture->capture([
            'tenant_key' => $tenantKey,
            'actor_user_id' => $actorUserId,
            'actor_type' => 'system',
            'source_surface' => 'deal_followthrough',
            'domain_key' => 'deal_followthrough',
            'entity_type' => 'deal',
            'entity_id' => (int) $deal['id'],
            'action_key' => (string) $action['action'],
            'prior_state' => ['stage' => $deal['stage'] ?? null],
            'action_payload' => $action,
            'outcome_state' => $result,
            'outcome_label' => in_array((string) ($result['status'] ?? ''), ['applied', 'created_task'], true) ? 'accepted' : 'observed',
            'metadata' => [
                'deal_stage' => $deal['stage'] ?? null,
                'assistant_confidence' => $context['assistant_confidence'] ?? null,
                'confidence_basis' => $context['confidence_basis'] ?? [],
            ],
            'was_successful' => in_array((string) ($result['status'] ?? ''), ['applied', 'created_task'], true),
        ]);
    }

    private function evaluateActionPolicy(string $actionKey, array $context): array
    {
        $threshold = (float) (self::ACTION_THRESHOLDS[$actionKey] ?? 0.85);
        $mode = (string) (($context['domain_control']['autonomy_mode'] ?? $context['domain_control']['promotion_status'] ?? 'suggest_only'));
        $reasons = [];
        if (($context['assistant_confidence'] ?? 0) < $threshold) {
            $reasons[] = 'confidence_below_threshold';
        }
        if ($actionKey === 'progress_stage' && empty($context['evidence']['proposal_sent']) && empty($context['evidence']['quote_created'])) {
            $reasons[] = 'insufficient_stage_evidence';
        }
        if ($actionKey === 'reopen_deal' && empty($context['evidence']['communications'])) {
            $reasons[] = 'reopen_evidence_missing';
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

    private function stageEvidenceQuality(array $deal, array $evidence): float
    {
        $score = 0.58;
        if (!empty($evidence['proposal_sent']) || !empty($evidence['quote_created'])) {
            $score += 0.18;
        }
        if (!empty($evidence['bidirectional_exchange'])) {
            $score += 0.12;
        }
        if (!empty($evidence['quote_accepted'])) {
            $score += 0.1;
        }
        return max(0.0, min(1.0, round($score, 4)));
    }

    private function stalenessDays(string $lastActivityAt): int
    {
        if ($lastActivityAt === '') {
            return 999;
        }
        $timestamp = strtotime($lastActivityAt);
        return $timestamp ? max(0, (int) floor((time() - $timestamp) / 86400)) : 999;
    }
}
