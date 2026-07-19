<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Activities;
use CRM\Modules\AITaskAutomationService;
use CRM\Modules\Tasks;

class TaskCompletionReviewService
{
    private Tasks $tasks;
    private AITaskCompletionService $completionService;
    private WorkspaceFinanceGateService $financeGate;
    private TaskCompletionCoordinator $completionCoordinator;

    public function __construct(?WorkspaceFinanceGateService $financeGate = null)
    {
        $this->tasks = new Tasks();
        $this->completionService = new AITaskCompletionService();
        $this->financeGate = $financeGate ?? new WorkspaceFinanceGateService();
        $this->completionCoordinator = new TaskCompletionCoordinator($this->tasks);
    }

    public function requiresReview(array $task): bool
    {
        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        return (string) ($task['completion_mode'] ?? 'manual') !== 'manual'
            || AITaskAutomationService::isAIAutoTask($task)
            || !empty($metadata['auto_complete_allowed']);
    }

    public function evaluateCompletion(array $task, int $actorId): array
    {
        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        $taskId = (int) ($task['id'] ?? 0);
        $review = [
            'task_id' => $taskId,
            'decision' => 'insufficient_evidence',
            'confidence_score' => 0.15,
            'evidence_found' => [],
            'evidence_missing' => [],
            'evidence_conflicts' => [],
            'recommended_action' => 'gather_more_evidence',
            'explanation' => 'Not enough evidence was found to confidently mark this task as done.',
            'reviewed_by' => $actorId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'scope_applies' => $this->requiresReview($task),
        ];

        if (!$review['scope_applies']) {
            $review['decision'] = 'confirm';
            $review['confidence_score'] = 1.0;
            $review['recommended_action'] = 'complete_now';
            $review['explanation'] = 'This task does not require evidence-aware review.';
            return $review;
        }

        $applicableSignals = 0;
        $score = 0.0;
        $isFinanceSetupTask = $this->isFinanceSetupTask($task, $metadata);

        if (!$isFinanceSetupTask) {
            $subtaskCounts = Database::queryOne(
                "SELECT COUNT(*) AS total_count,
                        SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_count
                 FROM task_subtasks
                 WHERE task_id = ?",
                [$taskId]
            ) ?: ['total_count' => 0, 'completed_count' => 0];
            $subtaskTotal = (int) ($subtaskCounts['total_count'] ?? 0);
            $subtaskCompleted = (int) ($subtaskCounts['completed_count'] ?? 0);
            if ($subtaskTotal > 0) {
                $applicableSignals++;
                if ($subtaskCompleted === $subtaskTotal) {
                    $review['evidence_found'][] = [
                        'code' => 'checklist_complete',
                        'label' => 'Checklist completed',
                        'detail' => "All {$subtaskTotal} checklist items are done."
                    ];
                    $score += 0.45;
                } else {
                    $review['evidence_missing'][] = [
                        'code' => 'checklist_incomplete',
                        'label' => 'Checklist incomplete',
                        'detail' => "{$subtaskCompleted} of {$subtaskTotal} checklist items are done."
                    ];
                }
            }
        }

        $lowerTitle = strtolower((string) ($task['title'] ?? ''));
        $lowerDescription = strtolower((string) ($task['description'] ?? ''));
        $evidenceTypes = array_map('strval', (array) ($metadata['completion_evidence_types'] ?? []));
        $contactId = (int) ($task['contact_id'] ?? 0);
        $workspaceId = (int) ($task['workspace_id'] ?? 0);

        $expectsReply = in_array('email_reply_received', $evidenceTypes, true)
            || str_contains($lowerTitle, 'reply')
            || str_contains($lowerDescription, 'reply')
            || str_contains($lowerDescription, 'follow up')
            || (($metadata['task_intent'] ?? '') === 'follow_up');
        if ($expectsReply && $contactId > 0) {
            $applicableSignals++;
            $createdAt = trim((string) ($task['created_at'] ?? ''));
            $replySql = "SELECT id, created_at FROM communications
                 WHERE contact_id = ? AND direction = 'inbound'";
            $replyParams = [$contactId];
            if ($workspaceId > 0 && Database::columnExists('communications', 'workspace_id')) {
                $replySql .= " AND workspace_id = ?";
                $replyParams[] = $workspaceId;
            }
            if ($createdAt !== '') {
                $replySql .= " AND created_at >= ?";
                $replyParams[] = $createdAt;
            }
            $replySql .= " ORDER BY created_at DESC LIMIT 1";
            $reply = Database::queryOne(
                $replySql,
                $replyParams
            );
            if ($reply) {
                $review['evidence_found'][] = [
                    'code' => 'email_reply_received',
                    'label' => 'Inbound reply received',
                    'detail' => 'An inbound communication was recorded on ' . date('M j, Y g:i A', strtotime((string) $reply['created_at'])) . '.',
                ];
                $score += 0.30;
            } else {
                $review['evidence_missing'][] = [
                    'code' => 'email_reply_missing',
                    'label' => 'Waiting for reply',
                    'detail' => 'No inbound reply has been recorded for this contact yet.',
                ];
            }
        }

        $invoiceId = (int) ($metadata['invoice_id'] ?? $metadata['linked_invoice_id'] ?? 0);
        $expectsInvoice = $invoiceId > 0 || in_array('invoice_paid', $evidenceTypes, true) || (($metadata['task_intent'] ?? '') === 'billing');
        if ($expectsInvoice && $invoiceId > 0) {
            $applicableSignals++;
            $invoiceSql = "SELECT id, status FROM invoices WHERE id = ?";
            $invoiceParams = [$invoiceId];
            if ($workspaceId > 0 && Database::columnExists('invoices', 'workspace_id')) {
                $invoiceSql .= " AND workspace_id = ?";
                $invoiceParams[] = $workspaceId;
            }
            $invoice = Database::queryOne($invoiceSql, $invoiceParams);
            $invoiceStatus = strtolower((string) ($invoice['status'] ?? ''));
            if (in_array($invoiceStatus, ['paid', 'sent', 'finalized'], true)) {
                $review['evidence_found'][] = [
                    'code' => 'invoice_status_reached',
                    'label' => 'Invoice state reached',
                    'detail' => 'Invoice #' . $invoiceId . ' is ' . $invoiceStatus . '.',
                ];
                $score += $invoiceStatus === 'paid' ? 0.35 : 0.25;
            } else {
                $review['evidence_conflicts'][] = [
                    'code' => 'invoice_not_ready',
                    'label' => 'Invoice not finished',
                    'detail' => 'Invoice #' . $invoiceId . ' is currently ' . ($invoiceStatus !== '' ? $invoiceStatus : 'unknown') . '.',
                ];
            }
        }

        $billingSignals = $this->completionService->getBillingEvidenceSignals($task);
        if (!empty($billingSignals['applicable'])) {
            if (!empty($billingSignals['design_ready'])) {
                $applicableSignals++;
                $review['evidence_found'][] = [
                    'code' => 'invoice_design_ready',
                    'label' => 'Invoice design configured',
                    'detail' => 'Invoice branding or layout settings are saved in invoice settings.',
                ];
                $score += 0.18;
            }

            if (!empty($billingSignals['settings_ready'])) {
                $applicableSignals++;
                $review['evidence_found'][] = [
                    'code' => 'billing_identity_ready',
                    'label' => 'Billing details configured',
                    'detail' => 'Company and billing identity fields are saved in invoice settings.',
                ];
                $score += 0.22;
            } else {
                $applicableSignals++;
                $review['evidence_missing'][] = [
                    'code' => 'billing_identity_missing',
                    'label' => 'Billing details incomplete',
                    'detail' => 'Required company or billing identity fields are still missing from invoice settings.',
                ];
            }

            if (!empty($billingSignals['invoice_send_ready'])) {
                $applicableSignals++;
                $latestStatus = strtolower((string) ($billingSignals['latest_invoice']['status'] ?? ''));
                $review['evidence_found'][] = [
                    'code' => 'invoice_send_ready',
                    'label' => 'Invoice sending verified',
                    'detail' => 'A recent invoice/proforma has status ' . ($latestStatus !== '' ? $latestStatus : 'sent') . '.',
                ];
                $score += 0.25;
            } else {
                $applicableSignals++;
                $review['evidence_missing'][] = [
                    'code' => 'invoice_send_missing',
                    'label' => 'Invoice send not verified',
                    'detail' => 'No sent/viewed/finalized invoice or proforma was found for this user yet.',
                ];
            }
        }

        if ($isFinanceSetupTask) {
            $this->appendFinanceSetupReview($task, $review, $applicableSignals, $score);
        }

        $dealId = (int) ($metadata['deal_id'] ?? $metadata['linked_deal_id'] ?? 0);
        $targetStage = strtolower((string) ($metadata['deal_stage'] ?? ''));
        if ($dealId > 0 && $targetStage !== '') {
            $applicableSignals++;
            $dealSql = "SELECT id, stage FROM deals WHERE id = ?";
            $dealParams = [$dealId];
            if ($workspaceId > 0 && Database::columnExists('deals', 'workspace_id')) {
                $dealSql .= " AND workspace_id = ?";
                $dealParams[] = $workspaceId;
            }
            $deal = Database::queryOne($dealSql, $dealParams);
            $dealStage = strtolower((string) ($deal['stage'] ?? ''));
            if ($dealStage === $targetStage) {
                $review['evidence_found'][] = [
                    'code' => 'deal_stage_reached',
                    'label' => 'Deal stage reached',
                    'detail' => 'Deal #' . $dealId . ' is in the required stage: ' . $targetStage . '.',
                ];
                $score += 0.30;
            } else {
                $review['evidence_conflicts'][] = [
                    'code' => 'deal_stage_mismatch',
                    'label' => 'Deal stage does not match',
                    'detail' => 'Deal #' . $dealId . ' is ' . ($dealStage !== '' ? $dealStage : 'unknown') . ', expected ' . $targetStage . '.',
                ];
            }
        }

        $dependencyTaskId = (int) ($metadata['dependency_task_id'] ?? 0);
        if ($dependencyTaskId > 0) {
            $applicableSignals++;
            $dependencyTask = Database::queryOne(
                "SELECT id, status FROM tasks WHERE workspace_id = ? AND id = ?",
                [$workspaceId, $dependencyTaskId]
            );
            if (($dependencyTask['status'] ?? '') === 'completed') {
                $review['evidence_found'][] = [
                    'code' => 'dependency_completed',
                    'label' => 'Dependency completed',
                    'detail' => 'Linked dependency task #' . $dependencyTaskId . ' is completed.',
                ];
                $score += 0.20;
            } else {
                $review['evidence_conflicts'][] = [
                    'code' => 'dependency_open',
                    'label' => 'Dependency still open',
                    'detail' => 'Linked dependency task #' . $dependencyTaskId . ' is not completed yet.',
                ];
            }
        }

        if (!empty($metadata['requires_note'])) {
            $applicableSignals++;
            $noteCount = (int) (Database::queryOne(
                "SELECT COUNT(*) AS c FROM notes WHERE entity_type = 'task' AND entity_id = ?",
                [$taskId]
            )['c'] ?? 0);
            if ($noteCount > 0) {
                $review['evidence_found'][] = [
                    'code' => 'task_note_present',
                    'label' => 'Task note present',
                    'detail' => $noteCount . ' task note(s) recorded.',
                ];
                $score += 0.10;
            } else {
                $review['evidence_missing'][] = [
                    'code' => 'task_note_missing',
                    'label' => 'Expected task note',
                    'detail' => 'This task is configured to require a note before completion.',
                ];
            }
        }

        if (!empty($metadata['requires_document'])) {
            $applicableSignals++;
            $documentCount = (int) (Database::queryOne(
                "SELECT COUNT(*) AS c FROM documents WHERE entity_type = 'task' AND entity_id = ?",
                [$taskId]
            )['c'] ?? 0);
            if ($documentCount > 0) {
                $review['evidence_found'][] = [
                    'code' => 'task_document_present',
                    'label' => 'Task document present',
                    'detail' => $documentCount . ' document(s) attached to this task.',
                ];
                $score += 0.10;
            } else {
                $review['evidence_missing'][] = [
                    'code' => 'task_document_missing',
                    'label' => 'Expected task document',
                    'detail' => 'This task is configured to require a document before completion.',
                ];
            }
        }

        $this->appendDetectorEvidence($task, $review, $applicableSignals, $score);

        $score = max(0.0, min(1.0, $score));
        $review['confidence_score'] = round($score, 2);

        if (!empty($review['evidence_conflicts']) && $score < 0.75) {
            $review['decision'] = 'contradict';
            $review['recommended_action'] = 'keep_open_or_override';
            $review['explanation'] = $this->buildExplanation($review, 'The recorded evidence conflicts with marking this task complete.');
        } elseif ($score >= 0.85) {
            $review['decision'] = 'confirm';
            $review['recommended_action'] = 'complete_now';
            $review['explanation'] = $this->buildExplanation($review, 'The task appears complete based on strong supporting evidence.');
        } elseif (!empty($review['evidence_found'])) {
            $review['decision'] = 'warn';
            $review['recommended_action'] = 'review_before_complete';
            $review['explanation'] = $this->buildExplanation($review, 'Some evidence supports completion, but the task should be reviewed before it is marked done.');
        } else {
            $review['decision'] = 'insufficient_evidence';
            $review['recommended_action'] = 'gather_more_evidence';
            $review['explanation'] = $this->buildExplanation($review, 'Not enough supporting evidence was found to confidently mark this task complete.');
        }

        return $review;
    }

    public function persistReview(int $taskId, array $review, int $actorId): bool
    {
        $task = $this->tasks->getById($taskId);
        if (!$task) {
            return false;
        }

        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        $metadata['completion_review'] = [
            'decision' => (string) ($review['decision'] ?? 'insufficient_evidence'),
            'confidence_score' => (float) ($review['confidence_score'] ?? 0.0),
            'recommended_action' => (string) ($review['recommended_action'] ?? 'gather_more_evidence'),
            'explanation' => (string) ($review['explanation'] ?? ''),
            'evidence_found' => array_values((array) ($review['evidence_found'] ?? [])),
            'evidence_missing' => array_values((array) ($review['evidence_missing'] ?? [])),
            'evidence_conflicts' => array_values((array) ($review['evidence_conflicts'] ?? [])),
            'reviewed_by' => $actorId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ];

        return $this->saveMetadata($taskId, $metadata);
    }

    public function clearReview(int $taskId): bool
    {
        $task = $this->tasks->getById($taskId);
        if (!$task) {
            return false;
        }

        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        unset($metadata['completion_review']);
        return $this->saveMetadata($taskId, $metadata);
    }

    public function completeFromReview(int $taskId, int $actorId, string $decision = 'complete_anyway', ?string $overrideReason = null): array
    {
        $task = $this->tasks->getById($taskId);
        if (!$task) {
            return ['success' => false, 'error' => 'Task not found'];
        }

        $review = $this->evaluateCompletion($task, $actorId);
        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        $metadata['completion_review'] = [
            'decision' => (string) ($review['decision'] ?? 'insufficient_evidence'),
            'confidence_score' => (float) ($review['confidence_score'] ?? 0.0),
            'recommended_action' => (string) ($review['recommended_action'] ?? 'gather_more_evidence'),
            'explanation' => (string) ($review['explanation'] ?? ''),
            'evidence_found' => array_values((array) ($review['evidence_found'] ?? [])),
            'evidence_missing' => array_values((array) ($review['evidence_missing'] ?? [])),
            'evidence_conflicts' => array_values((array) ($review['evidence_conflicts'] ?? [])),
            'reviewed_by' => $actorId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'finalized_decision' => $decision,
        ];
        $metadata['completion_source'] = ($review['decision'] === 'confirm' && $decision !== 'complete_anyway')
            ? 'manual_review_confirmed'
            : 'manual_override';
        $metadata['completion_review_decision'] = (string) ($review['decision'] ?? 'insufficient_evidence');
        $metadata['completion_review_confidence'] = (float) ($review['confidence_score'] ?? 0.0);
        $metadata['completion_reviewed_at'] = date('Y-m-d H:i:s');
        $metadata['completion_reviewed_by'] = $actorId;

        $reason = trim((string) $overrideReason);
        if ($reason !== '') {
            $metadata['completion_override_reason'] = $reason;
            $metadata['completion_review']['override_reason'] = $reason;
        } else {
            unset($metadata['completion_override_reason']);
        }

        $evidenceFingerprints = array_values(array_filter(array_map(
            static fn($item): string => is_array($item) ? (string) ($item['fingerprint'] ?? $item['code'] ?? '') : (string) $item,
            (array) ($review['evidence_found'] ?? [])
        )));
        $success = $this->completionCoordinator->completeManually($taskId, [
            'metadata_json' => $metadata,
            'actor_user_id' => $actorId,
            '_completion_source' => 'review',
            '_completion_confidence' => (float) ($review['confidence_score'] ?? 0.0),
            '_completion_evidence_fingerprints' => $evidenceFingerprints,
            '_completion_explanation' => (string) ($review['explanation'] ?? 'Task completed after evidence review.'),
        ]);

        if ($success && !empty($task['contact_id']) && $metadata['completion_source'] === 'manual_override') {
            try {
                (new Activities())->log(
                    (int) $task['contact_id'],
                    'note',
                    'Task completion overridden after evidence review: ' . (string) ($task['title'] ?? 'Task'),
                    [
                        'task_id' => $taskId,
                        'completion_review_decision' => $review['decision'] ?? null,
                        'completion_review_confidence' => $review['confidence_score'] ?? null,
                        'override_reason' => $reason !== '' ? $reason : null,
                    ],
                    $actorId
                );
            } catch (\Throwable $e) {
                error_log('TaskCompletionReviewService override log failed: ' . $e->getMessage());
            }
        }

        return ['success' => $success, 'review' => $review];
    }

    private function buildExplanation(array $review, string $fallback): string
    {
        $foundLabels = array_map(static fn(array $item): string => (string) ($item['label'] ?? 'Evidence found'), array_slice((array) ($review['evidence_found'] ?? []), 0, 2));
        $missingLabels = array_map(static fn(array $item): string => (string) ($item['label'] ?? 'Missing evidence'), array_slice((array) ($review['evidence_missing'] ?? []), 0, 2));
        $conflictLabels = array_map(static fn(array $item): string => (string) ($item['label'] ?? 'Conflicting evidence'), array_slice((array) ($review['evidence_conflicts'] ?? []), 0, 2));

        $parts = [];
        if (!empty($foundLabels)) {
            $parts[] = 'Found: ' . implode(', ', $foundLabels) . '.';
        }
        if (!empty($missingLabels)) {
            $parts[] = 'Missing: ' . implode(', ', $missingLabels) . '.';
        }
        if (!empty($conflictLabels)) {
            $parts[] = 'Conflicts: ' . implode(', ', $conflictLabels) . '.';
        }

        return !empty($parts) ? implode(' ', $parts) : $fallback;
    }

    private function appendDetectorEvidence(array $task, array &$review, int &$applicableSignals, float &$score): void
    {
        if (!$this->shouldConsultCompletionDetector($task)) {
            return;
        }

        $evidence = $this->completionService->findMatchingEvidence($task);
        if (empty($evidence)) {
            return;
        }

        $code = (string) ($evidence['evidence_type'] ?? 'matched_evidence');
        if ($this->reviewHasEvidenceCode($review, $code, 'evidence_found')) {
            $score += (float) ($evidence['confidence_score'] ?? 0.0);
            $applicableSignals++;
            return;
        }
        if ($this->reviewHasEvidenceCode($review, $code)) {
            return;
        }

        $review['evidence_found'][] = [
            'code' => $code,
            'label' => $this->detectorEvidenceLabel($code),
            'detail' => $this->detectorEvidenceDetail($evidence),
        ];
        $score += (float) ($evidence['confidence_score'] ?? 0.0);
        $applicableSignals++;
    }

    private function shouldConsultCompletionDetector(array $task): bool
    {
        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        return AITaskAutomationService::isAIAutoTask($task)
            || !empty($metadata['auto_complete_allowed'])
            || !empty($metadata['completion_evidence_types']);
    }

    private function reviewHasEvidenceCode(array $review, string $code, ?string $onlyBucket = null): bool
    {
        $buckets = $onlyBucket !== null ? [$onlyBucket] : ['evidence_found', 'evidence_missing', 'evidence_conflicts'];
        foreach ($buckets as $bucket) {
            foreach ((array) ($review[$bucket] ?? []) as $item) {
                if ((string) ($item['code'] ?? '') === $code) {
                    return true;
                }
            }
        }

        return false;
    }

    private function detectorEvidenceLabel(string $code): string
    {
        return match ($code) {
            'email_reply_received' => 'Inbound reply received',
            'deal_stage_reached' => 'Deal stage reached',
            'invoice_paid' => 'Invoice paid',
            'workflow_step_completed' => 'Workflow evidence found',
            'task_dependency_completed' => 'Dependency completed',
            'finance_setup_ready' => 'Finance setup ready',
            default => 'Detected completion evidence',
        };
    }

    private function detectorEvidenceDetail(array $evidence): string
    {
        $payload = is_array($evidence['evidence_json'] ?? null) ? $evidence['evidence_json'] : [];
        $type = (string) ($evidence['evidence_type'] ?? '');
        $entityType = trim((string) ($evidence['entity_type'] ?? ''));
        $entityId = (int) ($evidence['entity_id'] ?? 0);

        if ($type === 'email_reply_received' && !empty($payload['created_at'])) {
            return 'An inbound reply was recorded on ' . date('M j, Y g:i A', strtotime((string) $payload['created_at'])) . '.';
        }
        if (($type === 'invoice_paid' || $type === 'workflow_step_completed') && !empty($payload['invoice_status'])) {
            return 'Detected invoice evidence with status ' . (string) $payload['invoice_status'] . '.';
        }
        if (!empty($payload['message'])) {
            return (string) $payload['message'];
        }
        if ($entityType !== '' && $entityId > 0) {
            return 'Detected completion evidence on ' . $entityType . ' #' . $entityId . '.';
        }

        return 'Existing completion rules detected supporting evidence for this task.';
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

    private function saveMetadata(int $taskId, array $metadata): bool
    {
        Database::execute(
            "UPDATE tasks SET metadata_json = ? WHERE id = ?",
            [json_encode($metadata), $taskId]
        );
        return true;
    }

    private function appendFinanceSetupReview(array $task, array &$review, int &$applicableSignals, float &$score): void
    {
        $applicableSignals++;
        $workspaceId = (int) ($task['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        }

        if ($workspaceId <= 0) {
            $review['evidence_missing'][] = [
                'code' => 'finance_workspace_missing',
                'label' => 'Workspace unavailable',
                'detail' => 'Finance setup evidence needs an active workspace.',
            ];
            return;
        }

        try {
            $status = $this->financeGate->status($workspaceId, null);
        } catch (\Throwable $e) {
            $review['evidence_conflicts'][] = [
                'code' => 'finance_setup_check_failed',
                'label' => 'Finance setup check failed',
                'detail' => 'Finance readiness could not be checked: ' . $e->getMessage(),
            ];
            return;
        }

        if (!empty($status['ready'])) {
            $review['evidence_found'][] = [
                'code' => 'finance_setup_ready',
                'label' => 'Finance setup ready',
                'detail' => (string) ($status['message'] ?? 'Finance is ready.'),
            ];
            $score += 0.95;
            return;
        }

        $review['evidence_missing'][] = [
            'code' => 'finance_setup_incomplete',
            'label' => 'Finance setup incomplete',
            'detail' => $this->financeSetupStatusDetail($status),
        ];
    }

    private function financeSetupStatusDetail(array $status): string
    {
        $message = trim((string) ($status['message'] ?? 'Complete Finance setup before opening Finance.'));
        $blockers = array_values(array_filter(array_map(
            static fn($blocker): string => trim((string) $blocker),
            (array) ($status['blockers'] ?? [])
        )));

        if ($message === '') {
            $message = 'Complete Finance setup before opening Finance.';
        }

        if ($blockers === []) {
            return $message;
        }

        return rtrim($message, '.') . '. Missing: ' . implode(', ', $blockers) . '.';
    }

    private function isFinanceSetupTask(array $task, array $metadata): bool
    {
        $evidenceTypes = array_values(array_filter((array) ($metadata['completion_evidence_types'] ?? []), 'is_string'));
        if (in_array('finance_setup_ready', $evidenceTypes, true)) {
            return true;
        }

        foreach ($this->financeSkillCandidates($task, $metadata) as $candidate) {
            if (strtolower(trim((string) $candidate)) === WorkspaceSkillCatalogService::PLUGIN_FINANCE) {
                return true;
            }
        }

        $text = strtolower(trim((string) ($task['title'] ?? '') . ' ' . (string) ($task['description'] ?? '')));
        return str_contains($text, 'finance')
            && (
                str_contains($text, 'setup')
                || str_contains($text, 'opening finance')
                || str_contains($text, 'before opening finance')
            );
    }

    private function financeSkillCandidates(array $task, array $metadata): array
    {
        $activationBundle = is_array($metadata['marketplace_activation_bundle'] ?? null)
            ? $metadata['marketplace_activation_bundle']
            : [];
        $sourceContext = is_array($metadata['source_context'] ?? null)
            ? $metadata['source_context']
            : [];

        return [
            $metadata['marketplace_skill_key'] ?? null,
            $metadata['skill_key'] ?? null,
            $metadata['module_key'] ?? null,
            $metadata['source_skill_key'] ?? null,
            $metadata['source_plugin_key'] ?? null,
            $metadata['recommended_skill_key'] ?? null,
            $activationBundle['skill_key'] ?? null,
            $sourceContext['skill_key'] ?? null,
            $task['source_skill_key'] ?? null,
            $task['source_plugin_key'] ?? null,
        ];
    }
}
