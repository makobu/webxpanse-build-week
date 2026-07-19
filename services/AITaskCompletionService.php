<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Tasks;
use CRM\Modules\UserPreferences;

class AITaskCompletionService
{
    private Tasks $tasks;
    private UserPreferences $preferences;
    private AIThresholdUpdateService $thresholds;
    private AIOutcomeClassifier $classifier;
    private AIDecisionOutcomeService $outcomes;
    private AIRuntimeControlService $runtimeControls;
    private TaskCompletionCoordinator $completionCoordinator;
    private AITaskCompletionDecisionService $decisionService;
    private WorkspaceTaskAutomationSettingsService $workspaceSettings;

    public function __construct(?AITaskCompletionDecisionService $decisionService = null)
    {
        $this->tasks = new Tasks();
        $this->preferences = new UserPreferences();
        $this->thresholds = new AIThresholdUpdateService();
        $this->classifier = new AIOutcomeClassifier();
        $this->outcomes = new AIDecisionOutcomeService();
        $this->runtimeControls = new AIRuntimeControlService();
        $this->completionCoordinator = new TaskCompletionCoordinator($this->tasks);
        $this->decisionService = $decisionService ?? new AITaskCompletionDecisionService();
        $this->workspaceSettings = new WorkspaceTaskAutomationSettingsService();
    }

    public function scanForCompletionEvidence(int $userId, ?int $taskId = null, array $options = []): array
    {
        $workspaceId = (int) ($options['workspace_id'] ?? 0);
        if ($workspaceId > 0 && empty($options['workspace_context_applied'])) {
            $currentWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
            if ($currentWorkspaceId !== $workspaceId) {
                $snapshot = WorkspaceContext::runtimeSnapshot();
                WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId);
                try {
                    $options['workspace_context_applied'] = true;
                    return $this->scanForCompletionEvidence($userId, $taskId, $options);
                } finally {
                    WorkspaceContext::restoreRuntimeWorkspace($snapshot);
                }
            }
        }

        $control = $this->completionRuntimeControl();
        $controlMode = (string) ($control['control_mode'] ?? 'normal');
        if ($controlMode === 'paused') {
            return [];
        }

        if ($taskId !== null) {
            $task = $this->tasks->getById($taskId);
            if (!$task) {
                return [];
            }
            if (!empty($options['enforce_task_access'])) {
                $this->assertActorCanScanTask($task, (int) ($options['actor_user_id'] ?? $userId));
            }
            $taskList = [$task];
        } else {
            $taskList = $this->tasksForUser($userId);
        }

        $results = [];
        foreach ($taskList as $task) {
            $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
            $completionMode = (string) ($task['completion_mode'] ?? (!empty($metadata['auto_complete_allowed']) ? 'auto' : 'manual'));
            if ($completionMode === 'manual' && empty($options['evaluate_manual_task'])) {
                continue;
            }
            if ($controlMode === 'normal') {
                $subtaskSync = $this->syncSubtasksFromEvidence($task);
                if (!empty($subtaskSync['task_completed'])) {
                    $results[] = [
                        'task_id' => (int) $task['id'],
                        'completed' => true,
                        'evidence' => [
                            'evidence_type' => 'workflow_step_completed',
                            'entity_type' => 'task',
                            'entity_id' => (int) $task['id'],
                            'confidence_score' => 0.98,
                            'evidence_json' => ['source' => 'subtask_evidence_sync'],
                        ],
                    ];
                    continue;
                }
            }

            $evidence = $this->findMatchingEvidence($task);
            if (!$evidence) {
                continue;
            }

            $evidence['evidence_fingerprint'] = $this->completionCoordinator->evidenceFingerprint($task, $evidence);
            $workspaceSettings = $this->workspaceSettings->get($this->taskWorkspaceId($task));
            $decision = $this->decisionService->decide($task, $evidence, $workspaceSettings);

            if ($this->shouldAutoComplete($task, $evidence, array_merge($options, ['decision' => $decision, 'workspace_settings' => $workspaceSettings]))) {
                $results[] = [
                    'task_id' => (int) $task['id'],
                    'completed' => $this->autoCompleteTask((int) $task['id'], $evidence, array_merge($options, ['decision' => $decision])),
                    'evidence' => $evidence,
                    'decision' => $decision,
                    'workspace_mode' => (string) ($workspaceSettings['rollout_mode'] ?? 'review'),
                ];
                continue;
            }

            $shouldSuggest = $controlMode === 'suggest_only'
                || (string) ($workspaceSettings['rollout_mode'] ?? 'review') === 'review'
                || $completionMode === 'review'
                || (string) ($decision['decision'] ?? 'review') !== 'complete';
            if ($shouldSuggest) {
                $this->completionCoordinator->persistSuggestion((int) $task['id'], $evidence, $decision, (int) ($options['actor_user_id'] ?? $userId));
            }

            if (in_array($controlMode, ['diagnostics_only', 'suggest_only'], true) || $shouldSuggest) {
                $results[] = [
                    'task_id' => (int) $task['id'],
                    'completed' => false,
                    'evidence' => $evidence,
                    'decision' => $decision,
                    'control_mode' => $controlMode !== 'normal' ? $controlMode : 'review',
                    'workspace_mode' => (string) ($workspaceSettings['rollout_mode'] ?? 'review'),
                ];
            }
        }
        return $results;
    }

    public function findMatchingEvidence(array $task): array
    {
        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        $title = strtolower((string) ($task['title'] ?? ''));
        $description = strtolower((string) ($task['description'] ?? ''));
        $contactId = (int) ($task['contact_id'] ?? 0);
        $targetStage = (string) ($metadata['deal_stage'] ?? '');
        $invoiceId = (int) ($metadata['invoice_id'] ?? 0);
        $dependencyTaskId = (int) ($metadata['dependency_task_id'] ?? 0);
        $workspaceId = $this->taskWorkspaceId($task);
        $evidenceTypes = array_values(array_filter(array_map('strval', (array) ($metadata['completion_evidence_types'] ?? []))));
        $taskCreatedAt = trim((string) ($task['created_at'] ?? ''));

        $financeSetupEvidence = $this->findFinanceSetupEvidence($task, $metadata, $title, $description);
        if ($financeSetupEvidence !== []) {
            return $financeSetupEvidence;
        }

        if ($contactId > 0 && (str_contains($title, 'reply') || str_contains($description, 'reply') || str_contains($description, 'follow up'))) {
            $createdAt = trim((string) ($task['created_at'] ?? ''));
            $sql = "SELECT id, created_at FROM communications WHERE contact_id = ? AND direction = 'inbound'";
            $params = [$contactId];
            if ($workspaceId > 0 && Database::columnExists('communications', 'workspace_id')) {
                $sql .= " AND workspace_id = ?";
                $params[] = $workspaceId;
            }
            if ($createdAt !== '') {
                $sql .= " AND created_at >= ?";
                $params[] = $createdAt;
            }
            $sql .= " ORDER BY created_at DESC LIMIT 1";
            $row = Database::queryOne(
                $sql,
                $params
            );
            if ($row) {
                return $this->buildEvidence('email_reply_received', 'communication', (int) $row['id'], 0.98, ['created_at' => $row['created_at']]);
            }
        }

        $originDealStage = (string) ($metadata['origin_deal_stage'] ?? '');
        if (!empty($metadata['deal_id']) && ($targetStage !== '' || ($originDealStage !== '' && in_array('deal_stage_reached', $evidenceTypes, true)))) {
            $sql = "SELECT id, stage, updated_at FROM deals WHERE id = ?";
            $params = [(int) $metadata['deal_id']];
            if ($workspaceId > 0 && Database::columnExists('deals', 'workspace_id')) {
                $sql .= " AND workspace_id = ?";
                $params[] = $workspaceId;
            }
            $deal = Database::queryOne($sql, $params);
            $taskCreatedAt = strtotime((string) ($task['created_at'] ?? '')) ?: 0;
            $dealUpdatedAt = strtotime((string) ($deal['updated_at'] ?? '')) ?: 0;
            $currentStage = (string) ($deal['stage'] ?? '');
            $stageQualifies = $targetStage !== '' ? $currentStage === $targetStage : $currentStage !== '' && $currentStage !== $originDealStage;
            if ($deal && $stageQualifies && ($taskCreatedAt <= 0 || $dealUpdatedAt >= $taskCreatedAt)) {
                return $this->buildEvidence('deal_stage_reached', 'deal', (int) $deal['id'], 0.99, [
                    'stage' => $currentStage,
                    'origin_stage' => $originDealStage,
                ]);
            }
        }

        if ($invoiceId > 0) {
            $sql = "SELECT id, status, created_at, updated_at FROM invoices WHERE id = ?";
            $params = [$invoiceId];
            if ($workspaceId > 0 && Database::columnExists('invoices', 'workspace_id')) {
                $sql .= " AND workspace_id = ?";
                $params[] = $workspaceId;
            }
            $invoice = Database::queryOne($sql, $params);
            $taskCreatedAt = strtotime((string) ($task['created_at'] ?? '')) ?: 0;
            $invoiceObservedAt = strtotime((string) ($invoice['updated_at'] ?? $invoice['created_at'] ?? '')) ?: 0;
            if ($invoice && in_array((string) ($invoice['status'] ?? ''), ['paid', 'sent', 'finalized'], true) && ($taskCreatedAt <= 0 || $invoiceObservedAt >= $taskCreatedAt)) {
                $type = (string) ($invoice['status'] ?? '') === 'paid' ? 'invoice_paid' : 'workflow_step_completed';
                return $this->buildEvidence($type, 'invoice', (int) $invoice['id'], 0.99, ['status' => $invoice['status']]);
            }
        }

        $billingSignals = $this->getBillingEvidenceSignals($task);
        if (!empty($billingSignals['settings_ready']) && !empty($billingSignals['invoice_send_ready'])) {
            $entityId = (int) ($billingSignals['latest_invoice']['id'] ?? 0);
            $entityType = $entityId > 0 ? 'invoice' : 'workflow_execution';
            return $this->buildEvidence(
                'workflow_step_completed',
                $entityType,
                $entityId,
                0.98,
                [
                    'settings_ready' => true,
                    'invoice_send_ready' => true,
                    'invoice_status' => (string) ($billingSignals['latest_invoice']['status'] ?? ''),
                ]
            );
        }

        if ($dependencyTaskId > 0) {
            $sql = "SELECT id, status FROM tasks WHERE id = ?";
            $params = [$dependencyTaskId];
            if ($workspaceId > 0 && Database::columnExists('tasks', 'workspace_id')) {
                $sql .= " AND workspace_id = ?";
                $params[] = $workspaceId;
            }
            $dep = Database::queryOne($sql, $params);
            if ($dep && (string) ($dep['status'] ?? '') === 'completed') {
                return $this->buildEvidence('task_dependency_completed', 'task', (int) $dep['id'], 0.98, []);
            }
        }

        if (in_array('workflow_step_completed', $evidenceTypes, true) && !empty($metadata['workflow_execution_id'])) {
            $workflow = Database::queryOne(
                "SELECT id, completed_at FROM workflow_executions
                 WHERE workspace_id = ? AND id = ? AND status = 'completed'
                   AND (? = '' OR COALESCE(completed_at, executed_at) >= ?)
                 LIMIT 1",
                [$workspaceId, (int) $metadata['workflow_execution_id'], $taskCreatedAt, $taskCreatedAt]
            );
            if ($workflow) {
                return $this->buildEvidence('workflow_step_completed', 'workflow_execution', (int) $workflow['id'], 0.98, [
                    'completed_at' => $workflow['completed_at'] ?? null,
                    'step_id' => $metadata['workflow_step_id'] ?? null,
                ]);
            }
        }

        if (in_array('completed_event', $evidenceTypes, true)) {
            $eventSql = "SELECT id, status, updated_at FROM events WHERE workspace_id = ? AND status = 'completed'";
            $eventParams = [$workspaceId];
            if (!empty($metadata['event_id'])) {
                $eventSql .= ' AND id = ?';
                $eventParams[] = (int) $metadata['event_id'];
            } elseif ($contactId > 0) {
                $eventSql .= ' AND contact_id = ?';
                $eventParams[] = $contactId;
            } else {
                $eventSql .= ' AND (assigned_to = ? OR created_by = ?)';
                $eventParams[] = (int) (($task['assigned_to'] ?? 0) ?: ($task['created_by'] ?? 0));
                $eventParams[] = (int) ($task['created_by'] ?? 0);
            }
            if ($taskCreatedAt !== '') {
                $eventSql .= ' AND updated_at >= ?';
                $eventParams[] = $taskCreatedAt;
            }
            $eventSql .= ' ORDER BY updated_at DESC, id DESC LIMIT 1';
            $event = Database::queryOne($eventSql, $eventParams);
            if ($event) {
                return $this->buildEvidence('completed_event', 'event', (int) $event['id'], 0.98, ['completed_at' => $event['updated_at']]);
            }
        }

        if (in_array('note_created', $evidenceTypes, true)) {
            $note = $this->findTaskRelatedArtifact('notes', $task, $workspaceId, $taskCreatedAt, 'created_by');
            if ($note) {
                return $this->buildEvidence('note_created', 'note', (int) $note['id'], 0.97, ['created_at' => $note['created_at']]);
            }
        }

        if (in_array('document_uploaded', $evidenceTypes, true)) {
            $document = $this->findTaskRelatedArtifact('documents', $task, $workspaceId, $taskCreatedAt, 'uploaded_by');
            if ($document) {
                return $this->buildEvidence('document_uploaded', 'document', (int) $document['id'], 0.97, ['created_at' => $document['created_at']]);
            }
        }

        if (in_array('contact_updated', $evidenceTypes, true) && $contactId > 0) {
            $contact = Database::queryOne(
                "SELECT id, updated_at FROM contacts WHERE workspace_id = ? AND id = ? AND (? = '' OR updated_at >= ?) LIMIT 1",
                [$workspaceId, $contactId, $taskCreatedAt, $taskCreatedAt]
            );
            if ($contact) {
                return $this->buildEvidence('contact_updated', 'contact', (int) $contact['id'], 0.97, ['updated_at' => $contact['updated_at']]);
            }
        }

        return [];
    }

    private function findTaskRelatedArtifact(string $table, array $task, int $workspaceId, string $taskCreatedAt, string $actorColumn): ?array
    {
        if (!in_array($table, ['notes', 'documents'], true)) {
            return null;
        }
        $taskId = (int) ($task['id'] ?? 0);
        $contactId = (int) ($task['contact_id'] ?? 0);
        $sql = "SELECT id, created_at FROM {$table} WHERE workspace_id = ? AND (";
        $params = [$workspaceId];
        $clauses = ['(entity_type = \'task\' AND entity_id = ?)'];
        $params[] = $taskId;
        if ($contactId > 0) {
            $clauses[] = '(entity_type = \'contact\' AND entity_id = ?)';
            $params[] = $contactId;
        }
        $sql .= implode(' OR ', $clauses) . ')';
        if ($taskCreatedAt !== '') {
            $sql .= ' AND created_at >= ?';
            $params[] = $taskCreatedAt;
        }
        $actorId = (int) (($task['assigned_to'] ?? 0) ?: ($task['created_by'] ?? 0));
        if ($actorId > 0 && Database::columnExists($table, $actorColumn)) {
            $sql .= " AND {$actorColumn} = ?";
            $params[] = $actorId;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 1';
        return Database::queryOne($sql, $params) ?: null;
    }

    public function autoCompleteTask(int $taskId, array $evidence, array $options = []): bool
    {
        $task = $this->tasks->getById($taskId);
        if (!$task) {
            return false;
        }
        $decision = is_array($options['decision'] ?? null)
            ? $options['decision']
            : $this->decisionService->decide($task, $evidence, $this->workspaceSettings->get($this->taskWorkspaceId($task)));
        $settings = $this->workspaceSettings->get($this->taskWorkspaceId($task));
        $options['decision'] = $decision;
        $options['workspace_settings'] = $settings;
        if (!$this->shouldAutoComplete($task, $evidence, $options)) {
            return false;
        }
        $completed = $this->completionCoordinator->completeFromEvidence($taskId, $evidence, $decision, $options);
        if ($completed) {
            $updatedTask = $this->tasks->getById($taskId);
            if ($updatedTask) {
                $this->outcomes->recordTaskOutcome($updatedTask, $this->classifier->classifyTaskOutcome($updatedTask, $evidence));
            }
        }
        return $completed;
    }

    public function shouldAutoComplete(array $task, array $evidence, array $policy): bool
    {
        if (empty($evidence)) {
            return false;
        }
        if (in_array((string) ($task['status'] ?? ''), ['completed', 'cancelled'], true)) {
            return false;
        }

        $userId = (int) (($task['assigned_to'] ?? 0) ?: ($task['created_by'] ?? 0));
        if (!$this->preferences->isAIAutoTaskCompletionEnabled($userId)) {
            return false;
        }
        if ($this->preferences->getEffectiveAIGuidanceMode($userId) === '3') {
            return false;
        }
        $control = $this->completionRuntimeControl();
        if ((string) ($control['control_mode'] ?? 'normal') !== 'normal') {
            return false;
        }

        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        $completionMode = (string) ($task['completion_mode'] ?? (!empty($metadata['auto_complete_allowed']) ? 'auto' : 'manual'));
        if ($completionMode !== 'auto') {
            return false;
        }
        if (!empty($metadata['protected_from_auto_complete']) || empty($metadata['auto_complete_allowed'])) {
            return false;
        }

        $workspaceSettings = is_array($policy['workspace_settings'] ?? null)
            ? $policy['workspace_settings']
            : $this->workspaceSettings->get($this->taskWorkspaceId($task));
        if ((string) ($workspaceSettings['rollout_mode'] ?? 'review') !== 'full_auto') {
            return false;
        }
        $decision = is_array($policy['decision'] ?? null) ? $policy['decision'] : [];
        if ($decision === []) {
            if (empty($evidence['evidence_fingerprint'])) {
                $evidence['evidence_fingerprint'] = $this->completionCoordinator->evidenceFingerprint($task, $evidence);
            }
            $decision = $this->decisionService->decide($task, $evidence, $workspaceSettings);
        }
        if ((string) ($decision['decision'] ?? '') !== 'complete') {
            return false;
        }
        if (!empty($workspaceSettings['low_risk_only']) && (string) ($decision['risk'] ?? 'high') !== 'low') {
            return false;
        }
        if (!empty($decision['conflicts']) || !empty($decision['missing_evidence'])) {
            return false;
        }

        $minConfidence = $this->thresholds->getCurrentThreshold('ai_auto_task_completion_min_confidence', 'task_automation', 'task', $userId)
            ?? ($this->preferences->getAIAutoTaskCompletionMinConfidence($userId) ?? (float) ($workspaceSettings['min_confidence'] ?? 0.96));
        $minConfidence = max((float) ($workspaceSettings['min_confidence'] ?? 0.96), (float) $minConfidence);
        return (float) ($decision['confidence'] ?? $evidence['confidence_score'] ?? 0) >= $minConfidence;
    }

    private function tasksForUser(int $userId): array
    {
        $byId = [];
        foreach ([['assigned_to' => $userId], ['created_by' => $userId]] as $ownerFilter) {
            $offset = 0;
            do {
                $page = $this->tasks->getAll(array_merge($ownerFilter, ['active_only' => true]), 200, $offset);
                foreach ($page as $task) {
                    $taskId = (int) ($task['id'] ?? 0);
                    if ($taskId > 0) {
                        $byId[$taskId] = $task;
                    }
                }
                $offset += count($page);
            } while (count($page) === 200);
        }

        return array_values($byId);
    }

    private function assertActorCanScanTask(array $task, int $actorUserId): void
    {
        if ($actorUserId <= 0) {
            throw new \DomainException('Task scan is not allowed for this user.');
        }

        if (
            (int) ($task['assigned_to'] ?? 0) === $actorUserId
            || (int) ($task['created_by'] ?? 0) === $actorUserId
        ) {
            return;
        }

        $workspaceId = $this->taskWorkspaceId($task);
        $actor = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$actorUserId]) ?: [];
        if ($actor && $this->actorCanManageTasks($actor, $workspaceId)) {
            return;
        }

        throw new \DomainException('Task scan is not allowed for this user.');
    }

    private function actorCanManageTasks(array $actor, int $workspaceId): bool
    {
        if ($workspaceId <= 0) {
            return Authorization::can('tasks.write', $actor);
        }

        $snapshot = WorkspaceContext::runtimeSnapshot();
        $currentWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($currentWorkspaceId !== $workspaceId) {
            WorkspaceContext::activateRuntimeWorkspace($workspaceId, (int) ($actor['id'] ?? 0));
        }

        try {
            return Authorization::can('tasks.write', $actor);
        } finally {
            if ($currentWorkspaceId !== $workspaceId) {
                WorkspaceContext::restoreRuntimeWorkspace($snapshot);
            }
        }
    }

    private function completionRuntimeControl(): array
    {
        try {
            return $this->runtimeControls->getEffectiveControl('task_automation');
        } catch (\Throwable $e) {
            return ['control_mode' => 'normal'];
        }
    }

    private function persistCompletionSuggestion(array $task, array $evidence, int $actorUserId): bool
    {
        $taskId = (int) ($task['id'] ?? 0);
        if ($taskId <= 0) {
            return false;
        }

        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        $metadata['completion_review'] = [
            'decision' => 'warn',
            'confidence_score' => (float) ($evidence['confidence_score'] ?? 0.0),
            'recommended_action' => 'review_before_complete',
            'explanation' => 'Completion evidence was found while task automation is in suggest-only mode.',
            'evidence_found' => [[
                'code' => (string) ($evidence['evidence_type'] ?? 'completion_evidence_found'),
                'label' => 'Completion evidence found',
                'detail' => (string) ($evidence['entity_type'] ?? 'entity') . ' #' . (int) ($evidence['entity_id'] ?? 0),
            ]],
            'evidence_missing' => [],
            'evidence_conflicts' => [],
            'reviewed_by' => $actorUserId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'control_mode' => 'suggest_only',
        ];

        try {
            return $this->tasks->update($taskId, [
                'metadata_json' => $metadata,
                'actor_user_id' => 0,
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function taskWorkspaceId(array $task): int
    {
        $workspaceId = (int) ($task['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        }
        return $workspaceId;
    }

    private function buildEvidence(string $type, string $entityType, int $entityId, float $confidence, array $payload): array
    {
        return [
            'evidence_type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'confidence_score' => $confidence,
            'evidence_json' => $payload,
        ];
    }

    private function findFinanceSetupEvidence(array $task, array $metadata, string $title, string $description): array
    {
        if (!$this->isFinanceSetupTask($task, $metadata, $title, $description)) {
            return [];
        }

        $workspaceId = (int) ($task['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        }
        if ($workspaceId <= 0) {
            return [];
        }

        try {
            $status = (new WorkspaceFinanceGateService())->status($workspaceId, null);
        } catch (\Throwable $e) {
            return [];
        }

        if (empty($status['ready'])) {
            return [];
        }

        return $this->buildEvidence(
            'finance_setup_ready',
            'workspace_skill',
            $workspaceId,
            0.98,
            [
                'workspace_id' => $workspaceId,
                'status' => (string) ($status['status'] ?? 'ready'),
                'message' => (string) ($status['message'] ?? 'Finance is ready.'),
                'checks' => (array) ($status['checks'] ?? []),
            ]
        );
    }

    private function isFinanceSetupTask(array $task, array $metadata, string $title, string $description): bool
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

        $text = trim($title . ' ' . $description);
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

    public function syncSubtasksFromEvidence(array $task): array
    {
        $taskId = (int) ($task['id'] ?? 0);
        if ($taskId <= 0 || in_array((string) ($task['status'] ?? ''), ['completed', 'cancelled'], true)) {
            return ['updated_subtasks' => 0, 'task_completed' => false];
        }

        $subtasks = $this->tasks->getSubtasks($taskId);
        if ($subtasks === []) {
            return ['updated_subtasks' => 0, 'task_completed' => false];
        }

        $signals = $this->getBillingEvidenceSignals($task);
        if (empty($signals['applicable'])) {
            return ['updated_subtasks' => 0, 'task_completed' => false];
        }

        $syncEvidence = $this->buildEvidence(
            'workflow_step_completed',
            'task',
            $taskId,
            0.98,
            ['source' => 'subtask_evidence_sync']
        );
        if (!$this->shouldAutoComplete($task, $syncEvidence, [])) {
            return ['updated_subtasks' => 0, 'task_completed' => false, 'signals' => $signals];
        }

        $updated = 0;
        foreach ($subtasks as $subtask) {
            if (!empty($subtask['completed'])) {
                continue;
            }

            $subtaskTitle = strtolower(trim((string) ($subtask['title'] ?? '')));
            $shouldComplete = false;

            if (
                str_contains($subtaskTitle, 'design')
                && !empty($signals['design_ready'])
            ) {
                $shouldComplete = true;
            } elseif (
                (str_contains($subtaskTitle, 'billing') || str_contains($subtaskTitle, 'company') || str_contains($subtaskTitle, 'details'))
                && !empty($signals['settings_ready'])
            ) {
                $shouldComplete = true;
            } elseif (
                (str_contains($subtaskTitle, 'send') || str_contains($subtaskTitle, 'test invoice'))
                && !empty($signals['invoice_send_ready'])
            ) {
                $shouldComplete = true;
            }

            if ($shouldComplete) {
                $this->tasks->updateSubtask((int) $subtask['id'], ['completed' => 1]);
                $updated++;
            }
        }

        $freshTask = $this->tasks->getById($taskId);
        return [
            'updated_subtasks' => $updated,
            'task_completed' => (($freshTask['status'] ?? '') === 'completed'),
            'signals' => $signals,
        ];
    }

    public function getBillingEvidenceSignals(array $task): array
    {
        $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
        $text = strtolower((string) ($task['title'] ?? '') . ' ' . (string) ($task['description'] ?? ''));
        $applicable = !empty($metadata['auto_complete_allowed'])
            && (
                ($metadata['task_intent'] ?? '') === 'billing'
                || in_array('invoice_paid', (array) ($metadata['completion_evidence_types'] ?? []), true)
                || str_contains($text, 'invoice')
                || str_contains($text, 'invoicing')
                || str_contains($text, 'billing')
            );

        if (!$applicable) {
            return ['applicable' => false];
        }

        $workspaceId = $this->taskWorkspaceId($task);
        $settings = Database::queryOne("SELECT * FROM invoice_settings ORDER BY id ASC LIMIT 1") ?: [];
        $invoiceSql = "SELECT id, status, document_type, created_by, assigned_to, created_at
             FROM invoices
             WHERE (created_by = ? OR assigned_to = ?)
               AND status <> 'cancelled'";
        $invoiceParams = [(int) (($task['assigned_to'] ?? 0) ?: ($task['created_by'] ?? 0)), (int) (($task['assigned_to'] ?? 0) ?: ($task['created_by'] ?? 0))];
        if ($workspaceId > 0 && Database::columnExists('invoices', 'workspace_id')) {
            $invoiceSql .= " AND workspace_id = ?";
            $invoiceParams[] = $workspaceId;
        }
        $invoiceSql .= " ORDER BY created_at DESC, id DESC LIMIT 1";
        $latestInvoice = Database::queryOne(
            $invoiceSql,
            $invoiceParams
        ) ?: [];

        $designReady = !empty($settings['logo_asset_path'])
            || trim((string) ($settings['footer_text'] ?? '')) !== ''
            || trim((string) ($settings['proposal_intro_text'] ?? '')) !== ''
            || trim((string) ($settings['visual_theme'] ?? 'classic')) !== 'classic';

        $settingsReady = !empty($settings['enabled'])
            && trim((string) ($settings['company_legal_name'] ?? '')) !== ''
            && trim((string) ($settings['company_address'] ?? '')) !== ''
            && trim((string) ($settings['company_email'] ?? '')) !== ''
            && (
                trim((string) ($settings['company_phone'] ?? '')) !== ''
                || (
                    trim((string) ($settings['bank_name'] ?? '')) !== ''
                    && trim((string) ($settings['bank_account_number'] ?? '')) !== ''
                )
            );

        $invoiceStatus = strtolower((string) ($latestInvoice['status'] ?? ''));
        $invoiceSendReady = in_array($invoiceStatus, ['sent', 'viewed', 'accepted', 'finalized', 'partially_paid', 'paid', 'overdue'], true);

        return [
            'applicable' => true,
            'design_ready' => $designReady,
            'settings_ready' => $settingsReady,
            'invoice_send_ready' => $invoiceSendReady,
            'latest_invoice' => $latestInvoice,
        ];
    }
}
