<?php
/**
 * Tasks Management Module
 * 
 * Handles task CRUD operations
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Concurrency;
use CRM\Security;
use CRM\Modules\Notifications;
use CRM\Services\OutcomeEventService;
use CRM\Services\AIDecisionOutcomeService;
use CRM\Services\AIOutcomeClassifier;
use CRM\Services\ContactIntelligenceService;
use CRM\Services\TargetIntelligenceService;
use CRM\Services\AIDemonstrationCaptureService;
use CRM\Services\AIWorkspaceScopeService;
use CRM\Services\CoreEntityLifecycleEventService;
use CRM\Services\PluginRuntimeEventService;
use CRM\Services\TaskAssignmentAccessService;
use CRM\Services\TaskCompletionCoordinator;
use CRM\Services\TaskFollowthroughAutonomyService;
use CRM\Services\TaskPluginIntegrationService;
use CRM\Services\WorkspaceScopeService;

class Tasks
{
    private const ALLOWED_STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];
    private const ALLOWED_PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /** @var bool|null Cached check for target_id column on tasks table */
    private static $hasTargetIdColumn = null;
    /** @var bool|null Cached check for metadata_json column on tasks table */
    private static $hasMetadataJsonColumn = null;
    /** @var array<string, bool> Cached checks for optional runtime source columns */
    private static array $sourceColumnCache = [];
    /** @var bool|null Cached check for task_subtasks schema health */
    private static $taskSubtasksSchemaReady = null;
    private bool $lastCreateWasDeduplicated = false;
    private TaskAssignmentAccessService $assignmentAccess;
    private WorkspaceScopeService $workspaceScope;
    private AIWorkspaceScopeService $aiWorkspaceScope;
    private TaskPluginIntegrationService $taskPluginIntegration;
    private CoreEntityLifecycleEventService $lifecycleEvents;

    public function __construct()
    {
        $this->assignmentAccess = new TaskAssignmentAccessService();
        $this->workspaceScope = new WorkspaceScopeService();
        $this->aiWorkspaceScope = new AIWorkspaceScopeService($this->workspaceScope);
        $this->taskPluginIntegration = new TaskPluginIntegrationService();
        $this->lifecycleEvents = new CoreEntityLifecycleEventService();
    }

    /**
     * Check if tasks table has target_id column (for backward compatibility before migration 059).
     */
    private function hasTargetIdColumn(): bool
    {
        if (self::$hasTargetIdColumn !== null) {
            return self::$hasTargetIdColumn;
        }
        try {
            $row = Database::queryOne(
                "SELECT 1 FROM information_schema.COLUMNS 
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'target_id'"
            );
            self::$hasTargetIdColumn = !empty($row);
        } catch (\Throwable $e) {
            self::$hasTargetIdColumn = false;
        }
        return self::$hasTargetIdColumn;
    }

    private function hasMetadataJsonColumn(): bool
    {
        if (self::$hasMetadataJsonColumn !== null) {
            return self::$hasMetadataJsonColumn;
        }
        try {
            $row = Database::queryOne(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'metadata_json'"
            );
            self::$hasMetadataJsonColumn = !empty($row);
        } catch (\Throwable $e) {
            self::$hasMetadataJsonColumn = false;
        }
        return self::$hasMetadataJsonColumn;
    }

    private function ensureTaskSubtasksSchema(): void
    {
        if (self::$taskSubtasksSchemaReady === true) {
            return;
        }

        $columns = Database::query(
            "SELECT COLUMN_NAME, COLUMN_KEY, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_subtasks'
             ORDER BY ORDINAL_POSITION"
        );

        if (empty($columns)) {
            self::$taskSubtasksSchemaReady = false;
            return;
        }

        $idColumn = null;
        foreach ($columns as $column) {
            if (($column['COLUMN_NAME'] ?? '') === 'id') {
                $idColumn = $column;
                break;
            }
        }

        $healthy = $idColumn
            && (($idColumn['COLUMN_KEY'] ?? '') === 'PRI')
            && stripos((string) ($idColumn['EXTRA'] ?? ''), 'auto_increment') !== false;

        if ($healthy) {
            self::$taskSubtasksSchemaReady = true;
            return;
        }

        $repairTable = 'task_subtasks_repair_' . date('YmdHis');
        Database::beginTransaction();
        try {
            Database::execute(
                "CREATE TABLE {$repairTable} (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    task_id INT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    description TEXT,
                    completed TINYINT(1) DEFAULT 0,
                    `order` INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_task_id (task_id),
                    INDEX idx_task_order (task_id, `order`),
                    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            Database::execute(
                "INSERT INTO {$repairTable} (task_id, title, description, completed, `order`, created_at, updated_at)
                 SELECT task_id, title, description, completed, `order`, created_at, updated_at
                 FROM task_subtasks
                 ORDER BY task_id ASC, `order` ASC, created_at ASC, title ASC"
            );

            Database::execute("DROP TABLE task_subtasks");
            Database::execute("RENAME TABLE {$repairTable} TO task_subtasks");
            Database::commit();
            self::$taskSubtasksSchemaReady = true;
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            self::$taskSubtasksSchemaReady = false;
            error_log('Tasks::ensureTaskSubtasksSchema failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a new task
     */
    public function create(array $data): int
    {
        $this->lastCreateWasDeduplicated = false;
        $workspaceId = $this->workspaceId();
        // Validate required fields
        if (empty($data['title'])) {
            throw new \Exception("Task title is required");
        }
        
        // Sanitize inputs
        $title = Security::sanitizeInput($data['title'], 'string');
        $description = Security::sanitizeInput($data['description'] ?? '', 'string');
        $contactId = !empty($data['contact_id']) ? (int) $data['contact_id'] : null;
        $targetId = $this->hasTargetIdColumn() && !empty($data['target_id']) ? (int) $data['target_id'] : null;
        $assignedTo = !empty($data['assigned_to']) ? (int) $data['assigned_to'] : null;
        $createdBy = (int) ($data['created_by'] ?? $_SESSION['user_id'] ?? 0);
        $actorUserId = (int) ($data['actor_user_id'] ?? $_SESSION['user_id'] ?? 0);
        $assignmentMode = (string) ($data['assignment_mode'] ?? 'manual');
        $status = $this->normalizeStatus($data['status'] ?? 'pending');
        $priority = $this->normalizePriority($data['priority'] ?? 'medium');
        $dueDate = $this->normalizeDueDate($data['due_date'] ?? null);
        $metadata = $this->decodeMetadataArray($data['metadata_json'] ?? null);

        if ($title === '') {
            throw new \Exception("Task title is required");
        }
        if ($actorUserId > 0) {
            $this->assignmentAccess->assertCanCreateOrEdit($actorUserId);
        }
        if ($contactId !== null) {
            $this->workspaceScope->assertSameWorkspace('contacts', $contactId, $workspaceId);
        }
        if ($targetId !== null) {
            $this->workspaceScope->assertSameWorkspace('targets', $targetId, $workspaceId);
        }
        $this->assertAssignmentTarget($assignedTo, $assignmentMode === 'ai', $this->buildAiAssignmentContext($data));

        $payload = $this->taskPluginIntegration->prepareCreate($workspaceId, $actorUserId, [
            'workspace_id' => $workspaceId,
            'title' => $title,
            'description' => $description,
            'contact_id' => $contactId,
            'target_id' => $targetId,
            'metadata_json' => $metadata,
            'assigned_to' => $assignedTo,
            'created_by' => $createdBy,
            'status' => $status,
            'priority' => $priority,
            'due_date' => $dueDate,
            'source_skill_key' => $data['source_skill_key'] ?? null,
            'source_plugin_key' => $data['source_plugin_key'] ?? null,
            'source_surface' => $data['source_surface'] ?? null,
            'source_capability_key' => $data['source_capability_key'] ?? null,
            'source_run_id' => $data['source_run_id'] ?? null,
            'origin_type' => $data['origin_type'] ?? null,
            'completion_mode' => $data['completion_mode'] ?? null,
            'automation_dedupe_key' => $data['automation_dedupe_key'] ?? null,
        ]);

        $title = Security::sanitizeInput((string) ($payload['title'] ?? $title), 'string');
        $description = Security::sanitizeInput((string) ($payload['description'] ?? $description), 'string');
        $contactId = !empty($payload['contact_id']) ? (int) $payload['contact_id'] : null;
        $targetId = $this->hasTargetIdColumn() && !empty($payload['target_id']) ? (int) $payload['target_id'] : null;
        if ($targetId !== null) {
            $this->workspaceScope->assertSameWorkspace('targets', $targetId, $workspaceId);
        }
        $assignedTo = !empty($payload['assigned_to']) ? (int) $payload['assigned_to'] : null;
        $status = $this->normalizeStatus($payload['status'] ?? $status);
        $priority = $this->normalizePriority($payload['priority'] ?? $priority);
        $dueDate = $this->normalizeDueDate($payload['due_date'] ?? $dueDate);
        $metadata = $this->decodeMetadataArray($payload['metadata_json'] ?? []);
        $source = $this->sourceColumnsFromPayload($payload);
        $v2 = $this->taskV2ColumnsFromPayload($payload, $metadata, $source, $title);
        $metadata['origin_type'] = $v2['origin_type'];
        $metadata['completion_mode'] = $v2['completion_mode'];
        if ($assignmentMode === 'ai') {
            $metadata['assignment_mode'] = 'ai';
        }
        foreach ($source as $sourceColumn => $sourceValue) {
            if ($sourceValue !== null && $sourceValue !== '') {
                $metadata[$sourceColumn] = $sourceValue;
            }
        }
        if (!empty($v2['automation_dedupe_key'])) {
            $metadata['automation_dedupe_key'] = $v2['automation_dedupe_key'];
        }
        $metadataJson = $this->hasMetadataJsonColumn() ? $this->normalizeMetadataJson($metadata) : null;

        if (!empty($v2['automation_dedupe_key'])) {
            $existing = Database::queryOne(
                "SELECT id FROM tasks WHERE workspace_id = ? AND automation_dedupe_key = ? LIMIT 1",
                [$workspaceId, $v2['automation_dedupe_key']]
            );
            if ($existing) {
                $this->lastCreateWasDeduplicated = true;
                return (int) $existing['id'];
            }
        }

        $columns = ['workspace_id', 'title', 'description', 'contact_id'];
        $values = [$workspaceId, $title, $description, $contactId];
        if ($this->hasTargetIdColumn()) {
            $columns[] = 'target_id';
            $values[] = $targetId;
        }
        if ($this->hasMetadataJsonColumn()) {
            $columns[] = 'metadata_json';
            $values[] = $metadataJson;
        }
        foreach ($source as $column => $value) {
            if ($this->hasSourceColumn($column)) {
                $columns[] = $column;
                $values[] = $value;
            }
        }
        foreach ($v2 as $column => $value) {
            if (Database::columnExists('tasks', $column)) {
                $columns[] = $column;
                $values[] = $value;
            }
        }
        $columns = array_merge($columns, ['assigned_to', 'created_by', 'status', 'priority', 'due_date']);
        $values = array_merge($values, [$assignedTo, $createdBy, $status, $priority, $dueDate]);

        Database::execute(
            "INSERT INTO tasks (" . implode(', ', $columns) . ")
             VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")",
            $values
        );
        
        $taskId = (int) Database::lastInsertId();
        
        if (empty($data['_defer_post_create'])) {
            $this->runPostCreateEffects($taskId, $workspaceId, $actorUserId, $createdBy, $title, $contactId, $targetId, $assignedTo, $status, $payload, $source);
        }

        return $taskId;
    }

    private function runPostCreateEffects(int $taskId, int $workspaceId, int $actorUserId, int $createdBy, string $title, ?int $contactId, ?int $targetId, ?int $assignedTo, string $status, array $payload, array $source): void
    {
        // Log activity if task is associated with a contact
        if ($contactId) {
            $activities = new Activities();
            $activities->log($contactId, 'note', "Task created: $title", [
                'task_id' => $taskId,
                'by' => $createdBy
            ]);
        }

        $createdTask = $this->getById($taskId) ?: array_merge($payload, ['id' => $taskId]);
        $this->lifecycleEvents->emit('task.created', 'task', $taskId, $createdTask, [
            'workspace_id' => $workspaceId,
            'actor_user_id' => $actorUserId,
            'source_skill_key' => $source['source_skill_key'] ?? '',
            'source_plugin_key' => $source['source_plugin_key'] ?? '',
            'source_capability_key' => $source['source_capability_key'] ?? '',
            'metadata' => ['created_by' => $createdBy],
        ]);
        $this->taskPluginIntegration->handleLifecycle('task.created', $workspaceId, $actorUserId, $createdTask);
        $this->recordRuntimeTaskEvent($workspaceId, $actorUserId, $createdTask, 'task_created');
        
        $this->notifyAssigneeChange($assignedTo, null, $taskId, $title);

        $this->refreshContactIntelligence($contactId);
        try {
            (new TargetIntelligenceService())->refreshAfterEntityChange('tasks', $taskId, [
                'contact_id' => $contactId,
                'target_id' => $targetId,
                'assigned_to' => $assignedTo,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            error_log('Tasks::create target intelligence refresh failed: ' . $e->getMessage());
        }
        
    }
    
    /**
     * Get task by ID
     */
    public function getById(int $id): ?array
    {
        $workspace = $this->workspaceClause('t.');
        if ($this->hasTargetIdColumn()) {
            $task = Database::queryOne(
                "SELECT t.*, 
                        c.first_name as contact_first_name, 
                        c.last_name as contact_last_name, 
                        c.email as contact_email,
                        tg.title as target_title,
                        tg.target_date as target_deadline,
                        u1.email as assigned_to_email,
                        u2.email as created_by_email
                 FROM tasks t
                 LEFT JOIN contacts c ON t.contact_id = c.id AND c.workspace_id = t.workspace_id
                 LEFT JOIN targets tg ON t.target_id = tg.id AND tg.workspace_id = t.workspace_id
                 LEFT JOIN users u1 ON t.assigned_to = u1.id
                 LEFT JOIN users u2 ON t.created_by = u2.id
                 WHERE {$workspace['sql']}
                   AND t.id = ?",
                array_merge($workspace['params'], [$id])
            );
        } else {
            $task = Database::queryOne(
                "SELECT t.*, 
                        c.first_name as contact_first_name, 
                        c.last_name as contact_last_name, 
                        c.email as contact_email,
                        u1.email as assigned_to_email,
                        u2.email as created_by_email
                 FROM tasks t
                 LEFT JOIN contacts c ON t.contact_id = c.id AND c.workspace_id = t.workspace_id
                 LEFT JOIN users u1 ON t.assigned_to = u1.id
                 LEFT JOIN users u2 ON t.created_by = u2.id
                 WHERE {$workspace['sql']}
                   AND t.id = ?",
                array_merge($workspace['params'], [$id])
            );
            if ($task) {
                $task['target_id'] = null;
                $task['target_title'] = null;
                $task['target_deadline'] = null;
            }
        }
        
        return $task;
    }
    
    /**
     * Update task
     */
    public function update(int $id, array $data): bool
    {
        $task = $this->getById($id);
        if (!$task) {
            throw new \Exception("Task not found");
        }
        
        $actorUserId = (int) ($data['actor_user_id'] ?? $_SESSION['user_id'] ?? 0);
        $assignmentMode = (string) ($data['assignment_mode'] ?? 'manual');
        if ($actorUserId > 0) {
            $this->assignmentAccess->assertCanCreateOrEdit($actorUserId);
        }

        if (empty($data['_completion_coordinator']) && array_key_exists('status', $data)) {
            $requestedStatus = $this->normalizeStatus($data['status']);
            if ($requestedStatus === 'completed' && (string) ($task['status'] ?? '') !== 'completed') {
                return (new TaskCompletionCoordinator())->completeManually($id, $data);
            }
            if ($requestedStatus !== 'completed' && (string) ($task['status'] ?? '') === 'completed') {
                return (new TaskCompletionCoordinator())->reopen($id, array_merge($data, ['status' => $requestedStatus]));
            }
        }

        $aiAssignmentContext = $this->buildAiAssignmentContext($data, $task);

        $updates = [];
        $params = [];
        $submittedFields = [];
        
        $allowedFields = ['title', 'description', 'contact_id', 'assigned_to', 'status', 'priority', 'due_date'];
        foreach (['origin_type', 'completion_mode', 'automation_dedupe_key'] as $v2Field) {
            if (Database::columnExists('tasks', $v2Field)) {
                $allowedFields[] = $v2Field;
            }
        }
        if ($this->hasTargetIdColumn()) {
            $allowedFields[] = 'target_id';
        }
        if ($this->hasMetadataJsonColumn()) {
            $allowedFields[] = 'metadata_json';
        }
        foreach (['source_skill_key', 'source_plugin_key', 'source_surface', 'source_capability_key', 'source_run_id'] as $sourceField) {
            if ($this->hasSourceColumn($sourceField)) {
                $allowedFields[] = $sourceField;
            }
        }
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'title' || $field === 'description') {
                    $value = Security::sanitizeInput($data[$field], 'string');
                    if ($field === 'title' && $value === '') {
                        throw new \Exception("Task title is required");
                    }
                } elseif ($field === 'contact_id' || $field === 'target_id' || $field === 'assigned_to') {
                    $value = !empty($data[$field]) ? (int) $data[$field] : null;
                } elseif ($field === 'metadata_json') {
                    $value = $this->normalizeMetadataJson($data[$field]);
                } elseif ($field === 'origin_type') {
                    $value = in_array((string) $data[$field], ['manual', 'ai', 'automation', 'import'], true) ? (string) $data[$field] : 'manual';
                } elseif ($field === 'completion_mode') {
                    $value = in_array((string) $data[$field], ['manual', 'review', 'auto'], true) ? (string) $data[$field] : 'manual';
                } elseif ($field === 'automation_dedupe_key') {
                    $value = trim((string) $data[$field]) !== '' ? substr(trim((string) $data[$field]), 0, 191) : null;
                } elseif (str_starts_with($field, 'source_')) {
                    $value = $this->normalizeSourceValue((string) $data[$field], $field === 'source_capability_key');
                } elseif ($field === 'status') {
                    $value = $this->normalizeStatus($data[$field]);
                } elseif ($field === 'priority') {
                    $value = $this->normalizePriority($data[$field]);
                } elseif ($field === 'due_date') {
                    $value = $this->normalizeDueDate($data[$field]);
                } else {
                    $value = $data[$field];
                }
                
                $updates[] = "$field = ?";
                $params[] = $value;
                $submittedFields[$field] = $value;
            }
        }

        $assignedToBefore = !empty($task['assigned_to']) ? (int) $task['assigned_to'] : null;
        $assignedToAfter = array_key_exists('assigned_to', $data)
            ? (!empty($data['assigned_to']) ? (int) $data['assigned_to'] : null)
            : $assignedToBefore;
        if (array_key_exists('assigned_to', $data) && $assignedToAfter !== $assignedToBefore) {
            if ($actorUserId > 0) {
                $this->assignmentAccess->assertCanReassign($actorUserId);
            }
            $this->assertAssignmentTarget($assignedToAfter, $assignmentMode === 'ai', $aiAssignmentContext);
        }
        if (array_key_exists('contact_id', $data) && !empty($data['contact_id'])) {
            $this->workspaceScope->assertSameWorkspace('contacts', (int) $data['contact_id'], $this->workspaceId());
        }
        if (array_key_exists('target_id', $data) && !empty($data['target_id'])) {
            $this->workspaceScope->assertSameWorkspace('targets', (int) $data['target_id'], $this->workspaceId());
        }
        
        // Handle completion
        $wasCompleted = $task['status'] === 'completed';
        $wasEvidenceCompleted = !empty($this->decodeMetadataArray($task['metadata_json'] ?? null)['completed_by_evidence']);
        if (isset($data['status']) && $data['status'] === 'completed' && !$wasCompleted) {
            $updates[] = "completed_at = NOW()";
        } elseif (isset($data['status']) && $data['status'] !== 'completed' && $wasCompleted) {
            $updates[] = "completed_at = NULL";
        }
        
        if (empty($updates)) {
            return false;
        }
        
        Concurrency::executeWorkspaceUpdate(
            'tasks',
            $this->workspaceId(),
            $id,
            $updates,
            $params,
            Concurrency::expectedVersionFromData($data),
            fn() => $this->getById($id),
            $submittedFields,
            'task'
        );

        if (isset($data['status']) && $data['status'] === 'completed') {
            try {
                $this->ensureTaskSubtasksSchema();
                Database::execute("UPDATE task_subtasks SET completed = 1 WHERE task_id = ?", [$id]);
            } catch (\Throwable $e) {
                error_log('Tasks::update subtask completion sync failed: ' . $e->getMessage());
            }
        }
        
        // Publish event for task completion
        if (isset($data['status']) && $data['status'] === 'completed' && !$wasCompleted) {
            \CRM\EventBus::publish('task.completed', [
                'task_id' => $id,
                'contact_id' => $task['contact_id'],
                'task' => array_merge($task, $data)
            ]);
            $updatedTaskForEvent = $this->getById($id) ?: array_merge($task, $data);
            $this->lifecycleEvents->emit('task.completed', 'task', $id, $updatedTaskForEvent, [
                'workspace_id' => (int) ($updatedTaskForEvent['workspace_id'] ?? $this->workspaceId()),
                'actor_user_id' => $actorUserId,
                'changes' => ['status' => 'completed'],
            ]);
            try {
                $outcomes = new OutcomeEventService();
                if ($outcomes->isQualifiedFollowUpTask($task, $data)) {
                    $outcomes->track('task.followup.completed', [
                        'user_id' => (int) ($task['assigned_to'] ?? $task['created_by'] ?? ($_SESSION['user_id'] ?? 0)),
                        'contact_id' => (int) ($task['contact_id'] ?? 0),
                        'event_source' => 'tasks.update',
                        'metadata' => [
                            'task_id' => $id,
                            'qualified_followup' => true,
                            'title' => (string) ($task['title'] ?? ''),
                        ],
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('Tasks::update outcome tracking failed: ' . $e->getMessage());
            }
            try {
                $updatedTask = $this->getById($id);
                if ($updatedTask) {
                    (new AIDecisionOutcomeService())->recordTaskOutcome($updatedTask, (new AIOutcomeClassifier())->classifyTaskOutcome($updatedTask));
                    (new AIDemonstrationCaptureService())->capture([
                        'workspace_id' => (int) ($updatedTask['workspace_id'] ?? $this->workspaceId()),
                        'tenant_key' => $this->aiWorkspaceScope->workspaceTenantKey((int) ($updatedTask['workspace_id'] ?? $this->workspaceId())),
                        'actor_user_id' => (int) ($_SESSION['user_id'] ?? 0),
                        'actor_type' => 'user',
                        'source_surface' => 'task_update',
                        'domain_key' => 'task_followthrough',
                        'entity_type' => 'task',
                        'entity_id' => $id,
                        'action_key' => 'complete_task',
                        'prior_state' => ['status' => $task['status']],
                        'action_payload' => ['status' => 'completed'],
                        'outcome_state' => ['status' => 'completed'],
                        'outcome_label' => 'accepted',
                        'linked_task_id' => $id,
                        'metadata' => [
                            'task_status' => 'completed',
                            'task_intent' => (string) ($this->decodeMetadataArray($updatedTask['metadata_json'] ?? null)['task_intent'] ?? ''),
                        ],
                        'was_successful' => true,
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('Tasks::update AI outcome tracking failed: ' . $e->getMessage());
            }
            try {
                (new TaskFollowthroughAutonomyService())->runForTask($id, 'task_completed', (int) ($_SESSION['user_id'] ?? 0));
            } catch (\Throwable $e) {
                error_log('Tasks::update task followthrough autonomy failed: ' . $e->getMessage());
            }
        } elseif (isset($data['status']) && $data['status'] !== 'completed' && $wasCompleted && $wasEvidenceCompleted) {
            try {
                $updatedTask = $this->getById($id);
                if ($updatedTask) {
                    (new AIDecisionOutcomeService())->recordTaskOutcome($updatedTask, [
                        'outcome_label' => 'reversed',
                        'outcome_score' => 0.0,
                        'metadata' => ['task_id' => $id, 'reason' => 'task_reopened_after_evidence_completion'],
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('Tasks::update AI reversal tracking failed: ' . $e->getMessage());
            }
        }

        if (isset($data['status']) && in_array($data['status'], ['pending', 'in_progress'], true)) {
            if ($wasCompleted) {
                $updatedTaskForEvent = $this->getById($id) ?: array_merge($task, $data);
                $this->lifecycleEvents->emit('task.reopened', 'task', $id, $updatedTaskForEvent, [
                    'workspace_id' => (int) ($updatedTaskForEvent['workspace_id'] ?? $this->workspaceId()),
                    'actor_user_id' => $actorUserId,
                    'changes' => ['status' => (string) $data['status']],
                ]);
            }
            try {
                (new TaskFollowthroughAutonomyService())->runForTask($id, 'task_update', (int) ($_SESSION['user_id'] ?? 0));
            } catch (\Throwable $e) {
                error_log('Tasks::update task followthrough autonomy failed: ' . $e->getMessage());
            }
        }
        
        // Log activity if task is associated with a contact
        if ($task['contact_id']) {
            $activities = new Activities();
            $activities->log($task['contact_id'], 'note', "Task updated: " . ($data['title'] ?? $task['title']), [
                'task_id' => $id,
                'by' => $_SESSION['user_id'] ?? null,
                'changes' => $data
            ]);
        }

        $this->refreshContactIntelligence(
            !empty($task['contact_id']) ? (int) $task['contact_id'] : null,
            array_key_exists('contact_id', $data) && !empty($data['contact_id']) ? (int) $data['contact_id'] : null
        );
        try {
            $updatedTask = $this->getById($id);
            if ($assignedToAfter !== $assignedToBefore) {
                $this->notifyAssigneeChange($assignedToAfter, $assignedToBefore, $id, (string) ($updatedTask['title'] ?? $task['title'] ?? 'Task'));
                $this->captureReassignment($task, $updatedTask ?: array_merge($task, ['assigned_to' => $assignedToAfter]), $actorUserId);
                $this->lifecycleEvents->emit('task.assigned', 'task', $id, $updatedTask ?: array_merge($task, ['assigned_to' => $assignedToAfter]), [
                    'workspace_id' => (int) ($updatedTask['workspace_id'] ?? $this->workspaceId()),
                    'actor_user_id' => $actorUserId,
                    'changes' => ['assigned_to' => $assignedToAfter, 'previous_assigned_to' => $assignedToBefore],
                ]);
            }
            if ($updatedTask) {
                $this->lifecycleEvents->emit('task.updated', 'task', $id, $updatedTask, [
                    'workspace_id' => (int) ($updatedTask['workspace_id'] ?? $this->workspaceId()),
                    'actor_user_id' => $actorUserId,
                    'changes' => $data,
                ]);
                $this->taskPluginIntegration->handleLifecycle('task.updated', (int) ($updatedTask['workspace_id'] ?? $this->workspaceId()), $actorUserId, $updatedTask, ['changes' => $data]);
            }
            (new TargetIntelligenceService())->refreshAfterEntityChange('tasks', $id, [
                'contact_id' => (int) ($updatedTask['contact_id'] ?? 0),
                'target_id' => (int) ($updatedTask['target_id'] ?? 0),
                'assigned_to' => (int) ($updatedTask['assigned_to'] ?? 0),
                'status' => (string) ($updatedTask['status'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            error_log('Tasks::update target intelligence refresh failed: ' . $e->getMessage());
        }
        
        return true;
    }

    private function assertAssignmentTarget(?int $assignedTo, bool $isAiAssignment, array $taskContext = []): void
    {
        if (empty($assignedTo)) {
            return;
        }

        $valid = $isAiAssignment
            ? $this->assignmentAccess->isAiAssigneeValid($assignedTo, $taskContext)
            : $this->assignmentAccess->isManualAssigneeValid($assignedTo, $taskContext);

        if (!$valid) {
            throw new \RuntimeException($isAiAssignment
                ? 'Selected assignee is not eligible to receive AI-assigned tasks.'
                : 'Selected assignee is not valid for task assignment.');
        }
    }

    private function buildAiAssignmentContext(array $data, ?array $existingTask = null): array
    {
        $metadata = $data['metadata_json'] ?? ($existingTask['metadata_json'] ?? null);
        $title = array_key_exists('title', $data) ? (string) ($data['title'] ?? '') : (string) ($existingTask['title'] ?? '');
        $description = array_key_exists('description', $data) ? (string) ($data['description'] ?? '') : (string) ($existingTask['description'] ?? '');

        return [
            'title' => $title,
            'description' => $description,
            'metadata_json' => $metadata,
        ];
    }

    private function notifyAssigneeChange(?int $newAssigneeId, ?int $previousAssigneeId, int $taskId, string $taskTitle): void
    {
        if (empty($newAssigneeId) || $newAssigneeId === $previousAssigneeId) {
            return;
        }

        $taskTitle = trim($taskTitle) !== '' ? trim($taskTitle) : 'Untitled task';
        $notificationTitle = $previousAssigneeId ? 'Reassigned: ' . $taskTitle : $taskTitle;
        $notificationMessage = $previousAssigneeId
            ? 'This task has been reassigned to you.'
            : 'You have been assigned this task.';

        $notifications = new Notifications();
        $notifications->create(
            $newAssigneeId,
            'task_assigned',
            $notificationTitle,
            $notificationMessage,
            [
                'entity_type' => 'task',
                'entity_id' => $taskId,
                'link' => publicUrl("task_view.php?id=$taskId"),
            ]
        );
    }

    private function captureReassignment(array $before, array $after, int $actorUserId): void
    {
        try {
            (new AIDemonstrationCaptureService())->capture([
                'workspace_id' => (int) ($after['workspace_id'] ?? $this->workspaceId()),
                'tenant_key' => $this->aiWorkspaceScope->workspaceTenantKey((int) ($after['workspace_id'] ?? $this->workspaceId())),
                'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
                'actor_type' => 'user',
                'source_surface' => 'task_reassignment',
                'domain_key' => 'task_followthrough',
                'entity_type' => 'task',
                'entity_id' => (int) ($after['id'] ?? 0),
                'linked_task_id' => (int) ($after['id'] ?? 0),
                'action_key' => 'reassign_task',
                'prior_state' => ['assigned_to' => $before['assigned_to'] ?? null],
                'action_payload' => ['assigned_to' => $after['assigned_to'] ?? null],
                'outcome_state' => ['assigned_to' => $after['assigned_to'] ?? null],
                'outcome_label' => 'accepted',
                'metadata' => [
                    'previous_assigned_to' => $before['assigned_to'] ?? null,
                    'new_assigned_to' => $after['assigned_to'] ?? null,
                ],
                'was_successful' => true,
            ]);
        } catch (\Throwable $e) {
            error_log('Tasks::captureReassignment failed: ' . $e->getMessage());
        }
    }

    private function refreshContactIntelligence(?int ...$contactIds): void
    {
        $service = new ContactIntelligenceService();
        $uniqueIds = array_unique(array_filter(array_map(static fn ($contactId) => !empty($contactId) ? (int) $contactId : null, $contactIds)));
        foreach ($uniqueIds as $contactId) {
            try {
                $service->computeAndPersist((int) $contactId);
            } catch (\Throwable $e) {
                error_log('Tasks::refreshContactIntelligence failed for contact_id=' . $contactId . ': ' . $e->getMessage());
            }
        }
    }

    private function normalizeMetadataJson($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return json_encode(is_array($decoded) ? $decoded : ['raw' => $value]);
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        return null;
    }

    private function decodeMetadataArray($value): array
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

    private function hasSourceColumn(string $column): bool
    {
        if (!preg_match('/^source_[a-z_]+$/', $column)) {
            return false;
        }
        if (!array_key_exists($column, self::$sourceColumnCache)) {
            self::$sourceColumnCache[$column] = Database::columnExists('tasks', $column);
        }

        return self::$sourceColumnCache[$column];
    }

    private function sourceColumnsFromPayload(array $payload): array
    {
        $metadata = $this->decodeMetadataArray($payload['metadata_json'] ?? null);
        return [
            'source_skill_key' => $this->normalizeSourceValue((string) ($payload['source_skill_key'] ?? $metadata['source_skill_key'] ?? $metadata['marketplace_skill_key'] ?? ''), false),
            'source_plugin_key' => $this->normalizeSourceValue((string) ($payload['source_plugin_key'] ?? $metadata['source_plugin_key'] ?? $metadata['marketplace_plugin_key'] ?? ''), false),
            'source_surface' => $this->normalizeSourceValue((string) ($payload['source_surface'] ?? $metadata['source_surface'] ?? ''), false),
            'source_capability_key' => $this->normalizeSourceValue((string) ($payload['source_capability_key'] ?? $metadata['source_capability_key'] ?? ''), true),
            'source_run_id' => $this->firstSourceRunId($payload, $metadata),
        ];
    }

    /** @return array{origin_type:string,completion_mode:string,completion_policy_version:int,automation_dedupe_key:?string} */
    private function taskV2ColumnsFromPayload(array $payload, array $metadata, array $source, string $title): array
    {
        $surface = (string) ($source['source_surface'] ?? '');
        $origin = (string) ($payload['origin_type'] ?? '');
        if (!in_array($origin, ['manual', 'ai', 'automation', 'import'], true)) {
            $origin = in_array($surface, ['ai_coach', 'clarity_chat', 'assistant', 'email_assistant', 'whatsapp_assistant', 'ai_mobile'], true)
                ? 'ai'
                : ($surface !== '' ? 'automation' : 'manual');
        }

        $mode = (string) ($payload['completion_mode'] ?? '');
        if (!in_array($mode, ['manual', 'review', 'auto'], true)) {
            $mode = !empty($metadata['auto_complete_allowed']) && empty($metadata['protected_from_auto_complete'])
                ? 'auto'
                : ($origin === 'manual' ? 'manual' : 'review');
        }

        $dedupe = trim((string) ($payload['automation_dedupe_key'] ?? ''));
        if ($dedupe === '' && $surface !== '') {
            $identity = array_filter([
                'surface' => $surface,
                'run_id' => $source['source_run_id'] ?? null,
                'recommendation_key' => $metadata['recommendation_key'] ?? null,
                'meeting_bot_run_id' => $metadata['meeting_bot_run_id'] ?? null,
                'assistant_run_id' => $metadata['assistant_run_id'] ?? null,
                'commercial_run_id' => $metadata['commercial_run_id'] ?? null,
                'workflow_execution_id' => $metadata['workflow_execution_id'] ?? null,
                'workflow_step_id' => $metadata['workflow_step_id'] ?? null,
                'origin_task_id' => $metadata['origin_task_id'] ?? null,
                'deal_id' => $metadata['deal_id'] ?? null,
                'conversation_id' => $metadata['conversation_id'] ?? null,
                'title' => strtolower(trim($title)),
            ], static fn($value): bool => $value !== null && $value !== '');
            $hasStableIdentity = count($identity) > 2 || !empty($metadata['recommendation_key']) || !empty($source['source_run_id']);
            if ($hasStableIdentity) {
                $dedupe = substr($surface . ':' . hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES)), 0, 191);
            }
        }

        return [
            'origin_type' => $origin,
            'completion_mode' => $mode,
            'completion_policy_version' => 2,
            'automation_dedupe_key' => $dedupe !== '' ? substr($dedupe, 0, 191) : null,
        ];
    }

    private function firstSourceRunId(array $payload, array $metadata): ?string
    {
        foreach ([
            $payload['source_run_id'] ?? null,
            $metadata['source_run_id'] ?? null,
            $metadata['guidance_run_id'] ?? null,
            $metadata['assistant_run_id'] ?? null,
            $metadata['commercial_run_id'] ?? null,
            $metadata['meeting_bot_run_id'] ?? null,
            $metadata['workflow_execution_id'] ?? null,
        ] as $candidate) {
            if (trim((string) $candidate) !== '') {
                return substr(trim((string) $candidate), 0, 120);
            }
        }
        return null;
    }

    private function normalizeSourceValue(string $value, bool $allowDot): ?string
    {
        $pattern = $allowDot ? '/[^a-z0-9_.]+/' : '/[^a-z0-9_]+/';
        $value = strtolower(preg_replace($pattern, '_', trim($value)) ?? '');
        $value = trim($value, '_');
        return $value === '' ? null : substr($value, 0, $allowDot ? 120 : 80);
    }

    private function recordRuntimeTaskEvent(int $workspaceId, int $actorUserId, array $task, string $eventType): void
    {
        $skillKey = (string) ($task['source_skill_key'] ?? '');
        $pluginKey = (string) ($task['source_plugin_key'] ?? '');
        $capabilityKey = (string) ($task['source_capability_key'] ?? '');
        if ($skillKey === '' && $pluginKey === '' && $capabilityKey === '') {
            return;
        }

        (new PluginRuntimeEventService())->record([
            'workspace_id' => $workspaceId,
            'user_id' => $actorUserId,
            'skill_key' => $skillKey !== '' ? $skillKey : $pluginKey,
            'capability_key' => $capabilityKey,
            'entity_type' => 'task',
            'entity_id' => (int) ($task['id'] ?? 0),
            'event_type' => $eventType,
            'status' => 'success',
            'metadata' => [
                'source_surface' => (string) ($task['source_surface'] ?? ''),
                'title' => (string) ($task['title'] ?? ''),
            ],
        ]);
    }
    
    /**
     * Delete task
     */
    public function delete(int $id): bool
    {
        $task = $this->getById($id);
        if (!$task) {
            return false;
        }

        $metadata = $this->decodeMetadataArray($task['metadata_json'] ?? null);
        Database::execute("DELETE FROM tasks WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $id]);
        $this->lifecycleEvents->emit('task.deleted', 'task', $id, $task, [
            'workspace_id' => (int) ($task['workspace_id'] ?? $this->workspaceId()),
            'actor_user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'metadata' => ['title' => (string) ($task['title'] ?? '')],
        ]);

        if (!empty($metadata['source_surface']) || !empty($metadata['completed_by_evidence'])) {
            try {
                (new AIDecisionOutcomeService())->recordTaskOutcome($task, [
                    'outcome_label' => 'rejected',
                    'outcome_score' => 0.0,
                    'metadata' => ['task_id' => $id, 'reason' => 'task_deleted'],
                ]);
            } catch (\Throwable $e) {
                error_log('Tasks::delete AI outcome tracking failed: ' . $e->getMessage());
            }
        }
        
        return true;
    }
    
    /**
     * Get tasks with filters
     */
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $workspace = $this->workspaceClause('t.');
        $where = [$workspace['sql']];
        $params = $workspace['params'];
        
        if (!empty($filters['status'])) {
            $where[] = "t.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['active_only'])) {
            $where[] = "t.status NOT IN ('completed', 'cancelled')";
        }
        
        if (!empty($filters['priority'])) {
            $where[] = "t.priority = ?";
            $params[] = $filters['priority'];
        }
        
        if (!empty($filters['assigned_to'])) {
            $where[] = "t.assigned_to = ?";
            $params[] = (int) $filters['assigned_to'];
        }
        
        if (!empty($filters['contact_id'])) {
            $where[] = "t.contact_id = ?";
            $params[] = (int) $filters['contact_id'];
        }

        if ($this->hasTargetIdColumn() && !empty($filters['target_id'])) {
            $where[] = "t.target_id = ?";
            $params[] = (int) $filters['target_id'];
        }
        
        if (!empty($filters['created_by'])) {
            $where[] = "t.created_by = ?";
            $params[] = (int) $filters['created_by'];
        }
        
        if (!empty($filters['search'])) {
            $searchTerm = '%' . Security::sanitizeInput($filters['search'], 'string') . '%';
            $where[] = "(t.title LIKE ? OR t.description LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if (!empty($filters['overdue'])) {
            $where[] = "t.due_date < NOW() AND t.status NOT IN ('completed', 'cancelled')";
        }
        
        if (!empty($filters['due_today'])) {
            $where[] = "DATE(t.due_date) = CURDATE() AND t.status NOT IN ('completed', 'cancelled')";
        }
        
        if ($this->hasTargetIdColumn()) {
            $sql = "SELECT t.*, 
                           c.first_name as contact_first_name, 
                           c.last_name as contact_last_name, 
                           c.email as contact_email,
                           tg.title as target_title,
                           tg.target_date as target_deadline,
                           u1.email as assigned_to_email,
                           u2.email as created_by_email
                    FROM tasks t
                    LEFT JOIN contacts c ON t.contact_id = c.id AND c.workspace_id = t.workspace_id
                    LEFT JOIN targets tg ON t.target_id = tg.id AND tg.workspace_id = t.workspace_id
                    LEFT JOIN users u1 ON t.assigned_to = u1.id
                    LEFT JOIN users u2 ON t.created_by = u2.id";
        } else {
            $sql = "SELECT t.*, 
                           c.first_name as contact_first_name, 
                           c.last_name as contact_last_name, 
                           c.email as contact_email,
                           u1.email as assigned_to_email,
                           u2.email as created_by_email
                    FROM tasks t
                    LEFT JOIN contacts c ON t.contact_id = c.id AND c.workspace_id = t.workspace_id
                    LEFT JOIN users u1 ON t.assigned_to = u1.id
                    LEFT JOIN users u2 ON t.created_by = u2.id";
        }
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " ORDER BY 
                    CASE t.priority 
                        WHEN 'urgent' THEN 1 
                        WHEN 'high' THEN 2 
                        WHEN 'medium' THEN 3 
                        WHEN 'low' THEN 4 
                    END,
                    t.due_date ASC,
                    t.created_at DESC
                  LIMIT " . max(1, (int) $limit) . " OFFSET " . max(0, (int) $offset);
        
        $rows = Database::query($sql, $params);
        if (!$this->hasTargetIdColumn() && !empty($rows)) {
            foreach ($rows as &$row) {
                $row['target_id'] = null;
                $row['target_title'] = null;
                $row['target_deadline'] = null;
            }
            unset($row);
        }
        return $rows;
    }
    
    /**
     * Get task count
     */
    public function getCount(array $filters = []): int
    {
        $workspace = $this->workspaceClause();
        $where = [$workspace['sql']];
        $params = $workspace['params'];
        
        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['assigned_to'])) {
            $where[] = "assigned_to = ?";
            $params[] = (int) $filters['assigned_to'];
        }
        
        if (!empty($filters['overdue'])) {
            $where[] = "due_date < NOW() AND status NOT IN ('completed', 'cancelled')";
        }

        if (!empty($filters['due_today'])) {
            $where[] = "DATE(due_date) = CURDATE() AND status NOT IN ('completed', 'cancelled')";
        }

        $sql = "SELECT COUNT(*) as count FROM tasks";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $result = Database::queryOne($sql, $params);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Count unresolved tasks assigned to a user since they last opened the task list.
     */
    public function getNewUnresolvedSinceCount(int $userId, ?string $lastOpenedAt): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $workspace = $this->workspaceClause();
        $where = [
            $workspace['sql'],
            "assigned_to = ?",
            "status NOT IN ('completed', 'cancelled')",
        ];
        $params = array_merge($workspace['params'], [$userId]);

        $lastOpenedAt = trim((string) $lastOpenedAt);
        if ($lastOpenedAt !== '') {
            $where[] = "created_at > ?";
            $params[] = $lastOpenedAt;
        }

        $sql = "SELECT COUNT(*) as count FROM tasks WHERE " . implode(" AND ", $where);
        $result = Database::queryOne($sql, $params);

        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Get user's tasks
     */
    public function getUserTasks(int $userId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $filters['assigned_to'] = $userId;
        return $this->getAll($filters, $limit, $offset);
    }

    /**
     * Get active AI starter tasks for a user.
     */
    public function getAiStarterTasks(int $userId, int $limit = 5): array
    {
        if ($userId <= 0 || $limit <= 0) {
            return [];
        }

        if (!$this->hasMetadataJsonColumn()) {
            return [];
        }

        $safeLimit = max(1, (int) $limit);

        return Database::query(
            "SELECT t.*,
                    c.first_name as contact_first_name,
                    c.last_name as contact_last_name,
                    c.email as contact_email,
                    u1.email as assigned_to_email,
                    u2.email as created_by_email
             FROM tasks t
             LEFT JOIN contacts c ON t.contact_id = c.id AND c.workspace_id = t.workspace_id
             LEFT JOIN users u1 ON t.assigned_to = u1.id
             LEFT JOIN users u2 ON t.created_by = u2.id
             WHERE t.workspace_id = ?
               AND t.assigned_to = ?
               AND t.status IN ('pending', 'in_progress')
               AND JSON_UNQUOTE(JSON_EXTRACT(COALESCE(t.metadata_json, '{}'), '$.source_surface')) = 'ai_coach'
             ORDER BY
               CASE t.status
                   WHEN 'pending' THEN 0
                   WHEN 'in_progress' THEN 1
                   ELSE 2
               END ASC,
               CASE t.priority
                   WHEN 'urgent' THEN 0
                   WHEN 'high' THEN 1
                   WHEN 'medium' THEN 2
                   ELSE 3
               END ASC,
               COALESCE(t.due_date, '9999-12-31') ASC,
               t.created_at DESC
              LIMIT {$safeLimit}",
            [$this->workspaceId(), $userId]
        );
    }
    
    /**
     * Get contact's tasks
     */
    public function getContactTasks(int $contactId): array
    {
        return $this->getAll(['contact_id' => $contactId]);
    }

    /**
     * Create a task with subtasks (checklist/guided task)
     */
    public function createWithSubtasks(array $taskData, array $subtasks): int
    {
        $this->ensureTaskSubtasksSchema();
        $started = !Database::getInstance()->inTransaction();
        if ($started) {
            Database::beginTransaction();
        }
        try {
            $taskId = $this->create(array_merge($taskData, ['_defer_post_create' => true]));
            if ($this->lastCreateWasDeduplicated) {
                if ($started) {
                    Database::commit();
                }
                return $taskId;
            }
            foreach ($subtasks as $index => $subtask) {
                $title = is_string($subtask) ? $subtask : (string) ($subtask['title'] ?? '');
                if ($title === '') {
                    continue;
                }
                $description = is_array($subtask) ? ($subtask['description'] ?? '') : '';
                Database::execute(
                    "INSERT INTO task_subtasks (task_id, title, description, completed, `order`) VALUES (?, ?, ?, 0, ?)",
                    [$taskId, Security::sanitizeInput($title, 'string'), Security::sanitizeInput($description, 'string'), $index]
                );
            }

            $created = $this->getById($taskId) ?: [];
            $source = $this->sourceColumnsFromPayload(array_merge($taskData, ['metadata_json' => $created['metadata_json'] ?? $taskData['metadata_json'] ?? null]));
            $this->runPostCreateEffects(
                $taskId,
                (int) ($created['workspace_id'] ?? $this->workspaceId()),
                (int) ($taskData['actor_user_id'] ?? $_SESSION['user_id'] ?? 0),
                (int) ($created['created_by'] ?? $taskData['created_by'] ?? 0),
                (string) ($created['title'] ?? $taskData['title'] ?? ''),
                !empty($created['contact_id']) ? (int) $created['contact_id'] : null,
                !empty($created['target_id']) ? (int) $created['target_id'] : null,
                !empty($created['assigned_to']) ? (int) $created['assigned_to'] : null,
                (string) ($created['status'] ?? 'pending'),
                $created,
                $source
            );
            if ($started) {
                Database::commit();
            }
            return $taskId;
        } catch (\Throwable $e) {
            if ($started && Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get subtasks for a task
     */
    public function getSubtasks(int $taskId): array
    {
        $this->ensureTaskSubtasksSchema();
        $task = Database::queryOne(
            "SELECT id, title, description, metadata_json
             FROM tasks
             WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId(), $taskId]
        );
        if (!$task) {
            return [];
        }

        $subtasks = Database::query(
            "SELECT * FROM task_subtasks WHERE task_id = ? ORDER BY `order` ASC, id ASC",
            [$taskId]
        );

        if ($task && AITaskAutomationService::isAIAutoTask($task)) {
            $reconciled = AITaskAutomationService::reconcileTaskSubtasks($task, $subtasks);
            if ($this->shouldRewriteTaskSubtasks($subtasks, $reconciled, (string) ($task['title'] ?? ''))) {
                $this->replaceTaskSubtasks($taskId, $subtasks, $reconciled);
                $subtasks = Database::query(
                    "SELECT * FROM task_subtasks WHERE task_id = ? ORDER BY `order` ASC, id ASC",
                    [$taskId]
                );
            }
        }

        return $subtasks;
    }

    /**
     * Update a subtask
     */
    public function updateSubtask(int $subtaskId, array $data): bool
    {
        $this->ensureTaskSubtasksSchema();
        $actorUserId = (int) ($data['actor_user_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($actorUserId > 0) {
            $this->assignmentAccess->assertCanCreateOrEdit($actorUserId);
        }
        $subtask = Database::queryOne(
            "SELECT st.id, st.task_id, st.completed, t.status, t.metadata_json
             FROM task_subtasks st
             INNER JOIN tasks t ON t.id = st.task_id
             WHERE t.workspace_id = ?
               AND st.id = ?",
            [$this->workspaceId(), $subtaskId]
        );
        if (!$subtask) {
            return false;
        }

        $allowed = ['title', 'description', 'completed', 'order'];
        $updates = [];
        $params = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if ($field === 'completed') {
                $updates[] = 'completed = ?';
                $params[] = !empty($data['completed']) ? 1 : 0;
            } elseif ($field === 'order') {
                $updates[] = '`order` = ?';
                $params[] = (int) $data['order'];
            } else {
                $updates[] = $field . ' = ?';
                $params[] = Security::sanitizeInput($data[$field], 'string');
            }
        }
        if (empty($updates)) {
            return false;
        }
        $wasSubtaskCompleted = isset($data['completed']) && !empty($data['completed']) && empty($subtask['completed']);
        $params[] = $subtaskId;
        $updatedRows = Database::execute("UPDATE task_subtasks SET " . implode(', ', $updates) . " WHERE id = ?", $params);
        if ($updatedRows <= 0 && !array_key_exists('completed', $data)) {
            return false;
        }
        if ($wasSubtaskCompleted) {
            $task = $this->getById((int) ($subtask['task_id'] ?? 0)) ?: [];
            $this->lifecycleEvents->emit('task.subtask.completed', 'task', (int) ($subtask['task_id'] ?? 0), $task, [
                'workspace_id' => (int) ($task['workspace_id'] ?? $this->workspaceId()),
                'actor_user_id' => $actorUserId,
                'metadata' => ['subtask_id' => $subtaskId],
            ]);
        }

        if (array_key_exists('completed', $data)) {
            $parentTaskId = (int) ($subtask['task_id'] ?? 0);
            if ($parentTaskId > 0) {
                $subtaskCounts = Database::queryOne(
                    "SELECT COUNT(*) AS total_count,
                            SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_count
                     FROM task_subtasks
                     WHERE task_id = ?",
                    [$parentTaskId]
                ) ?: ['total_count' => 0, 'completed_count' => 0];

                $totalCount = (int) ($subtaskCounts['total_count'] ?? 0);
                $completedCount = (int) ($subtaskCounts['completed_count'] ?? 0);
                $taskStatus = (string) ($subtask['status'] ?? 'pending');
                $metadata = $this->decodeMetadataArray($subtask['metadata_json'] ?? null);
                $completedByChecklist = !empty($metadata['completed_by_checklist']);

                if ($totalCount > 0 && $completedCount === $totalCount && !in_array($taskStatus, ['completed', 'cancelled'], true)) {
                    $metadata['completion_source'] = 'checklist';
                    $metadata['completed_by_checklist'] = true;
                    $this->update($parentTaskId, [
                        'status' => 'completed',
                        'metadata_json' => $metadata,
                        '_completion_source' => 'checklist',
                    ]);
                } elseif (
                    $totalCount > 0
                    && $completedCount < $totalCount
                    && $taskStatus === 'completed'
                    && $completedByChecklist
                ) {
                    unset($metadata['completed_by_checklist']);
                    if (($metadata['completion_source'] ?? null) === 'checklist') {
                        unset($metadata['completion_source']);
                    }
                    $this->update($parentTaskId, [
                        'status' => $completedCount > 0 ? 'in_progress' : 'pending',
                        'metadata_json' => $metadata,
                    ]);
                }
            }
        }

        return true;
    }

    private function shouldRewriteTaskSubtasks(array $existingSubtasks, array $reconciledSubtasks, string $taskTitle): bool
    {
        if (empty($reconciledSubtasks)) {
            return false;
        }

        if (empty($existingSubtasks)) {
            return true;
        }

        if (count($existingSubtasks) === 1 && count($reconciledSubtasks) > 1) {
            $existingTitle = strtolower(trim((string) ($existingSubtasks[0]['title'] ?? '')));
            $taskTitle = strtolower(trim($taskTitle));
            $percent = 0.0;
            similar_text($existingTitle, $taskTitle, $percent);
            if ($existingTitle === '' || $existingTitle === $taskTitle || $percent >= 75.0) {
                return true;
            }

            $genericWords = ['configure', 'set up', 'setup', 'review', 'update'];
            foreach ($genericWords as $word) {
                if (str_contains($existingTitle, $word)) {
                    return true;
                }
            }
        }

        foreach ($existingSubtasks as $index => $existingSubtask) {
            $existingTitle = strtolower(trim((string) ($existingSubtask['title'] ?? '')));
            $existingDescription = trim((string) ($existingSubtask['description'] ?? ''));
            $reconciledTitle = strtolower(trim((string) ($reconciledSubtasks[$index]['title'] ?? '')));
            $reconciledDescription = trim((string) ($reconciledSubtasks[$index]['description'] ?? ''));
            if (
                $reconciledTitle !== ''
                && $existingTitle !== ''
                && $existingTitle !== $reconciledTitle
                && in_array($existingTitle, ['open the related crm area', 'save the required change', 'verify the expected result'], true)
            ) {
                return true;
            }
            if ($existingDescription === '' && $reconciledDescription !== '') {
                return true;
            }
        }

        return false;
    }

    private function replaceTaskSubtasks(int $taskId, array $existingSubtasks, array $reconciledSubtasks): void
    {
        Database::beginTransaction();
        try {
            Database::execute("DELETE FROM task_subtasks WHERE task_id = ?", [$taskId]);
            foreach ($reconciledSubtasks as $index => $subtask) {
                $title = trim((string) ($subtask['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $description = (string) ($subtask['description'] ?? '');
                $completed = 0;
                foreach ($existingSubtasks as $existingSubtask) {
                    if (strcasecmp(trim((string) ($existingSubtask['title'] ?? '')), $title) === 0) {
                        $completed = !empty($existingSubtask['completed']) ? 1 : 0;
                        break;
                    }
                }

                Database::execute(
                    "INSERT INTO task_subtasks (task_id, title, description, completed, `order`) VALUES (?, ?, ?, ?, ?)",
                    [$taskId, Security::sanitizeInput($title, 'string'), Security::sanitizeInput($description, 'string'), $completed, $index]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            error_log('Tasks::replaceTaskSubtasks failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array{sql:string, params:array<int, int>}
     */
    private function workspaceClause(string $alias = '', string $column = 'workspace_id'): array
    {
        return $this->workspaceScope->workspaceClause($alias, $column);
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }

    private function normalizeStatus($value): string
    {
        $status = Security::sanitizeInput((string) $value, 'string');
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid task status');
        }

        return $status;
    }

    private function normalizePriority($value): string
    {
        $priority = Security::sanitizeInput((string) $value, 'string');
        if (!in_array($priority, self::ALLOWED_PRIORITIES, true)) {
            throw new \InvalidArgumentException('Invalid task priority');
        }

        return $priority;
    }

    private function normalizeDueDate($value): ?string
    {
        $dueDate = trim((string) ($value ?? ''));
        if ($dueDate === '') {
            return null;
        }

        $formats = [
            'Y-m-d\TH:i' => 'Y-m-d H:i:00',
            'Y-m-d H:i:s' => 'Y-m-d H:i:s',
            'Y-m-d H:i' => 'Y-m-d H:i:00',
            'Y-m-d' => 'Y-m-d 00:00:00',
        ];

        foreach ($formats as $format => $outputFormat) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $dueDate);
            if ($parsed && $parsed->format($format) === $dueDate) {
                return $parsed->format($outputFormat);
            }
        }

        throw new \InvalidArgumentException('Task due date must be a valid date or time');
    }
}
