<?php

namespace CRM\Services;

class AIOutcomeClassifier
{
    public function classifyAssistantDraftOutcome(array $assistantRun, array $sendContext): array
    {
        $draftBody = $this->normalizeText((string) ($sendContext['draft']['plain_body'] ?? ''));
        $sentBody = $this->normalizeText((string) ($sendContext['sent_body'] ?? ''));

        if ($draftBody === '' || $sentBody === '') {
            return $this->build('ignored', ['reason' => 'missing_draft_or_sent_body']);
        }

        $similarity = $this->similarityRatio($draftBody, $sentBody);
        $label = $similarity >= 0.85 ? 'accepted' : 'edited';

        return $this->build($label, [
            'similarity_ratio' => $similarity,
            'assistant_run_id' => (int) ($assistantRun['id'] ?? 0),
        ]);
    }

    public function classifyApprovalOutcome(array $approval, ?array $executionResult = null): array
    {
        $status = (string) ($approval['status'] ?? 'pending');
        if ($status === 'rejected') {
            return $this->build('rejected', ['approval_id' => (int) ($approval['id'] ?? 0)]);
        }
        if ($status === 'approved') {
            $executionStatus = (string) ($executionResult['execution']['status'] ?? $executionResult['status'] ?? '');
            if (in_array($executionStatus, ['failed', 'blocked'], true)) {
                return $this->build('failed', [
                    'approval_id' => (int) ($approval['id'] ?? 0),
                    'execution_status' => $executionStatus,
                ]);
            }
            return $this->build('approved', [
                'approval_id' => (int) ($approval['id'] ?? 0),
                'execution_status' => $executionStatus,
            ]);
        }

        return $this->build('ignored', ['approval_id' => (int) ($approval['id'] ?? 0)]);
    }

    public function classifyTaskOutcome(array $task, array $evidence = []): array
    {
        $status = (string) ($task['status'] ?? '');
        $metadata = $this->decodeJson($task['metadata_json'] ?? null);
        if ($status === 'completed') {
            return $this->build('completed', [
                'task_id' => (int) ($task['id'] ?? 0),
                'completion_source' => $metadata['completion_source'] ?? null,
                'evidence' => $evidence,
            ]);
        }
        if ($status === 'cancelled') {
            return $this->build('rejected', ['task_id' => (int) ($task['id'] ?? 0)]);
        }
        if (!empty($metadata['completed_by_evidence']) && $status !== 'completed') {
            return $this->build('reversed', ['task_id' => (int) ($task['id'] ?? 0)]);
        }

        return $this->build('ignored', ['task_id' => (int) ($task['id'] ?? 0)]);
    }

    public function classifyCoachOutcome(array $context): array
    {
        if (!empty($context['task_completed'])) {
            return $this->build('completed', $context);
        }
        if (!empty($context['task_cancelled'])) {
            return $this->build('rejected', $context);
        }
        if (!empty($context['action_taken'])) {
            return $this->build('accepted', $context);
        }
        return $this->build('ignored', $context);
    }

    public function scoreOutcomeLabel(string $label): float
    {
        return match ($label) {
            'accepted', 'approved', 'completed' => 1.0,
            'edited' => 0.55,
            'ignored' => 0.35,
            'rejected', 'reversed', 'failed' => 0.0,
            default => 0.0,
        };
    }

    private function build(string $label, array $metadata): array
    {
        return [
            'outcome_label' => $label,
            'outcome_score' => $this->scoreOutcomeLabel($label),
            'metadata' => $metadata,
        ];
    }

    private function normalizeText(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value ?? '');
        return (string) $value;
    }

    private function similarityRatio(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }
        similar_text($left, $right, $percent);
        return round($percent / 100, 4);
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
}
