<?php

namespace CRM\Services;

use CRM\Modules\Tasks;

class AIAdviceFollowThroughService
{
    private AIWorkspaceScopeService $aiWorkspaceScope;
    private WorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?Tasks $tasks = null,
        private ?AIAdviceFeedbackService $feedback = null,
        private ?AIDecisionOutcomeService $outcomes = null,
        private ?AIDemonstrationCaptureService $capture = null
    ) {
        $this->tasks = $this->tasks ?? new Tasks();
        $this->feedback = $this->feedback ?? new AIAdviceFeedbackService();
        $this->outcomes = $this->outcomes ?? new AIDecisionOutcomeService();
        $this->capture = $this->capture ?? new AIDemonstrationCaptureService();
        $this->aiWorkspaceScope = new AIWorkspaceScopeService();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function linkTaskToGuidanceRun(int $taskId, int $guidanceRunId, string $surface, array $meta = []): void
    {
        $this->linkTaskToSourceRun($taskId, array_merge($meta, [
            'surface' => $surface,
            'guidance_run_id' => $guidanceRunId,
        ]));
    }

    public function linkManualAction(array $payload): void
    {
        if (empty($payload['surface'])) {
            throw new \InvalidArgumentException('Surface is required.');
        }

        $workspaceId = $this->resolveWorkspaceId($payload);
        $surface = (string) $payload['surface'];
        if (!empty($payload['task_id'])) {
            $this->linkTaskToSourceRun((int) $payload['task_id'], $payload);
        }

        if (in_array($surface, ['coach', 'clarity_chat'], true) && !empty($payload['guidance_run_id'])) {
            $feedbackPayload = [
                'user_id' => (int) ($payload['user_id'] ?? 0),
                'surface' => $surface,
                'guidance_run_id' => (int) $payload['guidance_run_id'],
                'feedback_type' => 'acted_on',
                'linked_task_id' => !empty($payload['task_id']) ? (int) $payload['task_id'] : null,
                'linked_contact_id' => !empty($payload['linked_contact_id']) ? (int) $payload['linked_contact_id'] : null,
                'linked_deal_id' => !empty($payload['linked_deal_id']) ? (int) $payload['linked_deal_id'] : null,
                'metadata_json' => array_merge([
                    'linked_via' => !empty($payload['task_id']) ? 'task' : 'manual_followthrough',
                    'source_recommendation_type' => $payload['source_recommendation_type'] ?? null,
                ], (array) ($payload['metadata_json'] ?? [])),
            ];
            if (!empty($payload['recommendation_key'])) {
                $feedbackPayload['recommendation_key'] = (string) $payload['recommendation_key'];
            }
            if (!empty($payload['message_hash'])) {
                $feedbackPayload['message_hash'] = (string) $payload['message_hash'];
            }
            $this->feedback->recordFeedback($feedbackPayload);
            return;
        }

        $this->outcomes->recordManualFollowThroughOutcome([
            'workspace_id' => $workspaceId,
            'surface' => $surface === 'customer_thread' ? 'assistant' : $surface,
            'decision_type' => !empty($payload['decision_type']) ? (string) $payload['decision_type'] : ($surface === 'commercial_assistant' || $surface === 'commercial' ? 'action' : 'advice'),
            'action_type' => (string) ($payload['source_recommendation_type'] ?? 'manual_followthrough'),
            'guidance_run_id' => !empty($payload['guidance_run_id']) ? (int) $payload['guidance_run_id'] : null,
            'assistant_run_id' => !empty($payload['assistant_run_id']) ? (int) $payload['assistant_run_id'] : null,
            'commercial_run_id' => !empty($payload['commercial_run_id']) ? (int) $payload['commercial_run_id'] : null,
            'approval_id' => !empty($payload['approval_id']) ? (int) $payload['approval_id'] : null,
            'task_id' => !empty($payload['task_id']) ? (int) $payload['task_id'] : null,
            'user_id' => !empty($payload['user_id']) ? (int) $payload['user_id'] : null,
            'linked_contact_id' => !empty($payload['linked_contact_id']) ? (int) $payload['linked_contact_id'] : null,
            'linked_deal_id' => !empty($payload['linked_deal_id']) ? (int) $payload['linked_deal_id'] : null,
            'linked_invoice_id' => !empty($payload['linked_invoice_id']) ? (int) $payload['linked_invoice_id'] : null,
            'recommendation_key' => $payload['recommendation_key'] ?? null,
            'message_hash' => $payload['message_hash'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'metadata' => [
                'linked_via' => !empty($payload['task_id']) ? 'task' : 'manual_followthrough',
                'source_recommendation_type' => $payload['source_recommendation_type'] ?? null,
            ],
        ]);
        $this->capture->capture([
            'tenant_key' => $this->aiWorkspaceScope->workspaceTenantKey($workspaceId),
            'workspace_id' => $workspaceId,
            'actor_user_id' => !empty($payload['user_id']) ? (int) $payload['user_id'] : null,
            'actor_type' => 'user',
            'source_surface' => $surface,
            'domain_key' => in_array($surface, ['assistant', 'customer_thread'], true) ? 'customer_thread' : 'task_followthrough',
            'entity_type' => !empty($payload['task_id']) ? 'task' : 'manual_followthrough',
            'entity_id' => !empty($payload['task_id']) ? (int) $payload['task_id'] : null,
            'action_key' => (string) ($payload['source_recommendation_type'] ?? 'manual_followthrough'),
            'prior_state' => ['payload' => $payload],
            'action_payload' => ['surface' => $surface],
            'outcome_state' => ['outcome_label' => 'accepted'],
            'outcome_label' => 'accepted',
            'linked_task_id' => !empty($payload['task_id']) ? (int) $payload['task_id'] : null,
            'was_successful' => true,
        ]);
    }

    public function linkTaskToSourceRun(int $taskId, array $payload): void
    {
        $task = $this->tasks->getById($taskId);
        if (!$task) {
            throw new \InvalidArgumentException('Task not found.');
        }

        $metadata = [];
        if (!empty($task['metadata_json'])) {
            $metadata = json_decode((string) $task['metadata_json'], true) ?: [];
        }

        $surface = (string) ($payload['surface'] ?? '');
        if ($surface !== '') {
            $metadata['source_surface'] = $surface === 'coach' ? 'ai_coach' : $surface;
        }
        $metadata['source_recommendation_type'] = (string) ($payload['source_recommendation_type'] ?? ($metadata['source_recommendation_type'] ?? ($surface === 'coach' ? 'coach_feedback' : 'clarity_feedback')));
        foreach (['guidance_run_id', 'assistant_run_id', 'commercial_run_id', 'approval_id', 'linked_contact_id', 'linked_deal_id', 'linked_invoice_id'] as $field) {
            if (!empty($payload[$field])) {
                $metadata[$field] = (int) $payload[$field];
            }
        }
        if (!empty($payload['recommendation_key'])) {
            $metadata['recommendation_key'] = (string) $payload['recommendation_key'];
        }
        if (!empty($payload['message_hash'])) {
            $metadata['message_hash'] = (string) $payload['message_hash'];
        }

        $this->tasks->update($taskId, ['metadata_json' => $metadata]);
    }

    private function resolveWorkspaceId(array $payload): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();

        if (!empty($payload['task_id'])) {
            $task = $this->tasks->getById((int) $payload['task_id']);
            if (!$task) {
                throw new \InvalidArgumentException('Task not found.');
            }
            $workspaceId = max(1, (int) ($task['workspace_id'] ?? $workspaceId));
        }

        if (!empty($payload['guidance_run_id'])) {
            $guidanceRun = \CRM\Database::queryOne(
                "SELECT workspace_id
                 FROM ai_guidance_runs
                 WHERE id = ?
                 LIMIT 1",
                [(int) $payload['guidance_run_id']]
            );
            $guidanceWorkspaceId = (int) ($guidanceRun['workspace_id'] ?? 0);
            if ($guidanceWorkspaceId > 0 && $guidanceWorkspaceId !== $workspaceId) {
                throw new \RuntimeException('The requested guidance run does not belong to the active workspace.');
            }
        }

        foreach ([
            'linked_contact_id' => 'contacts',
            'linked_deal_id' => 'deals',
            'linked_invoice_id' => 'invoices',
        ] as $field => $table) {
            if (!empty($payload[$field])) {
                $this->workspaceScope->assertSameWorkspace($table, (int) $payload[$field], $workspaceId);
            }
        }

        return $workspaceId;
    }
}
