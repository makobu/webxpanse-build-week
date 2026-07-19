<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Tasks;

class TaskCompletionCoordinator
{
    private Tasks $tasks;
    private WorkspaceTaskAutomationSettingsService $settings;

    public function __construct(?Tasks $tasks = null, ?WorkspaceTaskAutomationSettingsService $settings = null)
    {
        $this->tasks = $tasks ?? new Tasks();
        $this->settings = $settings ?? new WorkspaceTaskAutomationSettingsService();
    }

    public function completeManually(int $taskId, array $data = []): bool
    {
        $task = $this->tasks->getById($taskId);
        if (!$task) {
            return false;
        }
        $metadata = $this->decodeMetadata($data['metadata_json'] ?? $task['metadata_json'] ?? null);
        $source = (string) ($data['_completion_source'] ?? 'manual');
        $metadata['completion_source'] = $source === 'checklist' ? 'checklist' : ($metadata['completion_source'] ?? 'manual');
        $metadata['completion_decided_at'] = date('Y-m-d H:i:s');
        $payload = array_merge($data, [
            'status' => 'completed',
            'metadata_json' => $metadata,
            '_completion_coordinator' => true,
        ]);

        return $this->transition($task, $payload, [
            'decision_source' => match ($source) {
                'checklist' => 'checklist',
                'compatibility' => 'compatibility',
                'review' => 'review',
                default => 'manual',
            },
            'confidence' => $data['_completion_confidence'] ?? null,
            'evidence_fingerprints' => (array) ($data['_completion_evidence_fingerprints'] ?? []),
            'explanation' => (string) ($data['_completion_explanation'] ?? 'Task completed by a user.'),
            'actor_user_id' => (int) ($data['actor_user_id'] ?? $_SESSION['user_id'] ?? 0),
        ]);
    }

    public function completeFromEvidence(int $taskId, array $evidence, array $decision, array $options = []): bool
    {
        $task = $this->tasks->getById($taskId);
        if (!$task || in_array((string) ($task['status'] ?? ''), ['completed', 'cancelled'], true)) {
            return false;
        }
        $fingerprint = $this->evidenceFingerprint($task, $evidence);
        if ($this->fingerprintWasRejected((int) ($task['workspace_id'] ?? 0), $taskId, $fingerprint)) {
            return false;
        }

        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        $metadata['completion_source'] = 'evidence';
        $metadata['completed_by_evidence'] = true;
        $metadata['completion_evidence_type'] = (string) ($evidence['evidence_type'] ?? 'workflow_step_completed');
        $metadata['completion_evidence_fingerprint'] = $fingerprint;
        $metadata['completion_confidence'] = (float) ($decision['confidence'] ?? $evidence['confidence_score'] ?? 0.0);
        $metadata['completion_explanation'] = (string) ($decision['explanation'] ?? 'Clarity found sufficient CRM evidence.');
        $metadata['completion_scanned_by'] = (int) ($options['actor_user_id'] ?? 0) ?: null;
        $metadata['completion_scanned_at'] = date('Y-m-d H:i:s');
        $metadata['completion_review'] = $this->reviewMetadata($decision, 'confirm');

        $started = !Database::getInstance()->inTransaction();
        if ($started) {
            Database::beginTransaction();
        }
        try {
            $locked = Database::queryOne(
                'SELECT status FROM tasks WHERE workspace_id = ? AND id = ? FOR UPDATE',
                [(int) ($task['workspace_id'] ?? 0), $taskId]
            );
            if (!$locked || in_array((string) ($locked['status'] ?? ''), ['completed', 'cancelled'], true)) {
                if ($started) {
                    Database::rollBack();
                }
                return false;
            }
            $this->upsertEvidence($task, $evidence, $fingerprint, 'accepted', (int) ($options['actor_user_id'] ?? 0));
            $success = $this->transition($task, [
                'status' => 'completed',
                'metadata_json' => $metadata,
                'actor_user_id' => (int) ($options['actor_user_id'] ?? 0),
                '_completion_coordinator' => true,
            ], [
                'decision_source' => 'clarity',
                'confidence' => (float) ($decision['confidence'] ?? $evidence['confidence_score'] ?? 0.0),
                'evidence_fingerprints' => [$fingerprint],
                'explanation' => (string) ($decision['explanation'] ?? 'Clarity found sufficient CRM evidence.'),
                'actor_user_id' => (int) ($options['actor_user_id'] ?? 0),
            ], false);
            if (!$success) {
                throw new \RuntimeException('Task completion transition was not applied.');
            }
            if ($started) {
                Database::commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($started && Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            error_log('TaskCompletionCoordinator::completeFromEvidence failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function persistSuggestion(int $taskId, array $evidence, array $decision, int $actorUserId = 0): bool
    {
        $task = $this->tasks->getById($taskId);
        if (!$task) {
            return false;
        }
        $fingerprint = $this->evidenceFingerprint($task, $evidence);
        $this->upsertEvidence($task, $evidence, $fingerprint, 'observed', $actorUserId);
        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        $metadata['completion_review'] = $this->reviewMetadata($decision, ($decision['decision'] ?? '') === 'complete' ? 'warn' : (string) ($decision['decision'] ?? 'warn'));
        $metadata['completion_review']['evidence_fingerprints'] = [$fingerprint];
        return $this->tasks->update($taskId, [
            'metadata_json' => $metadata,
            'actor_user_id' => $actorUserId,
            '_completion_coordinator' => true,
        ]);
    }

    public function reopen(int $taskId, array $data = []): bool
    {
        $task = $this->tasks->getById($taskId);
        if (!$task || (string) ($task['status'] ?? '') !== 'completed') {
            return false;
        }
        $started = !Database::getInstance()->inTransaction();
        if ($started) {
            Database::beginTransaction();
        }
        try {
            $locked = Database::queryOne(
                'SELECT status FROM tasks WHERE workspace_id = ? AND id = ? FOR UPDATE',
                [(int) ($task['workspace_id'] ?? 0), $taskId]
            );
            if (!$locked || (string) ($locked['status'] ?? '') !== 'completed') {
                if ($started) {
                    Database::rollBack();
                }
                return false;
            }
            $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
            $fingerprint = trim((string) ($metadata['completion_evidence_fingerprint'] ?? ''));
            if ($fingerprint !== '') {
                Database::execute(
                    "UPDATE ai_task_evidence
                     SET decision_status = 'rejected', decided_at = NOW(), decided_by = ?
                     WHERE workspace_id = ? AND task_id = ? AND evidence_fingerprint = ?",
                    [(int) ($data['actor_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null, (int) ($task['workspace_id'] ?? 0), $taskId, $fingerprint]
                );
                $metadata['rejected_completion_evidence_fingerprints'] = array_values(array_unique(array_merge(
                    (array) ($metadata['rejected_completion_evidence_fingerprints'] ?? []),
                    [$fingerprint]
                )));
            }
            $metadata['completion_reopened_at'] = date('Y-m-d H:i:s');
            $metadata['completion_reopened_by'] = (int) ($data['actor_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null;
            $metadata['completion_reopen_reason'] = trim((string) ($data['reason'] ?? '')) ?: null;
            unset($metadata['completed_by_evidence'], $metadata['completion_evidence_fingerprint']);

            $success = $this->transition($task, [
                'status' => (string) ($data['status'] ?? 'in_progress'),
                'metadata_json' => $metadata,
                'actor_user_id' => (int) ($data['actor_user_id'] ?? $_SESSION['user_id'] ?? 0),
                '_completion_coordinator' => true,
            ], [
                'decision_source' => 'reopen',
                'confidence' => null,
                'evidence_fingerprints' => $fingerprint !== '' ? [$fingerprint] : [],
                'explanation' => trim((string) ($data['reason'] ?? '')) ?: 'Task reopened by a user.',
                'actor_user_id' => (int) ($data['actor_user_id'] ?? $_SESSION['user_id'] ?? 0),
            ], false);
            if (!$success) {
                throw new \RuntimeException('Task reopen transition was not applied.');
            }
            if ($started) {
                Database::commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($started && Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    public function setMode(int $taskId, string $mode, int $actorUserId): bool
    {
        if (!in_array($mode, ['manual', 'review', 'auto'], true)) {
            throw new \InvalidArgumentException('Invalid task completion mode.');
        }
        $task = $this->tasks->getById($taskId);
        if (!$task) {
            return false;
        }
        $settings = $this->settings->get((int) ($task['workspace_id'] ?? 0));
        if ($mode === 'auto' && (string) ($task['origin_type'] ?? 'manual') === 'manual' && empty($settings['allow_user_task_opt_in'])) {
            throw new \RuntimeException('This workspace does not allow AI completion for user-created tasks.');
        }
        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        $metadata['auto_complete_allowed'] = $mode === 'auto';
        $metadata['protected_from_auto_complete'] = $mode !== 'auto';
        return $this->tasks->update($taskId, [
            'completion_mode' => $mode,
            'metadata_json' => $metadata,
            'actor_user_id' => $actorUserId,
            '_completion_coordinator' => true,
        ]);
    }

    public function completionPayload(array $task, int $viewerUserId = 0): array
    {
        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        $workspaceId = (int) ($task['workspace_id'] ?? 0);
        $settings = $this->settings->get($workspaceId);
        $evidenceRows = Database::tableExists('ai_task_evidence') ? Database::query(
            "SELECT evidence_type, entity_type, entity_id, confidence_score, evidence_json, evidence_fingerprint, decision_status, created_at
             FROM ai_task_evidence
             WHERE workspace_id = ? AND task_id = ?
             ORDER BY created_at DESC, id DESC LIMIT 20",
            [$workspaceId, (int) ($task['id'] ?? 0)]
        ) : [];
        $review = is_array($metadata['completion_review'] ?? null) ? $metadata['completion_review'] : [];
        $completedBy = !empty($metadata['completed_by_evidence']) ? 'clarity' : (((string) ($task['status'] ?? '') === 'completed') ? 'user' : null);
        $state = (string) ($task['status'] ?? '') === 'completed'
            ? 'completed'
            : (!empty($metadata['completion_reopened_at']) ? 'reopened' : (!empty($review) ? 'evidence_found' : 'open'));

        return [
            'mode' => (string) ($task['completion_mode'] ?? 'manual'),
            'workspace_mode' => (string) ($settings['rollout_mode'] ?? 'review'),
            'state' => $state,
            'review_required' => (string) ($task['completion_mode'] ?? 'manual') !== 'manual',
            'completed_by' => $completedBy,
            'confidence' => isset($review['confidence_score']) ? (float) $review['confidence_score'] : ($metadata['completion_confidence'] ?? null),
            'explanation' => (string) ($review['explanation'] ?? $metadata['completion_explanation'] ?? ''),
            'evidence' => array_map(function (array $row): array {
                return [
                    'type' => (string) ($row['evidence_type'] ?? ''),
                    'entity_type' => (string) ($row['entity_type'] ?? ''),
                    'entity_id' => (int) ($row['entity_id'] ?? 0),
                    'confidence' => (float) ($row['confidence_score'] ?? 0),
                    'fingerprint' => (string) ($row['evidence_fingerprint'] ?? ''),
                    'decision_status' => (string) ($row['decision_status'] ?? 'observed'),
                    'observed_at' => (string) ($row['created_at'] ?? ''),
                    'facts' => $this->decodeMetadata($row['evidence_json'] ?? null),
                ];
            }, $evidenceRows),
            'missing_evidence' => array_values((array) ($review['evidence_missing'] ?? $review['missing_evidence'] ?? [])),
            'conflicts' => array_values((array) ($review['evidence_conflicts'] ?? $review['conflicts'] ?? [])),
            'can_evaluate' => !in_array((string) ($task['status'] ?? ''), ['completed', 'cancelled'], true),
            'can_complete' => !in_array((string) ($task['status'] ?? ''), ['completed', 'cancelled'], true),
            'can_reopen' => (string) ($task['status'] ?? '') === 'completed',
            'can_enable_auto' => (string) ($task['origin_type'] ?? 'manual') !== 'manual' || !empty($settings['allow_user_task_opt_in']),
        ];
    }

    private function transition(array $task, array $updateData, array $audit, bool $manageTransaction = true): bool
    {
        $started = $manageTransaction && !Database::getInstance()->inTransaction();
        if ($started) {
            Database::beginTransaction();
        }
        try {
            $success = $this->tasks->update((int) $task['id'], $updateData);
            if (!$success) {
                if ($started) {
                    Database::rollBack();
                }
                return false;
            }
            $this->recordTransition($task, (string) ($updateData['status'] ?? $task['status'] ?? ''), $audit);
            if ($started) {
                Database::commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($started && Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private function recordTransition(array $task, string $toStatus, array $audit): void
    {
        if (!Database::tableExists('task_completion_transitions')) {
            return;
        }
        $workspaceId = (int) ($task['workspace_id'] ?? 0);
        $taskId = (int) ($task['id'] ?? 0);
        $fromStatus = (string) ($task['status'] ?? 'pending');
        $source = (string) ($audit['decision_source'] ?? 'manual');
        $fingerprints = array_values(array_unique(array_filter(array_map('strval', (array) ($audit['evidence_fingerprints'] ?? [])))));
        $transitionKey = hash('sha256', implode('|', [
            $workspaceId,
            $taskId,
            $fromStatus,
            $toStatus,
            $source,
            implode(',', $fingerprints),
            (string) ($audit['actor_user_id'] ?? 0),
            (string) ($task['lock_version'] ?? 0),
        ]));
        Database::execute(
            "INSERT IGNORE INTO task_completion_transitions
                (workspace_id, task_id, from_status, to_status, decision_source, confidence_score,
                 evidence_fingerprints_json, explanation, actor_user_id, transition_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $taskId,
                $fromStatus,
                $toStatus,
                $source,
                $audit['confidence'] ?? null,
                json_encode($fingerprints),
                (string) ($audit['explanation'] ?? ''),
                !empty($audit['actor_user_id']) ? (int) $audit['actor_user_id'] : null,
                $transitionKey,
            ]
        );
    }

    private function upsertEvidence(array $task, array $evidence, string $fingerprint, string $decisionStatus, int $actorUserId): void
    {
        Database::execute(
            "INSERT INTO ai_task_evidence
                (workspace_id, task_id, evidence_type, entity_type, entity_id, confidence_score, evidence_json,
                 evidence_fingerprint, decision_status, decided_at, decided_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                confidence_score = GREATEST(confidence_score, VALUES(confidence_score)),
                evidence_json = VALUES(evidence_json),
                decision_status = CASE WHEN decision_status = 'rejected' THEN decision_status ELSE VALUES(decision_status) END,
                decided_at = CASE WHEN decision_status = 'rejected' THEN decided_at ELSE VALUES(decided_at) END,
                decided_by = CASE WHEN decision_status = 'rejected' THEN decided_by ELSE VALUES(decided_by) END",
            [
                (int) ($task['workspace_id'] ?? 0),
                (int) ($task['id'] ?? 0),
                (string) ($evidence['evidence_type'] ?? 'workflow_step_completed'),
                (string) ($evidence['entity_type'] ?? 'task'),
                (int) ($evidence['entity_id'] ?? 0),
                (float) ($evidence['confidence_score'] ?? 0.0),
                json_encode((array) ($evidence['evidence_json'] ?? [])),
                $fingerprint,
                $decisionStatus,
                $actorUserId ?: null,
            ]
        );
    }

    private function fingerprintWasRejected(int $workspaceId, int $taskId, string $fingerprint): bool
    {
        return (bool) Database::queryOne(
            "SELECT 1 FROM ai_task_evidence
             WHERE workspace_id = ? AND task_id = ? AND evidence_fingerprint = ? AND decision_status = 'rejected'
             LIMIT 1",
            [$workspaceId, $taskId, $fingerprint]
        );
    }

    public function evidenceFingerprint(array $task, array $evidence): string
    {
        return hash('sha256', implode('|', [
            (int) ($task['workspace_id'] ?? 0),
            (int) ($task['id'] ?? 0),
            (string) ($evidence['evidence_type'] ?? ''),
            (string) ($evidence['entity_type'] ?? ''),
            (int) ($evidence['entity_id'] ?? 0),
            json_encode((array) ($evidence['evidence_json'] ?? []), JSON_UNESCAPED_SLASHES),
        ]));
    }

    private function reviewMetadata(array $decision, string $fallbackDecision): array
    {
        $found = array_map(static fn($value): array => [
            'code' => (string) $value,
            'label' => 'Recorded CRM evidence',
            'detail' => 'Evidence fingerprint ' . substr((string) $value, 0, 12),
            'fingerprint' => (string) $value,
        ], array_values((array) ($decision['evidence_fingerprints'] ?? [])));
        $missing = array_map(static fn($value): array => [
            'code' => (string) $value,
            'label' => str_replace('_', ' ', ucfirst((string) $value)),
            'detail' => 'Additional evidence is required.',
        ], array_values((array) ($decision['missing_evidence'] ?? [])));
        $conflicts = array_map(static fn($value): array => [
            'code' => (string) $value,
            'label' => str_replace('_', ' ', ucfirst((string) $value)),
            'detail' => 'Recorded evidence conflicts with automatic completion.',
        ], array_values((array) ($decision['conflicts'] ?? [])));
        return [
            'decision' => (string) ($decision['decision'] ?? $fallbackDecision),
            'confidence_score' => (float) ($decision['confidence'] ?? 0.0),
            'risk' => (string) ($decision['risk'] ?? 'low'),
            'recommended_action' => ($decision['decision'] ?? '') === 'complete' ? 'complete_now' : 'review_before_complete',
            'explanation' => (string) ($decision['explanation'] ?? ''),
            'evidence_found' => $found,
            'evidence_missing' => $missing,
            'evidence_conflicts' => $conflicts,
            'judge_source' => (string) ($decision['judge_source'] ?? 'deterministic'),
            'reviewed_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function decodeMetadata($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
