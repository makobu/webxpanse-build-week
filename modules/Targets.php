<?php
/**
 * Targets Management Module
 * 
 * Handles target CRUD operations, progress tracking, and status management
 */

namespace CRM\Modules;

use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\CoreEntityLifecycleEventService;
use CRM\Services\TargetCoordinator;
use CRM\Services\TargetIntelligenceService;
use CRM\Services\TargetPluginIntegrationService;
use CRM\Services\WorkspaceScopeService;

class Targets
{
    private static ?bool $hasScopeColumn = null;
    private static ?bool $hasProgressModeColumn = null;
    private static ?bool $hasManualAdjustmentColumn = null;
    private static ?bool $hasMetadataJsonColumn = null;
    private static array $sourceColumnCache = [];
    private WorkspaceScopeService $workspaceScope;
    private CoreEntityLifecycleEventService $lifecycleEvents;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
        $this->lifecycleEvents = new CoreEntityLifecycleEventService();
    }

    /**
     * Create a new target
     */
    public function create(array $data): int
    {
        if (!isset($data['title']) || trim((string) $data['title']) === '') {
            throw new \Exception("Target title is required");
        }

        // Sanitize inputs
        $title = Security::sanitizeInput($data['title'], 'string');
        $description = Security::sanitizeInput($data['description'] ?? '', 'string');
        $userId = (int) ($data['user_id'] ?? $_SESSION['user_id'] ?? 0);
        $targetType = $this->normalizeTargetType($data['target_type'] ?? 'custom');
        $targetValue = $this->normalizePositiveDecimal($data['target_value'] ?? null, 'Target value', true);
        $currentValue = $this->normalizeNonNegativeDecimal($data['current_value'] ?? 0, 'Current value');
        $scope = $this->normalizeScope($data['scope'] ?? 'personal');
        $progressMode = $this->normalizeProgressMode($data['progress_mode'] ?? 'manual');
        $manualAdjustmentValue = $this->normalizeDecimal($data['manual_adjustment_value'] ?? ($progressMode === 'hybrid' ? $currentValue : 0), 'Manual adjustment value');
        $unit = Security::sanitizeInput($data['unit'] ?? '', 'string');
        $startDate = $this->normalizeDate($data['start_date'] ?? date('Y-m-d'), 'Start date', false) ?? date('Y-m-d');
        $targetDate = $this->normalizeDate($data['target_date'] ?? null, 'Target date (deadline)', true);
        $this->assertTargetDateWindow($startDate, $targetDate);
        $reminderFrequency = $this->normalizeReminderFrequency($data['reminder_frequency'] ?? 'weekly');
        $customReminderDays = $this->normalizeCustomReminderDays($data['custom_reminder_days'] ?? null, $reminderFrequency);
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $inputMetadata = $this->decodeMetadataJson($data['metadata_json'] ?? null);
        if (array_key_exists('rollup_source', $data) || array_key_exists('rollup_metric', $data)) {
            $inputMetadata['rollup_definition'] = [
                'source' => (string) ($data['rollup_source'] ?? $inputMetadata['rollup_definition']['source'] ?? ''),
                'metric' => (string) ($data['rollup_metric'] ?? $inputMetadata['rollup_definition']['metric'] ?? ''),
                'filters' => $data['rollup_filters_json'] ?? $inputMetadata['rollup_definition']['filters'] ?? [],
            ];
        }
        $metadataJson = $this->normalizeMetadataJson($inputMetadata);
        if ($this->hasTargetIntelligenceColumns()) {
            $metadataJson = $this->normalizeMetadataJson($this->normalizeTargetMetadata(
                $inputMetadata,
                $progressMode,
                $workspaceId
            ));
        }
        $sourceColumns = $this->sourceColumnsFromData($data);
        $metadata = $this->decodeMetadataJson($metadataJson);
        $rollupDefinition = (array) ($metadata['rollup_definition'] ?? []);
        $originType = in_array((string) ($data['origin_type'] ?? 'manual'), ['manual', 'ai', 'automation', 'import'], true)
            ? (string) ($data['origin_type'] ?? 'manual') : 'manual';
        $automationMode = in_array((string) ($data['automation_mode'] ?? ($progressMode === 'manual' ? 'manual' : 'review')), ['manual', 'review', 'auto'], true)
            ? (string) ($data['automation_mode'] ?? ($progressMode === 'manual' ? 'manual' : 'review')) : 'manual';
        $automationDedupeKey = trim((string) ($data['automation_dedupe_key'] ?? '')) ?: null;
        $sourceSurface = trim((string) ($data['source_surface'] ?? $metadata['source_surface'] ?? $metadata['source'] ?? '')) ?: null;
        $sourceRunId = trim((string) ($data['source_run_id'] ?? $metadata['source_run_id'] ?? $metadata['run_id'] ?? '')) ?: null;
        $rollupSource = trim((string) ($data['rollup_source'] ?? $rollupDefinition['source'] ?? '')) ?: null;
        $rollupMetric = trim((string) ($data['rollup_metric'] ?? $rollupDefinition['metric'] ?? '')) ?: null;
        $rollupWindow = in_array((string) ($data['rollup_window'] ?? 'target_period'), ['target_period', 'since_creation', 'lifetime'], true)
            ? (string) ($data['rollup_window'] ?? 'target_period') : 'target_period';
        $rollupFilters = $data['rollup_filters_json'] ?? $rollupDefinition['filters'] ?? null;
        $rollupFiltersJson = $rollupFilters === null ? null : json_encode($rollupFilters, JSON_UNESCAPED_SLASHES);
        $currencyCode = strtoupper(trim((string) ($data['currency_code'] ?? ''))) ?: null;
        if ($currencyCode !== null && !preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            throw new \InvalidArgumentException('Currency must be a three-letter ISO code.');
        }
        if ($automationDedupeKey !== null) {
            $existing = Database::queryOne('SELECT id FROM targets WHERE workspace_id=? AND automation_dedupe_key=? LIMIT 1', [$workspaceId, $automationDedupeKey]);
            if ($existing) {
                return (int) $existing['id'];
            }
        }

        if ($title === '') {
            throw new \Exception("Target title is required");
        }
        
        if ($this->hasTargetIntelligenceColumns()) {
            Database::execute(
                "INSERT INTO targets (workspace_id, user_id, scope, title, description, target_type, progress_mode, target_value, current_value, manual_adjustment_value, unit,
                                     start_date, target_date, reminder_frequency, custom_reminder_days, metadata_json, status,
                                     origin_type,automation_mode,automation_dedupe_key,source_surface,source_run_id,rollup_source,rollup_metric,rollup_window,rollup_filters_json,currency_code)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$workspaceId, $userId, $scope, $title, $description, $targetType, $progressMode, $targetValue, $currentValue, $manualAdjustmentValue, $unit,
                 $startDate, $targetDate, $reminderFrequency, $customReminderDays, $metadataJson, $originType, $automationMode, $automationDedupeKey,
                 $sourceSurface, $sourceRunId, $rollupSource, $rollupMetric, $rollupWindow, $rollupFiltersJson, $currencyCode]
            );
        } else {
            Database::execute(
                "INSERT INTO targets (workspace_id, user_id, title, description, target_type, target_value, current_value, unit,
                                     start_date, target_date, reminder_frequency, custom_reminder_days, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
                [$workspaceId, $userId, $title, $description, $targetType, $targetValue, $currentValue, $unit,
                 $startDate, $targetDate, $reminderFrequency, $customReminderDays]
            );
        }
        
        $targetId = (int) Database::lastInsertId();
        if ($targetId <= 0) {
            $targetId = $this->findTargetIdByLookup([
                'user_id' => $userId,
                'title' => $title,
                'target_date' => $targetDate,
                'description' => $description,
            ]);
        }

        $this->persistSourceColumns($targetId, $workspaceId, $sourceColumns);
        
        // Schedule initial reminders
        $reminders = new \CRM\Modules\TargetReminders();
        $reminders->scheduleInitialReminders($targetId, $reminderFrequency, $targetDate, $customReminderDays);

        if ($this->hasTargetIntelligenceColumns()) {
            (new TargetIntelligenceService())->syncTarget($targetId, $workspaceId);
        }

        if ($progressMode === 'manual' && $currentValue >= $targetValue) {
            (new TargetCoordinator())->completeManually($targetId, $workspaceId, (int) ($data['actor_user_id'] ?? $_SESSION['user_id'] ?? 0));
        }

        $createdTarget = $this->getById($targetId) ?: ['id' => $targetId, 'workspace_id' => $workspaceId] + $data;
        $this->lifecycleEvents->emit('target.created', 'target', $targetId, $createdTarget, [
            'workspace_id' => $workspaceId,
            'actor_user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'source_skill_key' => $sourceColumns['source_skill_key'] ?? '',
            'source_plugin_key' => $sourceColumns['source_plugin_key'] ?? '',
            'source_capability_key' => $sourceColumns['source_capability_key'] ?? '',
        ]);

        return $targetId;
    }
    
    /**
     * Get target by ID
     */
    public function getById(int $id): ?array
    {
        $target = Database::queryOne(
            "SELECT t.*, 
                    u.email as user_email
             FROM targets t
             LEFT JOIN users u ON t.user_id = u.id
             WHERE t.workspace_id = ? AND t.id = ?",
            [$this->workspaceScope->requireActiveWorkspaceId(), $id]
        );
        
        if ($target) {
            $targetId = (int) ($target['id'] ?? 0);
            if ($this->hasTargetIntelligenceColumns()) {
                $target = (new TargetIntelligenceService())->enrichTarget($target, true);
            } else {
                $target['progress_percentage'] = $this->calculateProgress($target);
                $target['days_remaining'] = $this->getDaysRemaining($target);
                $target['is_on_track'] = $this->isOnTrack($target);
                $target['status_category'] = $this->getStatusCategory($target);
            }
            $target['id'] = $targetId;
        }
        
        return $target;
    }

    public function canViewTarget(array $target, array $user): bool
    {
        if (empty($target) || empty($user['id'])) {
            return false;
        }

        if ($this->canManageTarget($target, $user)) {
            return true;
        }

        if ((int) ($target['user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
            return true;
        }

        return $this->isSharedScope($target['scope'] ?? null);
    }

    public function canEditTarget(array $target, array $user): bool
    {
        return $this->canManageTarget($target, $user);
    }

    public function canDeleteTarget(array $target, array $user): bool
    {
        return $this->canManageTarget($target, $user);
    }

    public function resolveTargetId(array $target): int
    {
        $id = is_scalar($target['id'] ?? null) ? (int) $target['id'] : 0;
        if ($id > 0) {
            return $id;
        }

        return $this->findTargetIdByLookup($target);
    }

    public function findTargetIdByLookup(array $lookup): int
    {
        $where = ['workspace_id = ?'];
        $params = [$this->workspaceScope->requireActiveWorkspaceId()];

        if (!empty($lookup['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = (int) $lookup['user_id'];
        }
        if (!empty($lookup['title'])) {
            $where[] = 'title = ?';
            $params[] = (string) $lookup['title'];
        }
        if (!empty($lookup['target_date'])) {
            $where[] = 'target_date = ?';
            $params[] = (string) $lookup['target_date'];
        }
        if (!empty($lookup['description'])) {
            $where[] = 'description = ?';
            $params[] = (string) $lookup['description'];
        }

        if ($where !== []) {
            $row = Database::queryOne(
                'SELECT id FROM targets WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 1',
                $params
            );
            $resolvedId = (int) ($row['id'] ?? 0);
            if ($resolvedId > 0) {
                return $resolvedId;
            }
        }

        if (!empty($lookup['title'])) {
            $row = Database::queryOne(
                'SELECT id FROM targets WHERE workspace_id = ? AND title = ? ORDER BY id DESC LIMIT 1',
                [$this->workspaceScope->requireActiveWorkspaceId(), (string) $lookup['title']]
            );
            $resolvedId = (int) ($row['id'] ?? 0);
            if ($resolvedId > 0) {
                return $resolvedId;
            }

            $row = Database::queryOne(
                'SELECT id FROM targets WHERE workspace_id = ? AND title LIKE ? ORDER BY id DESC LIMIT 1',
                [$this->workspaceScope->requireActiveWorkspaceId(), '%' . (string) $lookup['title'] . '%']
            );
            return (int) ($row['id'] ?? 0);
        }

        return 0;
    }
    
    /**
     * Update target
     */
    public function update(int $id, array $data): bool
    {
        $target = $this->getById($id);
        if (!$target) {
            throw new \Exception("Target not found");
        }
        if (array_key_exists('status', $data) && empty($data['_target_coordinator'])) {
            $status = $this->normalizeStatus($data['status']);
            $remaining = $data;
            unset($remaining['status'], $remaining['current_value'], $remaining['actor_user_id'], $remaining['state_version'], $remaining['reason']);
            if ($remaining !== []) {
                $remaining['_target_coordinator'] = true;
                $this->update($id, $remaining);
            }
            return (new TargetCoordinator())->changeStatus(
                $id,
                (int) ($target['workspace_id'] ?? $this->workspaceScope->requireActiveWorkspaceId()),
                $status,
                (int) ($data['actor_user_id'] ?? $_SESSION['user_id'] ?? 0),
                ['reason' => (string) ($data['reason'] ?? ''), 'expected_version' => $data['state_version'] ?? null]
            );
        }
        if (array_key_exists('current_value', $data) && empty($data['_target_coordinator'])) {
            $requestedValue = $this->normalizeNonNegativeDecimal($data['current_value'], 'Current value');
            $remaining = $data;
            unset($remaining['current_value'], $remaining['actor_user_id'], $remaining['state_version'], $remaining['reason']);
            $hadDefinitionChanges = $remaining !== [];
            if ($hadDefinitionChanges) {
                $remaining['_target_coordinator'] = true;
                $this->update($id, $remaining);
            }
            return (new TargetCoordinator())->updateProgress(
                $id,
                (int) ($target['workspace_id'] ?? $this->workspaceScope->requireActiveWorkspaceId()),
                $requestedValue,
                (int) ($data['actor_user_id'] ?? $_SESSION['user_id'] ?? 0),
                $hadDefinitionChanges ? null : (isset($data['state_version']) ? (int) $data['state_version'] : null)
            );
        }
        
        $updates = [];
        $params = [];
        
        $allowedFields = ['title', 'description', 'target_type', 'target_value', 'current_value',
                         'unit', 'start_date', 'target_date', 'reminder_frequency', 'custom_reminder_days', 'status'];
        if ($this->hasTargetIntelligenceColumns()) {
            $allowedFields = array_merge($allowedFields, ['scope', 'progress_mode', 'manual_adjustment_value', 'metadata_json']);
        }
        foreach (['source_skill_key', 'source_plugin_key', 'source_capability_key'] as $sourceField) {
            if ($this->hasSourceColumn($sourceField)) {
                $allowedFields[] = $sourceField;
            }
        }
        foreach (['origin_type','automation_mode','automation_dedupe_key','source_surface','source_run_id','rollup_source','rollup_metric','rollup_window','rollup_filters_json','currency_code'] as $v2Field) {
            if ($this->columnExists($v2Field)) {
                $allowedFields[] = $v2Field;
            }
        }
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                if (in_array($field, ['title', 'description', 'unit'], true)) {
                    $value = Security::sanitizeInput($data[$field], 'string');
                    if ($field === 'title' && $value === '') {
                        throw new \Exception("Target title is required");
                    }
                } elseif ($field === 'target_value') {
                    $value = $this->normalizePositiveDecimal($data[$field], 'Target value', true);
                } elseif ($field === 'current_value') {
                    $value = $this->normalizeNonNegativeDecimal($data[$field], 'Current value');
                } elseif ($field === 'manual_adjustment_value') {
                    $value = $this->normalizeDecimal($data[$field], 'Manual adjustment value');
                } elseif ($field === 'scope') {
                    $value = $this->normalizeScope($data[$field]);
                } elseif ($field === 'progress_mode') {
                    $value = $this->normalizeProgressMode($data[$field]);
                } elseif ($field === 'metadata_json') {
                    continue;
                } elseif ($field === 'origin_type') {
                    $value = in_array((string) $data[$field], ['manual','ai','automation','import'], true) ? (string) $data[$field] : throw new \InvalidArgumentException('Invalid target origin type');
                } elseif ($field === 'automation_mode') {
                    $value = in_array((string) $data[$field], ['manual','review','auto'], true) ? (string) $data[$field] : throw new \InvalidArgumentException('Invalid target automation mode');
                } elseif ($field === 'rollup_window') {
                    $value = in_array((string) $data[$field], ['target_period','since_creation','lifetime'], true) ? (string) $data[$field] : throw new \InvalidArgumentException('Invalid target rollup window');
                } elseif ($field === 'rollup_filters_json') {
                    $value = is_string($data[$field]) ? $data[$field] : json_encode($data[$field], JSON_UNESCAPED_SLASHES);
                    if ($value !== null && json_decode((string) $value, true) === null && trim((string) $value) !== 'null') {
                        throw new \InvalidArgumentException('Invalid target rollup filters');
                    }
                } elseif ($field === 'currency_code') {
                    $value = strtoupper(trim((string) $data[$field])) ?: null;
                    if ($value !== null && !preg_match('/^[A-Z]{3}$/', $value)) {
                        throw new \InvalidArgumentException('Currency must be a three-letter ISO code.');
                    }
                } elseif (in_array($field, ['automation_dedupe_key','source_surface','source_run_id','rollup_source','rollup_metric'], true)) {
                    $value = trim((string) $data[$field]) ?: null;
                } elseif (str_starts_with($field, 'source_')) {
                    $value = $this->normalizeSourceValue((string) $data[$field], $field === 'source_capability_key');
                } elseif ($field === 'target_type') {
                    $value = $this->normalizeTargetType($data[$field]);
                } elseif ($field === 'reminder_frequency') {
                    $value = $this->normalizeReminderFrequency($data[$field]);
                } elseif ($field === 'status') {
                    $value = $this->normalizeStatus($data[$field]);
                } elseif ($field === 'custom_reminder_days') {
                    $frequency = array_key_exists('reminder_frequency', $data)
                        ? $this->normalizeReminderFrequency($data['reminder_frequency'])
                        : (string) ($target['reminder_frequency'] ?? 'weekly');
                    $value = $this->normalizeCustomReminderDays($data[$field], $frequency);
                } elseif ($field === 'start_date') {
                    $value = $this->normalizeDate($data[$field], 'Start date', false);
                } elseif ($field === 'target_date') {
                    $value = $this->normalizeDate($data[$field], 'Target date (deadline)', true);
                } else {
                    $value = $data[$field];
                }
                
                $updates[] = "$field = ?";
                $params[] = $value;
            }
        }

        if ($this->hasTargetIntelligenceColumns() && (array_key_exists('metadata_json', $data) || array_key_exists('progress_mode', $data))) {
            $finalProgressMode = array_key_exists('progress_mode', $data)
                ? $this->normalizeProgressMode($data['progress_mode'])
                : $this->normalizeProgressMode($target['progress_mode'] ?? 'manual');
            $metadata = array_key_exists('metadata_json', $data)
                ? $this->decodeMetadataJson($data['metadata_json'])
                : $this->decodeMetadataJson($target['metadata_json'] ?? null);
            $metadataJson = $this->normalizeMetadataJson($this->normalizeTargetMetadata(
                $metadata,
                $finalProgressMode,
                (int) ($target['workspace_id'] ?? $this->workspaceScope->requireActiveWorkspaceId())
            ));
            $updates[] = 'metadata_json = ?';
            $params[] = $metadataJson;
        }

        if (array_key_exists('reminder_frequency', $data) && !array_key_exists('custom_reminder_days', $data)) {
            $finalFrequency = $this->normalizeReminderFrequency($data['reminder_frequency']);
            $this->normalizeCustomReminderDays($target['custom_reminder_days'] ?? null, $finalFrequency);
        }

        $effectiveStartDate = array_key_exists('start_date', $data)
            ? $this->normalizeDate($data['start_date'], 'Start date', false)
            : (!empty($target['start_date']) ? (string) $target['start_date'] : null);
        $effectiveTargetDate = array_key_exists('target_date', $data)
            ? $this->normalizeDate($data['target_date'], 'Target date (deadline)', true)
            : (string) ($target['target_date'] ?? '');
        $this->assertTargetDateWindow($effectiveStartDate, $effectiveTargetDate);
        
        // Handle completion
        $wasCompleted = $target['status'] === 'completed';
        $effectiveStatus = array_key_exists('status', $data)
            ? $this->normalizeStatus($data['status'])
            : (string) ($target['status'] ?? 'active');
        $effectiveCurrentValue = array_key_exists('current_value', $data)
            ? $this->normalizeNonNegativeDecimal($data['current_value'], 'Current value')
            : (float) ($target['current_value'] ?? 0);
        $effectiveTargetValue = array_key_exists('target_value', $data)
            ? $this->normalizePositiveDecimal($data['target_value'], 'Target value', true)
            : (float) ($target['target_value'] ?? 0);

        if ($effectiveStatus === 'completed' && !$wasCompleted) {
            $updates[] = "completed_at = NOW()";
        } elseif ($effectiveStatus !== 'completed' && $wasCompleted) {
            $updates[] = "completed_at = NULL";
        }
        
        // Check if target is achieved
        if ($effectiveStatus === 'active' && $effectiveTargetValue > 0 && $effectiveCurrentValue >= $effectiveTargetValue) {
            $updates[] = "status = 'completed'";
            $updates[] = "completed_at = NOW()";
        }

        if (empty($updates)) {
            return false;
        }
        if ($this->columnExists('state_version')) {
            $updates[] = 'state_version = state_version + 1';
        }
        
        $params[] = $this->workspaceScope->requireActiveWorkspaceId();
        $params[] = $id;
        
        Database::execute(
            "UPDATE targets SET " . implode(', ', $updates) . " WHERE workspace_id = ? AND id = ?",
            $params
        );

        // Auto-detect missed targets after the new date/value/status is in place.
        $this->checkAndMarkMissed($id);
        
        // Reschedule reminders if frequency changed
        if (isset($data['reminder_frequency']) || isset($data['target_date'])) {
            $updatedTarget = $this->getById($id);
            $reminders = new \CRM\Modules\TargetReminders();
            $reminders->rescheduleReminders($id, $updatedTarget['reminder_frequency'], 
                                           $updatedTarget['target_date'], 
                                           $updatedTarget['custom_reminder_days']);
        }
        
        if ($this->hasTargetIntelligenceColumns()) {
            (new TargetIntelligenceService())->syncTarget($id, (int) ($target['workspace_id'] ?? $this->workspaceScope->requireActiveWorkspaceId()));
        }

        $updatedTarget = $this->getById($id) ?: $target;
        $eventName = 'target.updated';
        if (($updatedTarget['status'] ?? '') === 'completed' && !$wasCompleted) {
            $eventName = 'target.completed';
        }
        $this->lifecycleEvents->emit($eventName, 'target', $id, $updatedTarget, [
            'workspace_id' => (int) ($updatedTarget['workspace_id'] ?? $this->workspaceScope->requireActiveWorkspaceId()),
            'actor_user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'changes' => $data,
        ]);

        return true;
    }
    
    /**
     * Update progress (current_value)
     */
    public function updateProgress(int $id, float $currentValue, ?int $expectedVersion = null): bool
    {
        $currentValue = $this->normalizeNonNegativeDecimal($currentValue, 'Current value');
        return (new TargetCoordinator())->updateProgress(
            $id,
            $this->workspaceScope->requireActiveWorkspaceId(),
            $currentValue,
            (int) ($_SESSION['user_id'] ?? 0),
            $expectedVersion
        );
    }
    
    /**
     * Delete target
     */
    public function delete(int $id): bool
    {
        $target = $this->getById($id);
        if (!$target) {
            return false;
        }
        $workspaceId = (int) ($target['workspace_id'] ?? $this->workspaceScope->requireActiveWorkspaceId());

        try {
            Database::beginTransaction();

            // Keep deletion correct on legacy/test schemas where target child foreign keys may be absent.
            foreach (['target_evidence', 'target_state_transitions', 'target_automation_proposals', 'target_milestones', 'target_reminders', 'target_advice'] as $childTable) {
                if (Database::tableExists($childTable)) {
                    Database::execute("DELETE FROM `{$childTable}` WHERE workspace_id = ? AND target_id = ?", [$workspaceId, $id]);
                }
            }

            // Generic FK cleanup for mixed/legacy schemas:
            // - CASCADE: no action needed
            // - SET NULL: null child references
            // - RESTRICT/NO ACTION: delete child rows
            $references = Database::query(
                "SELECT rc.TABLE_NAME, kcu.COLUMN_NAME, rc.DELETE_RULE
                 FROM information_schema.REFERENTIAL_CONSTRAINTS rc
                 INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
                    ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                   AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                   AND rc.TABLE_NAME = kcu.TABLE_NAME
                 WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
                   AND rc.REFERENCED_TABLE_NAME = 'targets'"
            );

            foreach ($references as $reference) {
                $table = (string) ($reference['TABLE_NAME'] ?? '');
                $column = (string) ($reference['COLUMN_NAME'] ?? '');
                $deleteRule = strtoupper((string) ($reference['DELETE_RULE'] ?? ''));

                // Identifier safety: allow only typical SQL identifiers.
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                    continue;
                }

                if ($deleteRule === 'CASCADE') {
                    continue;
                }

                if ($deleteRule === 'SET NULL') {
                    try {
                        Database::execute("UPDATE `{$table}` SET `{$column}` = NULL WHERE `{$column}` = ?", [$id]);
                        continue;
                    } catch (\Throwable $e) {
                        // If SET NULL fails (e.g., NOT NULL column in legacy schema), fall through to delete.
                    }
                }

                Database::execute("DELETE FROM `{$table}` WHERE `{$column}` = ?", [$id]);
            }

            $deleted = Database::execute("DELETE FROM targets WHERE workspace_id = ? AND id = ?", [$workspaceId, $id]) > 0;
            Database::commit();
            if ($deleted) {
                $this->lifecycleEvents->emit('target.deleted', 'target', $id, $target, [
                    'workspace_id' => $workspaceId,
                    'actor_user_id' => (int) ($_SESSION['user_id'] ?? 0),
                    'metadata' => ['title' => (string) ($target['title'] ?? '')],
                ]);
            }

            return $deleted;
        } catch (\Throwable $e) {
            try {
                Database::rollBack();
            } catch (\Throwable $rollbackError) {
                // Ignore rollback errors.
            }
            throw $e;
        }
    }
    
    /**
     * Get targets with filters
     */
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $workspace = $this->workspaceScope->workspaceClause('t.');
        $where = [$workspace['sql']];
        $params = $workspace['params'];
        
        if (!empty($filters['user_id'])) {
            $scopeFilter = $this->buildAccessibleScopeWhere((int) $filters['user_id'], $filters);
            if ($scopeFilter['sql'] !== '') {
                $where[] = $scopeFilter['sql'];
                $params = array_merge($params, $scopeFilter['params']);
            }
        }
        
        if (!empty($filters['status'])) {
            $where[] = "t.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['target_type'])) {
            $where[] = "t.target_type = ?";
            $params[] = $filters['target_type'];
        }

        if ($this->hasScopeColumn() && !empty($filters['scope'])) {
            $where[] = "t.scope = ?";
            $params[] = $this->normalizeScope($filters['scope']);
        }

        if ($this->hasProgressModeColumn() && !empty($filters['progress_mode'])) {
            $where[] = "t.progress_mode = ?";
            $params[] = $this->normalizeProgressMode($filters['progress_mode']);
        }

        if ($this->hasTargetIntelligenceColumns() && !empty($filters['rollup_source'])) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(t.metadata_json, '$.rollup_definition.source')) = ?";
            $params[] = strtolower(trim((string) $filters['rollup_source']));
        }

        if ($this->hasTargetIntelligenceColumns() && !empty($filters['status_band'])) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(t.metadata_json, '$.intelligence.status_band')) = ?";
            $params[] = $filters['status_band'];
        }
        
        if (!empty($filters['search'])) {
            $searchTerm = '%' . Security::sanitizeInput($filters['search'], 'string') . '%';
            $where[] = "(t.title LIKE ? OR t.description LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if (!empty($filters['overdue'])) {
            $where[] = "t.target_date < CURDATE() AND t.status = 'active'";
        }
        
        if (!empty($filters['upcoming'])) {
            $where[] = "t.target_date >= CURDATE() AND t.status = 'active'";
        }
        
        $sql = "SELECT t.*, 
                       u.email as user_email
                FROM targets t
                LEFT JOIN users u ON t.user_id = u.id";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " ORDER BY 
                    CASE t.status
                        WHEN 'active' THEN 1
                        WHEN 'completed' THEN 2
                        WHEN 'missed' THEN 3
                        WHEN 'cancelled' THEN 4
                    END,
                    t.target_date ASC,
                    t.created_at DESC
                  LIMIT " . max(1, (int) $limit) . " OFFSET " . max(0, (int) $offset);
        
        $targets = Database::query($sql, $params);
        
        // Add calculated fields
        foreach ($targets as &$target) {
            $targetId = (int) ($target['id'] ?? 0);
            if ($this->hasTargetIntelligenceColumns()) {
                $target = (new TargetIntelligenceService())->enrichTarget($target, false);
            } else {
                $target['progress_percentage'] = $this->calculateProgress($target);
                $target['days_remaining'] = $this->getDaysRemaining($target);
                $target['is_on_track'] = $this->isOnTrack($target);
                $target['status_category'] = $this->getStatusCategory($target);
            }
            $target['id'] = $targetId;
        }
        unset($target);
        
        return $targets;
    }
    
    /**
     * Get user's targets
     */
    public function getUserTargets(int $userId, array $filters = []): array
    {
        $filters['user_id'] = $userId;
        return $this->getAll($filters);
    }
    
    /**
     * Calculate progress percentage
     */
    public function calculateProgress(array $target): float
    {
        $targetValue = (float) $target['target_value'];
        $currentValue = (float) $target['current_value'];
        
        if ($targetValue <= 0) {
            return 0;
        }
        
        $percentage = ($currentValue / $targetValue) * 100;
        return min(100, max(0, round($percentage, 2)));
    }
    
    /**
     * Get days remaining until target date
     */
    public function getDaysRemaining(array $target): ?int
    {
        if (empty($target['target_date'])) {
            return null;
        }
        
        $targetDate = strtotime($target['target_date']);
        $today = strtotime(date('Y-m-d'));
        $diff = $targetDate - $today;
        
        return (int) ceil($diff / 86400);
    }
    
    /**
     * Check if target is on track
     */
    public function isOnTrack(array $target): bool
    {
        if ($target['status'] !== 'active') {
            return false;
        }
        
        $progress = $this->calculateProgress($target);
        $daysRemaining = $this->getDaysRemaining($target);
        
        if ($daysRemaining === null || $daysRemaining <= 0) {
            return $progress >= 100;
        }
        
        // Calculate expected progress based on time elapsed
        $startDate = strtotime($target['start_date'] ?? $target['created_at']);
        $targetDate = strtotime($target['target_date']);
        $today = strtotime(date('Y-m-d'));
        
        $totalDays = ($targetDate - $startDate) / 86400;
        $daysElapsed = ($today - $startDate) / 86400;
        
        if ($totalDays <= 0) {
            return $progress >= 100;
        }
        
        $expectedProgress = ($daysElapsed / $totalDays) * 100;
        
        // Consider on track if progress is within 10% of expected
        return $progress >= ($expectedProgress - 10);
    }
    
    /**
     * Get status category (on_track, at_risk, behind, completed, missed)
     */
    public function getStatusCategory(array $target): string
    {
        if ($target['status'] === 'completed') {
            return 'completed';
        }
        
        if ($target['status'] === 'missed' || $target['status'] === 'cancelled') {
            return $target['status'];
        }
        
        if ($this->isOnTrack($target)) {
            return 'on_track';
        }
        
        $daysRemaining = $this->getDaysRemaining($target);
        $progress = $this->calculateProgress($target);
        
        if ($daysRemaining !== null && $daysRemaining < 0) {
            return 'missed';
        }
        
        if ($daysRemaining !== null && $daysRemaining <= 3 && $progress < 80) {
            return 'at_risk';
        }
        
        return 'behind';
    }
    
    /**
     * Check and mark missed targets
     */
    public function checkAndMarkMissed(?int $targetId = null): void
    {
        $where = $targetId ? "AND id = ?" : "";
        $params = [$this->workspaceScope->requireActiveWorkspaceId()];
        if ($targetId) {
            $params[] = $targetId;
        }
        
        Database::execute(
            "UPDATE targets 
             SET status = 'missed' 
             WHERE workspace_id = ?
             AND status = 'active'
             AND target_date < CURDATE() 
             AND current_value < target_value
             $where",
            $params
        );
    }
    
    /**
     * Get target count
     */
    public function getCount(array $filters = []): int
    {
        $workspace = $this->workspaceScope->workspaceClause();
        $where = [$workspace['sql']];
        $params = $workspace['params'];
        
        if (!empty($filters['user_id'])) {
            $scopeFilter = $this->buildAccessibleScopeWhere((int) $filters['user_id'], $filters, false);
            if ($scopeFilter['sql'] !== '') {
                $where[] = $scopeFilter['sql'];
                $params = array_merge($params, $scopeFilter['params']);
            }
        }
        
        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['target_type'])) {
            $where[] = "target_type = ?";
            $params[] = $filters['target_type'];
        }

        if ($this->hasScopeColumn() && !empty($filters['scope'])) {
            $where[] = "scope = ?";
            $params[] = $this->normalizeScope($filters['scope']);
        }

        if ($this->hasProgressModeColumn() && !empty($filters['progress_mode'])) {
            $where[] = "progress_mode = ?";
            $params[] = $this->normalizeProgressMode($filters['progress_mode']);
        }

        if ($this->hasTargetIntelligenceColumns() && !empty($filters['rollup_source'])) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.rollup_definition.source')) = ?";
            $params[] = strtolower(trim((string) $filters['rollup_source']));
        }

        if ($this->hasTargetIntelligenceColumns() && !empty($filters['status_band'])) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.intelligence.status_band')) = ?";
            $params[] = $filters['status_band'];
        }
        
        if (!empty($filters['overdue'])) {
            $where[] = "target_date < CURDATE() AND status = 'active'";
        }
        
        $sql = "SELECT COUNT(*) as count FROM targets";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $result = Database::queryOne($sql, $params);
        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Mark target as complete
     */
    public function markComplete(int $id): bool
    {
        return (new TargetCoordinator())->completeManually(
            $id,
            $this->workspaceScope->requireActiveWorkspaceId(),
            (int) ($_SESSION['user_id'] ?? 0),
            ['decision_source' => 'compatibility']
        );
    }

    private function normalizeTargetType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, ['sales', 'personal', 'performance', 'custom'], true)) {
            throw new \InvalidArgumentException('Invalid target type');
        }

        return $value;
    }

    private function normalizeReminderFrequency(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, ['daily', 'weekly', 'deadline', 'custom'], true)) {
            throw new \InvalidArgumentException('Invalid reminder frequency');
        }

        return $value;
    }

    private function normalizeStatus(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, ['active', 'completed', 'missed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Invalid target status');
        }

        return $value;
    }

    private function normalizeDecimal(mixed $value, string $label): float
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === '' || $value === null || !is_numeric($value)) {
            throw new \Exception($label . ' must be a valid number');
        }

        return (float) $value;
    }

    private function normalizePositiveDecimal(mixed $value, string $label, bool $required = false): float
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if (($value === '' || $value === null) && $required) {
            throw new \Exception($label . ' is required');
        }

        $number = $this->normalizeDecimal($value, $label);
        if ($number <= 0) {
            throw new \Exception($label . ' must be greater than zero');
        }

        return $number;
    }

    private function normalizeNonNegativeDecimal(mixed $value, string $label): float
    {
        $number = $this->normalizeDecimal($value, $label);
        if ($number < 0) {
            throw new \Exception($label . ' cannot be negative');
        }

        return $number;
    }

    private function normalizeDate(mixed $value, string $label, bool $required): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            if ($required) {
                throw new \Exception($label . ' is required');
            }
            return null;
        }

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            throw new \Exception('Invalid ' . strtolower($label) . ' format');
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        if (!checkdate($month, $day, $year)) {
            throw new \Exception('Invalid ' . strtolower($label) . ' format');
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function assertTargetDateWindow(?string $startDate, string $targetDate): void
    {
        if ($startDate === null || $startDate === '') {
            return;
        }

        if (strtotime($targetDate) < strtotime($startDate)) {
            throw new \Exception("Target date cannot be before start date");
        }
    }

    private function normalizeCustomReminderDays(mixed $value, string $frequency): ?int
    {
        if ($frequency !== 'custom') {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === '' || $value === null || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new \Exception('Custom reminder days must be a positive whole number');
        }

        return (int) $value;
    }

    private function normalizeTargetMetadata(array $metadata, string $progressMode, int $workspaceId): array
    {
        $metadata = $metadata ?: [];
        $rollupDefinition = (array) ($metadata['rollup_definition'] ?? []);

        if ($progressMode === 'manual') {
            $metadata['rollup_definition'] = null;
        } else {
            $normalizedRollup = (new TargetPluginIntegrationService())->normalizeRollupDefinition($workspaceId, $rollupDefinition);
            if ($normalizedRollup === []) {
                throw new \Exception('Auto-rollup targets require a valid rollup source and metric');
            }
            $metadata['rollup_definition'] = $normalizedRollup;
        }

        $metadata['milestones'] = $this->normalizeMetadataMilestones((array) ($metadata['milestones'] ?? []));
        return $metadata;
    }

    private function normalizeMetadataMilestones(array $milestones): array
    {
        $normalized = [];
        foreach ($milestones as $index => $milestone) {
            if (!is_array($milestone)) {
                continue;
            }
            $title = Security::sanitizeInput($milestone['title'] ?? '', 'string');
            if ($title === '') {
                continue;
            }
            $dueDate = null;
            if (!empty($milestone['due_date'])) {
                $dueDate = $this->normalizeDate($milestone['due_date'], 'Milestone due date', false);
            }
            $normalized[] = [
                'title' => $title,
                'target_value' => $this->normalizeNonNegativeDecimal($milestone['target_value'] ?? 0, 'Milestone target value'),
                'due_date' => $dueDate ?? '',
                'sort_order' => (int) ($milestone['sort_order'] ?? $index),
            ];
        }

        return $normalized;
    }

    private function hasScopeColumn(): bool
    {
        if (self::$hasScopeColumn !== null) {
            return self::$hasScopeColumn;
        }
        self::$hasScopeColumn = $this->columnExists('scope');
        return self::$hasScopeColumn;
    }

    private function hasProgressModeColumn(): bool
    {
        if (self::$hasProgressModeColumn !== null) {
            return self::$hasProgressModeColumn;
        }
        self::$hasProgressModeColumn = $this->columnExists('progress_mode');
        return self::$hasProgressModeColumn;
    }

    private function hasTargetIntelligenceColumns(): bool
    {
        return $this->hasScopeColumn() && $this->hasProgressModeColumn() && $this->hasManualAdjustmentColumn() && $this->hasMetadataJsonColumn();
    }

    private function hasManualAdjustmentColumn(): bool
    {
        if (self::$hasManualAdjustmentColumn !== null) {
            return self::$hasManualAdjustmentColumn;
        }
        self::$hasManualAdjustmentColumn = $this->columnExists('manual_adjustment_value');
        return self::$hasManualAdjustmentColumn;
    }

    private function hasMetadataJsonColumn(): bool
    {
        if (self::$hasMetadataJsonColumn !== null) {
            return self::$hasMetadataJsonColumn;
        }
        self::$hasMetadataJsonColumn = $this->columnExists('metadata_json');
        return self::$hasMetadataJsonColumn;
    }

    private function columnExists(string $column): bool
    {
        try {
            $row = Database::queryOne(
                "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'targets' AND COLUMN_NAME = ?",
                [$column]
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasSourceColumn(string $column): bool
    {
        if (!preg_match('/^source_[a-z_]+$/', $column)) {
            return false;
        }
        if (!array_key_exists($column, self::$sourceColumnCache)) {
            self::$sourceColumnCache[$column] = $this->columnExists($column);
        }

        return self::$sourceColumnCache[$column];
    }

    private function sourceColumnsFromData(array $data): array
    {
        $metadata = $this->decodeMetadataJson($data['metadata_json'] ?? null);
        return [
            'source_skill_key' => $this->normalizeSourceValue((string) ($data['source_skill_key'] ?? $metadata['marketplace_skill_key'] ?? ''), false),
            'source_plugin_key' => $this->normalizeSourceValue((string) ($data['source_plugin_key'] ?? $metadata['marketplace_plugin_key'] ?? ''), false),
            'source_capability_key' => $this->normalizeSourceValue((string) ($data['source_capability_key'] ?? $metadata['source_capability_key'] ?? ''), true),
        ];
    }

    private function persistSourceColumns(int $targetId, int $workspaceId, array $sourceColumns): void
    {
        if ($targetId <= 0 || $workspaceId <= 0) {
            return;
        }

        $updates = [];
        $params = [];
        foreach ($sourceColumns as $column => $value) {
            if (!$this->hasSourceColumn($column) || $value === null) {
                continue;
            }
            $updates[] = $column . ' = ?';
            $params[] = $value;
        }
        if ($updates === []) {
            return;
        }

        $params[] = $workspaceId;
        $params[] = $targetId;
        Database::execute(
            "UPDATE targets SET " . implode(', ', $updates) . " WHERE workspace_id = ? AND id = ?",
            $params
        );
    }

    private function normalizeSourceValue(string $value, bool $allowDot): ?string
    {
        $pattern = $allowDot ? '/[^a-z0-9_.]+/' : '/[^a-z0-9_]+/';
        $value = strtolower(preg_replace($pattern, '_', trim($value)) ?? '');
        $value = trim($value, '_');
        return $value === '' ? null : substr($value, 0, $allowDot ? 120 : 80);
    }

    private function normalizeScope(mixed $scope): string
    {
        $scope = strtolower(trim((string) $scope));
        return in_array($scope, ['personal', 'team', 'company'], true) ? $scope : 'personal';
    }

    private function isSharedScope(mixed $scope): bool
    {
        $scope = $this->normalizeScope($scope);
        return in_array($scope, ['team', 'company'], true);
    }

    private function normalizeProgressMode(mixed $mode): string
    {
        $mode = strtolower(trim((string) $mode));
        return in_array($mode, ['manual', 'auto_rollup', 'hybrid'], true) ? $mode : 'manual';
    }

    private function normalizeMetadataJson(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return json_encode(is_array($decoded) ? $decoded : ['raw' => $value], JSON_UNESCAPED_SLASHES);
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        return null;
    }

    private function decodeMetadataJson(mixed $value): array
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

    private function buildAccessibleScopeWhere(int $userId, array $filters, bool $withAlias = true): array
    {
        $prefix = $withAlias ? 't.' : '';
        if (!$this->hasScopeColumn()) {
            return [
                'sql' => $prefix . "user_id = ?",
                'params' => [$userId],
            ];
        }

        $scope = !empty($filters['scope']) ? $this->normalizeScope($filters['scope']) : '';
        if ($scope === 'personal') {
            return ['sql' => $prefix . "user_id = ?", 'params' => [$userId]];
        }
        if ($scope === 'team' || $scope === 'company') {
            return ['sql' => $prefix . "scope = ?", 'params' => [$scope]];
        }

        return [
            'sql' => "(" . $prefix . "user_id = ? OR " . $prefix . "scope IN ('team', 'company'))",
            'params' => [$userId],
        ];
    }

    private function canManageTarget(array $target, array $user): bool
    {
        if (empty($target) || empty($user['id'])) {
            return false;
        }

        if ((int) ($target['user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
            return true;
        }

        return Authorization::can('targets.manage_all', $user);
    }
}
