<?php

namespace CRM\Services;

use CRM\Database;

class AIDecisionOutcomeService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(?AIWorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function recordGuidanceOutcome(array $run, array $classification): int
    {
        return $this->recordOutcome([
            'guidance_run_id' => (int) ($run['id'] ?? 0) ?: null,
            'user_id' => (int) ($run['user_id'] ?? 0) ?: null,
            'surface' => (string) ($run['surface'] ?? 'coach'),
            'decision_type' => 'advice',
            'action_type' => (string) ($run['surface'] ?? 'guidance'),
            'predicted_confidence' => $run['confidence_score'] ?? null,
            'context_quality_score' => $run['context_quality_score'] ?? null,
            'goal_relevance_score' => $run['goal_relevance_score'] ?? null,
            'policy_decision' => $run['decision'] ?? null,
            'threshold_snapshot_json' => $this->decodeJson($run['policy_snapshot_json'] ?? null),
            'outcome_label' => (string) ($classification['outcome_label'] ?? 'ignored'),
            'outcome_score' => (float) ($classification['outcome_score'] ?? 0.0),
            'outcome_metadata_json' => $classification['metadata'] ?? [],
            'measured_at' => $classification['measured_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function recordAssistantOutcome(array $run, array $classification): int
    {
        $result = $this->decodeJson($run['result_json'] ?? null);
        $policy = (array) ($result['policy'] ?? []);
        return $this->recordOutcome([
            'assistant_run_id' => (int) ($run['id'] ?? 0) ?: null,
            'user_id' => (int) ($run['user_id'] ?? 0) ?: null,
            'surface' => 'assistant',
            'decision_type' => !empty($result['draft']) ? 'draft' : 'action',
            'action_type' => (string) ($run['intent'] ?? 'assistant_action'),
            'predicted_confidence' => $policy['confidence_score'] ?? null,
            'context_quality_score' => $policy['context_quality_score'] ?? null,
            'goal_relevance_score' => $policy['goal_relevance_score'] ?? null,
            'policy_decision' => $policy['decision'] ?? null,
            'threshold_snapshot_json' => ['threshold' => $policy['threshold'] ?? null, 'mode' => $policy['mode'] ?? null],
            'outcome_label' => (string) ($classification['outcome_label'] ?? 'ignored'),
            'outcome_score' => (float) ($classification['outcome_score'] ?? 0.0),
            'outcome_metadata_json' => $classification['metadata'] ?? [],
            'measured_at' => $classification['measured_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function recordCommercialOutcome(array $run, array $classification): int
    {
        $policy = $this->decodeJson($run['policy_snapshot_json'] ?? null);
        return $this->recordOutcome([
            'commercial_run_id' => (int) ($run['id'] ?? 0) ?: null,
            'user_id' => null,
            'surface' => 'commercial',
            'decision_type' => 'action',
            'action_type' => (string) ($classification['action_type'] ?? 'commercial_action'),
            'predicted_confidence' => $policy['assistant_confidence'] ?? null,
            'context_quality_score' => $policy['context_quality_score'] ?? null,
            'goal_relevance_score' => $policy['goal_relevance_score'] ?? null,
            'policy_decision' => $run['decision'] ?? null,
            'threshold_snapshot_json' => $policy,
            'outcome_label' => (string) ($classification['outcome_label'] ?? 'ignored'),
            'outcome_score' => (float) ($classification['outcome_score'] ?? 0.0),
            'outcome_metadata_json' => $classification['metadata'] ?? [],
            'measured_at' => $classification['measured_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function recordApprovalOutcome(array $approval, array $classification): int
    {
        $payload = (array) ($approval['payload'] ?? []);
        $context = (array) ($payload['context'] ?? []);
        return $this->recordOutcome([
            'approval_id' => (int) ($approval['id'] ?? 0) ?: null,
            'user_id' => (int) ($approval['requested_by_id'] ?? 0) ?: null,
            'surface' => 'commercial',
            'decision_type' => 'approval',
            'action_type' => (string) ($approval['action_key'] ?? 'approval'),
            'predicted_confidence' => $context['assistant_confidence'] ?? null,
            'context_quality_score' => $context['context_quality_score'] ?? null,
            'goal_relevance_score' => $context['goal_relevance_score'] ?? null,
            'policy_decision' => match ((string) ($approval['status'] ?? 'pending')) {
                'approved' => 'allow',
                'rejected' => 'blocked',
                default => 'approval_required',
            },
            'threshold_snapshot_json' => ['reason' => $approval['reason'] ?? null],
            'outcome_label' => (string) ($classification['outcome_label'] ?? 'ignored'),
            'outcome_score' => (float) ($classification['outcome_score'] ?? 0.0),
            'outcome_metadata_json' => $classification['metadata'] ?? [],
            'measured_at' => $classification['measured_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function recordTaskOutcome(array $task, array $classification): int
    {
        $metadata = $this->decodeJson($task['metadata_json'] ?? null);
        $sourceSurface = (string) ($metadata['source_surface'] ?? '');
        $surface = match ($sourceSurface) {
            'ai_coach', 'coach' => 'coach',
            'clarity_chat' => 'clarity_chat',
            'assistant', 'customer_thread' => 'assistant',
            'commercial_assistant', 'commercial' => 'commercial',
            default => 'task_automation',
        };
        return $this->recordOutcome([
            'guidance_run_id' => !empty($metadata['guidance_run_id']) ? (int) $metadata['guidance_run_id'] : null,
            'assistant_run_id' => !empty($metadata['assistant_run_id']) ? (int) $metadata['assistant_run_id'] : null,
            'commercial_run_id' => !empty($metadata['commercial_run_id']) ? (int) $metadata['commercial_run_id'] : null,
            'approval_id' => !empty($metadata['approval_id']) ? (int) $metadata['approval_id'] : null,
            'task_id' => (int) ($task['id'] ?? 0) ?: null,
            'user_id' => (int) (($task['assigned_to'] ?? 0) ?: ($task['created_by'] ?? 0)) ?: null,
            'surface' => $surface,
            'decision_type' => 'task',
            'action_type' => (string) ($metadata['source_recommendation_type'] ?? $metadata['task_intent'] ?? 'task'),
            'predicted_confidence' => $metadata['predicted_confidence'] ?? null,
            'context_quality_score' => $metadata['context_quality_score'] ?? null,
            'goal_relevance_score' => $metadata['goal_relevance_score'] ?? null,
            'policy_decision' => $metadata['policy_decision'] ?? null,
            'threshold_snapshot_json' => [
                'completion_source' => $metadata['completion_source'] ?? null,
                'source_surface' => $sourceSurface !== '' ? $sourceSurface : null,
            ],
            'outcome_label' => (string) ($classification['outcome_label'] ?? 'ignored'),
            'outcome_score' => (float) ($classification['outcome_score'] ?? 0.0),
            'outcome_metadata_json' => array_merge(
                [
                    'linked_deal_id' => !empty($metadata['linked_deal_id']) ? (int) $metadata['linked_deal_id'] : null,
                    'linked_contact_id' => !empty($metadata['linked_contact_id']) ? (int) $metadata['linked_contact_id'] : null,
                    'linked_invoice_id' => !empty($metadata['linked_invoice_id']) ? (int) $metadata['linked_invoice_id'] : null,
                    'recommendation_key' => $metadata['recommendation_key'] ?? null,
                    'message_hash' => $metadata['message_hash'] ?? null,
                ],
                (array) ($classification['metadata'] ?? [])
            ),
            'measured_at' => $classification['measured_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function recordManualFollowThroughOutcome(array $payload): int
    {
        $surface = (string) ($payload['surface'] ?? 'assistant');
        $decisionType = (string) ($payload['decision_type'] ?? ($surface === 'commercial' ? 'action' : 'advice'));
        $actionType = (string) ($payload['action_type'] ?? ($surface === 'commercial' ? 'manual_followthrough' : $surface . '_followthrough'));

        return $this->recordOutcome([
            'guidance_run_id' => !empty($payload['guidance_run_id']) ? (int) $payload['guidance_run_id'] : null,
            'assistant_run_id' => !empty($payload['assistant_run_id']) ? (int) $payload['assistant_run_id'] : null,
            'commercial_run_id' => !empty($payload['commercial_run_id']) ? (int) $payload['commercial_run_id'] : null,
            'approval_id' => !empty($payload['approval_id']) ? (int) $payload['approval_id'] : null,
            'task_id' => !empty($payload['task_id']) ? (int) $payload['task_id'] : null,
            'user_id' => !empty($payload['user_id']) ? (int) $payload['user_id'] : null,
            'surface' => $surface,
            'decision_type' => $decisionType,
            'action_type' => $actionType,
            'predicted_confidence' => $payload['predicted_confidence'] ?? null,
            'context_quality_score' => $payload['context_quality_score'] ?? null,
            'goal_relevance_score' => $payload['goal_relevance_score'] ?? null,
            'policy_decision' => $payload['policy_decision'] ?? 'allow',
            'threshold_snapshot_json' => (array) ($payload['threshold_snapshot_json'] ?? []),
            'outcome_label' => (string) ($payload['outcome_label'] ?? 'accepted'),
            'outcome_score' => (float) ($payload['outcome_score'] ?? 1.0),
            'outcome_metadata_json' => array_merge(
                [
                    'manual_followthrough' => true,
                    'linked_deal_id' => !empty($payload['linked_deal_id']) ? (int) $payload['linked_deal_id'] : null,
                    'linked_contact_id' => !empty($payload['linked_contact_id']) ? (int) $payload['linked_contact_id'] : null,
                    'linked_invoice_id' => !empty($payload['linked_invoice_id']) ? (int) $payload['linked_invoice_id'] : null,
                    'recommendation_key' => $payload['recommendation_key'] ?? null,
                    'message_hash' => $payload['message_hash'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                ],
                (array) ($payload['metadata'] ?? [])
            ),
            'measured_at' => $payload['measured_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function recordOutcome(array $data): int
    {
        if (!$this->tableExists('ai_decision_outcomes')) {
            return 0;
        }

        $workspaceId = $this->resolveWorkspaceId($data);

        $linkColumns = ['guidance_run_id', 'assistant_run_id', 'commercial_run_id', 'approval_id', 'task_id'];
        $where = [];
        $params = [$workspaceId];
        $where[] = 'workspace_id = ?';
        foreach ($linkColumns as $column) {
            if (!empty($data[$column])) {
                $where[] = $column . ' = ?';
                $params[] = (int) $data[$column];
            }
        }
        if ($where) {
            $where[] = 'outcome_label = ?';
            $params[] = (string) ($data['outcome_label'] ?? 'ignored');
            $where[] = 'DATE(measured_at) = ?';
            $params[] = date('Y-m-d', strtotime((string) ($data['measured_at'] ?? 'now')));
            $existing = Database::queryOne('SELECT id FROM ai_decision_outcomes WHERE ' . implode(' AND ', $where) . ' LIMIT 1', $params);
            if ($existing) {
                return (int) $existing['id'];
            }
        }

        Database::execute(
            "INSERT INTO ai_decision_outcomes
                (workspace_id, guidance_run_id, assistant_run_id, commercial_run_id, approval_id, task_id, user_id, surface, decision_type, action_type,
                 predicted_confidence, context_quality_score, goal_relevance_score, policy_decision, threshold_snapshot_json, outcome_label,
                 outcome_score, outcome_metadata_json, measured_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $data['guidance_run_id'] ?? null,
                $data['assistant_run_id'] ?? null,
                $data['commercial_run_id'] ?? null,
                $data['approval_id'] ?? null,
                $data['task_id'] ?? null,
                $data['user_id'] ?? null,
                (string) ($data['surface'] ?? 'assistant'),
                (string) ($data['decision_type'] ?? 'action'),
                (string) ($data['action_type'] ?? 'unknown'),
                $data['predicted_confidence'] ?? null,
                $data['context_quality_score'] ?? null,
                $data['goal_relevance_score'] ?? null,
                $data['policy_decision'] ?? null,
                json_encode($data['threshold_snapshot_json'] ?? []),
                (string) ($data['outcome_label'] ?? 'ignored'),
                (float) ($data['outcome_score'] ?? 0.0),
                json_encode($data['outcome_metadata_json'] ?? []),
                (string) ($data['measured_at'] ?? date('Y-m-d H:i:s')),
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function decodeJson($value): array
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

    private function resolveWorkspaceId(array $data): int
    {
        if (!empty($data['workspace_id'])) {
            return $this->workspaceScope->requireWorkspaceId(null, (int) $data['workspace_id']);
        }

        foreach ([
            ['guidance_run_id', 'ai_guidance_runs'],
            ['task_id', 'tasks'],
        ] as [$idKey, $table]) {
            if (!empty($data[$idKey])) {
                $row = Database::queryOne(
                    "SELECT workspace_id FROM {$table} WHERE id = ? LIMIT 1",
                    [(int) $data[$idKey]]
                );
                $workspaceId = (int) ($row['workspace_id'] ?? 0);
                if ($workspaceId > 0) {
                    return $workspaceId;
                }
            }
        }

        return $this->workspaceScope->requireWorkspaceId();
    }
}
