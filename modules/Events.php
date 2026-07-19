<?php
/**
 * Events Management Module
 * 
 * Handles event/calendar CRUD operations
 */

namespace CRM\Modules;

use CRM\Authorization;
use CRM\Concurrency;
use CRM\Database;
use CRM\Security;
use CRM\Modules\Notifications;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;

class Events
{
    private const RECURRENCE_EXPANSION_LIMIT = 500;

    /** @var array<string, bool> */
    private static array $columnSupportCache = [];

    public function canViewAll(?array $user): bool
    {
        return Authorization::can('events.view_all', $user);
    }

    public function supportsRecurrence(): bool
    {
        return $this->eventColumnExists('recurrence_pattern')
            && $this->eventColumnExists('recurrence_end_date')
            && $this->eventColumnExists('recurrence_count');
    }

    public function supportsCustomFields(): bool
    {
        return $this->eventColumnExists('custom_fields');
    }

    public function supportsParentEventLinks(): bool
    {
        return $this->eventColumnExists('parent_event_id');
    }

    public function isAllDayEvent(array $event): bool
    {
        return !empty($event['is_all_day']);
    }

    public function formatEventTimeLabel(array $event): string
    {
        if ($this->isAllDayEvent($event)) {
            return 'All day';
        }

        return date('g:i A', strtotime((string) ($event['start_time'] ?? 'now')));
    }

    public function formatEventDateTimeRange(array $event): string
    {
        $start = (string) ($event['start_time'] ?? '');
        $end = (string) ($event['end_time'] ?? '');

        if ($start === '') {
            return '';
        }

        if ($this->isAllDayEvent($event)) {
            $startDate = date('M d, Y', strtotime($start));
            if ($end === '') {
                return $startDate . ' All day';
            }

            $endDate = date('M d, Y', strtotime($end));
            if ($startDate === $endDate) {
                return $startDate . ' All day';
            }

            return $startDate . ' - ' . $endDate . ' All day';
        }

        $startLabel = date('M d, Y g:i A', strtotime($start));
        if ($end === '') {
            return $startLabel;
        }

        return $startLabel . ' - ' . date('M d, Y g:i A', strtotime($end));
    }

    public function eventSpansMultipleDays(array $event): bool
    {
        $start = strtotime((string) ($event['start_time'] ?? ''));
        $end = strtotime((string) ($event['end_time'] ?? $event['start_time'] ?? ''));
        if ($start === false || $end === false) {
            return false;
        }

        return date('Y-m-d', $start) !== date('Y-m-d', $end);
    }

    /**
     * @return list<string>
     */
    public function getDatesCoveredByEvent(array $event, string $rangeStartDate, string $rangeEndDate): array
    {
        $start = strtotime((string) ($event['start_time'] ?? ''));
        if ($start === false) {
            return [];
        }

        $end = strtotime((string) ($event['end_time'] ?? ''));
        if ($end === false) {
            $end = $start;
        }

        $startDate = max(date('Y-m-d', $start), $this->normalizeDateValue($rangeStartDate) ?? $rangeStartDate);
        $endDate = min(date('Y-m-d', $end), $this->normalizeDateValue($rangeEndDate) ?? $rangeEndDate);
        if (strtotime($endDate) < strtotime($startDate)) {
            return [];
        }

        $dates = [];
        $cursor = new \DateTimeImmutable($startDate);
        $last = new \DateTimeImmutable($endDate);
        while ($cursor <= $last) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }

    public function canAccessEvent(array $event, ?array $user): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        if ($this->canViewAll($user)) {
            return true;
        }

        return (int) ($event['assigned_to'] ?? 0) === $userId;
    }

    public function getByIdForUser(int $id, ?array $user): ?array
    {
        $event = $this->getById($id);
        if (!$event || !$this->canAccessEvent($event, $user)) {
            return null;
        }

        return $event;
    }

    public function applyVisibilityScope(array $filters, ?array $user): array
    {
        $scoped = $filters;
        $userId = (int) ($user['id'] ?? 0);

        if ($this->canViewAll($user)) {
            if (array_key_exists('assigned_to', $scoped)) {
                $assignedTo = $scoped['assigned_to'];
                if ($assignedTo === '' || $assignedTo === null) {
                    unset($scoped['assigned_to']);
                } else {
                    $assignedTo = (int) $assignedTo;
                    if ($assignedTo > 0) {
                        $scoped['assigned_to'] = $assignedTo;
                    } else {
                        unset($scoped['assigned_to']);
                    }
                }
            }

            return $scoped;
        }

        if ($userId > 0) {
            $scoped['assigned_to'] = $userId;
        } else {
            $scoped['assigned_to'] = -1;
        }

        return $scoped;
    }

    public function resolveAssignedToForUser($requestedAssignedTo, ?array $user): ?int
    {
        $userId = (int) ($user['id'] ?? 0);
        if (!$this->canViewAll($user)) {
            return $userId > 0 ? $userId : null;
        }

        if ($requestedAssignedTo === '' || $requestedAssignedTo === null) {
            return null;
        }

        $assignedTo = (int) $requestedAssignedTo;
        return $assignedTo > 0 ? $assignedTo : null;
    }

    /**
     * Create a new event
     */
    public function create(array $data): int
    {
        if (empty($data['title'])) {
            throw new \Exception("Event title and start time are required");
        }

        $title = Security::sanitizeInput($data['title'], 'string');
        $description = Security::sanitizeInput($data['description'] ?? '', 'string');
        $eventType = $data['event_type'] ?? 'meeting';
        $contactId = !empty($data['contact_id']) ? (int) $data['contact_id'] : null;
        $assignedTo = !empty($data['assigned_to']) ? (int) $data['assigned_to'] : null;
        $createdBy = (int) ($data['created_by'] ?? $_SESSION['user_id'] ?? 0);
        $workspaceId = $this->requireWorkspaceId();
        $this->assertContactAccessibleToUser($contactId, $workspaceId, $createdBy);
        $this->assertUserInWorkspace($assignedTo, $workspaceId);
        $location = Security::sanitizeInput($data['location'] ?? '', 'string');
        $reminderMinutes = !empty($data['reminder_minutes']) ? (int) $data['reminder_minutes'] : null;
        $status = $data['status'] ?? 'scheduled';
        $schedule = $this->normalizeSchedulePayload($data);
        $startTime = $schedule['start_time'];
        $endTime = $schedule['end_time'];
        $isAllDay = $schedule['is_all_day'];

        $allowedTypes = ['meeting', 'call', 'email', 'task', 'other'];
        $allowedStatuses = ['scheduled', 'completed', 'cancelled', 'postponed'];
        
        if (!in_array($eventType, $allowedTypes)) {
            $eventType = 'meeting';
        }
        if (!in_array($status, $allowedStatuses)) {
            $status = 'scheduled';
        }

        $fields = [
            'workspace_id',
            'title',
            'description',
            'event_type',
            'contact_id',
            'assigned_to',
            'created_by',
            'start_time',
            'end_time',
            'location',
            'is_all_day',
            'reminder_minutes',
            'status',
        ];
        $values = [
            $workspaceId,
            $title,
            $description,
            $eventType,
            $contactId,
            $assignedTo,
            $createdBy,
            $startTime,
            $endTime,
            $location,
            $isAllDay,
            $reminderMinutes,
            $status,
        ];

        if ($this->supportsRecurrence()) {
            $recurrence = $this->normalizeRecurrencePayload($data);
            $fields = array_merge($fields, ['recurrence_pattern', 'recurrence_end_date', 'recurrence_count']);
            $values = array_merge($values, [
                $recurrence['recurrence_pattern'],
                $recurrence['recurrence_end_date'],
                $recurrence['recurrence_count'],
            ]);
        }

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        Database::execute(
            "INSERT INTO events (" . implode(', ', $fields) . ")
             VALUES ($placeholders)",
            $values
        );
        
        $eventId = (int) Database::lastInsertId();
        
        // Log activity if event is associated with a contact
        if ($contactId) {
            $activities = new Activities();
            $activities->log($contactId, 'meeting', "Event scheduled: $title", [
                'event_id' => $eventId,
                'start_time' => $startTime,
                'by' => $createdBy
            ]);
        }
        
        return $eventId;
    }
    
    /**
     * Get event by ID
     */
    public function getById(int $id): ?array
    {
        $event = Database::queryOne(
            "SELECT e.*, 
                    c.first_name as contact_first_name, 
                    c.last_name as contact_last_name, 
                    c.email as contact_email,
                    u1.email as assigned_to_email,
                    u2.email as created_by_email
             FROM events e
             LEFT JOIN contacts c ON e.contact_id = c.id AND c.workspace_id = e.workspace_id
             LEFT JOIN users u1 ON e.assigned_to = u1.id
             LEFT JOIN users u2 ON e.created_by = u2.id
             WHERE e.workspace_id = ? AND e.id = ?",
            [$this->requireWorkspaceId(), $id]
        );
        
        return $event;
    }
    
    /**
     * Update event
     */
    public function update(int $id, array $data): bool
    {
        $event = $this->getById($id);
        if (!$event) {
            throw new \Exception("Event not found");
        }
        $workspaceId = (int) ($event['workspace_id'] ?? $this->requireWorkspaceId());
        $actorUserId = (int) ($data['actor_user_id'] ?? $_SESSION['user_id'] ?? 0);
        
        $updates = [];
        $params = [];

        $supportedFields = [
            'title' => static function ($value) {
                return Security::sanitizeInput((string) $value, 'string');
            },
            'description' => static function ($value) {
                return Security::sanitizeInput((string) $value, 'string');
            },
            'event_type' => static function ($value) {
                $allowedTypes = ['meeting', 'call', 'email', 'task', 'other'];
                return in_array($value, $allowedTypes, true) ? $value : 'meeting';
            },
            'contact_id' => static function ($value) {
                return !empty($value) ? (int) $value : null;
            },
            'assigned_to' => static function ($value) {
                return !empty($value) ? (int) $value : null;
            },
            'location' => static function ($value) {
                return Security::sanitizeInput((string) $value, 'string');
            },
            'reminder_minutes' => static function ($value) {
                return !empty($value) ? (int) $value : null;
            },
            'status' => static function ($value) {
                $allowedStatuses = ['scheduled', 'completed', 'cancelled', 'postponed'];
                return in_array($value, $allowedStatuses, true) ? $value : 'scheduled';
            },
        ];

        foreach ($supportedFields as $field => $transform) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $transform($data[$field]);
            }
        }

        if (array_key_exists('contact_id', $updates)) {
            $this->assertContactAccessibleToUser(
                $updates['contact_id'] !== null ? (int) $updates['contact_id'] : null,
                $workspaceId,
                $actorUserId
            );
        }
        if (array_key_exists('assigned_to', $updates)) {
            $this->assertUserInWorkspace($updates['assigned_to'] !== null ? (int) $updates['assigned_to'] : null, $workspaceId);
        }

        if (
            array_key_exists('start_time', $data)
            || array_key_exists('end_time', $data)
            || array_key_exists('is_all_day', $data)
        ) {
            $schedule = $this->normalizeSchedulePayload([
                'start_time' => $data['start_time'] ?? $event['start_time'] ?? '',
                'end_time' => array_key_exists('end_time', $data) ? $data['end_time'] : ($event['end_time'] ?? null),
                'is_all_day' => array_key_exists('is_all_day', $data) ? $data['is_all_day'] : ($event['is_all_day'] ?? 0),
            ]);
            $updates['start_time'] = $schedule['start_time'];
            $updates['end_time'] = $schedule['end_time'];
            $updates['is_all_day'] = $schedule['is_all_day'];
        }

        if ($this->supportsRecurrence()) {
            foreach (['recurrence_pattern', 'recurrence_end_date', 'recurrence_count'] as $field) {
                if (array_key_exists($field, $data)) {
                    $recurrence = $this->normalizeRecurrencePayload([
                        'recurrence_pattern' => $data['recurrence_pattern'] ?? ($event['recurrence_pattern'] ?? 'none'),
                        'recurrence_end_date' => array_key_exists('recurrence_end_date', $data) ? $data['recurrence_end_date'] : ($event['recurrence_end_date'] ?? null),
                        'recurrence_count' => array_key_exists('recurrence_count', $data) ? $data['recurrence_count'] : ($event['recurrence_count'] ?? null),
                    ]);
                    $updates['recurrence_pattern'] = $recurrence['recurrence_pattern'];
                    $updates['recurrence_end_date'] = $recurrence['recurrence_end_date'];
                    $updates['recurrence_count'] = $recurrence['recurrence_count'];
                    break;
                }
            }
        }
        
        if (empty($updates)) {
            return false;
        }

        $submittedFields = $updates;
        foreach ($updates as $field => $value) {
            $params[] = $value;
            $updates[$field] = "$field = ?";
        }

        Concurrency::executeWorkspaceUpdate(
            'events',
            $workspaceId,
            $id,
            array_values($updates),
            $params,
            Concurrency::expectedVersionFromData($data),
            fn(): ?array => $this->getById($id),
            $submittedFields,
            'event'
        );
        
        // Log activity if event is associated with a contact
        if ($event['contact_id']) {
            $activities = new Activities();
            $activities->log($event['contact_id'], 'meeting', "Event updated: " . ($data['title'] ?? $event['title']), [
                'event_id' => $id,
                'by' => $_SESSION['user_id'] ?? null,
                'changes' => $data
            ]);
        }
        
        return true;
    }
    
    /**
     * Delete event
     */
    public function delete(int $id): bool
    {
        $event = $this->getById($id);
        if (!$event) {
            return false;
        }
        
        Database::execute(
            "DELETE FROM events WHERE workspace_id = ? AND id = ?",
            [(int) ($event['workspace_id'] ?? $this->requireWorkspaceId()), $id]
        );
        
        return true;
    }
    
    /**
     * Get events with filters
     */
    public function getAll(array $filters = [], int $limit = 100, int $offset = 0, string $sortDirection = 'ASC'): array
    {
        $workspaceId = $this->requireWorkspaceId();
        $where = ["e.workspace_id = ?"];
        $params = [$workspaceId];
        
        $dateRange = $this->applyDateRangeFilters($where, $params, $filters, 'e.');
        
        if (!empty($filters['status'])) {
            $where[] = "e.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['event_type'])) {
            $where[] = "e.event_type = ?";
            $params[] = $filters['event_type'];
        }

        if (!empty($filters['ids']) && is_array($filters['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['ids']), static function (int $id): bool {
                return $id > 0;
            }));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $where[] = "e.id IN ($placeholders)";
                $params = array_merge($params, $ids);
            }
        }

        if (!empty($filters['assigned_to'])) {
            $where[] = "e.assigned_to = ?";
            $params[] = (int) $filters['assigned_to'];
        }
        
        if (!empty($filters['contact_id'])) {
            $where[] = "e.contact_id = ?";
            $params[] = (int) $filters['contact_id'];
        }
        
        if (!empty($filters['search'])) {
            $searchTerm = '%' . Security::sanitizeInput($filters['search'], 'string') . '%';
            $where[] = "(e.title LIKE ? OR e.description LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql = "SELECT e.*, 
                       c.first_name as contact_first_name, 
                       c.last_name as contact_last_name, 
                       c.email as contact_email,
                       u1.email as assigned_to_email,
                       u2.email as created_by_email
                FROM events e
                LEFT JOIN contacts c ON e.contact_id = c.id AND c.workspace_id = e.workspace_id
                LEFT JOIN users u1 ON e.assigned_to = u1.id
                LEFT JOIN users u2 ON e.created_by = u2.id";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sortDirection = strtoupper($sortDirection) === 'DESC' ? 'DESC' : 'ASC';
        $limit = max(0, $limit);
        $offset = max(0, $offset);
        $sql .= " ORDER BY e.start_time $sortDirection LIMIT $limit OFFSET $offset";
        
        $events = Database::query($sql, $params);
        if ($dateRange !== null) {
            $events = $this->expandEventsForDateRange($events, $dateRange['start_date'], $dateRange['end_date']);
            if ($sortDirection === 'DESC') {
                $events = array_reverse($events);
            }
        }

        return $events;
    }

    public function getAllForUser(array $filters, ?array $user, int $limit = 100, int $offset = 0, string $sortDirection = 'ASC'): array
    {
        return $this->getAll($this->applyVisibilityScope($filters, $user), $limit, $offset, $sortDirection);
    }
    
    /**
     * Get events for a date range (for calendar view)
     */
    public function getByDateRange(string $startDate, string $endDate, array $filters = []): array
    {
        $filters['start_date'] = $startDate;
        $filters['end_date'] = $endDate;
        return $this->getAll($filters, 1000, 0);
    }

    public function getByDateRangeForUser(string $startDate, string $endDate, array $filters, ?array $user): array
    {
        $filters['start_date'] = $startDate;
        $filters['end_date'] = $endDate;

        return $this->getAllForUser($filters, $user, 1000, 0);
    }
    
    /**
     * Get upcoming events
     */
    public function getUpcoming(int $limit = 10, int $userId = null): array
    {
        $workspaceId = WorkspaceContext::currentWorkspaceId();
        if ($workspaceId === null || $workspaceId <= 0) {
            return [];
        }
        $where = ["e.workspace_id = ?", "e.start_time >= NOW()", "e.status = 'scheduled'"];
        $params = [$workspaceId];
        
        if ($userId) {
            $where[] = "e.assigned_to = ?";
            $params[] = $userId;
        }
        
        $limit = max(0, $limit);

        $sql = "SELECT e.*, 
                       c.first_name as contact_first_name, 
                       c.last_name as contact_last_name, 
                       c.email as contact_email,
                       u1.email as assigned_to_email
                FROM events e
                LEFT JOIN contacts c ON e.contact_id = c.id AND c.workspace_id = e.workspace_id
                LEFT JOIN users u1 ON e.assigned_to = u1.id
                WHERE " . implode(" AND ", $where) . "
                ORDER BY e.start_time ASC
                LIMIT $limit";
        
        return Database::query($sql, $params);
    }
    
    /**
     * Get event count
     */
    public function getCount(array $filters = []): int
    {
        $where = ["workspace_id = ?"];
        $params = [$this->requireWorkspaceId()];
        
        $this->applyDateRangeFilters($where, $params, $filters, '');
        
        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['assigned_to'])) {
            $where[] = "assigned_to = ?";
            $params[] = (int) $filters['assigned_to'];
        }
        
        $sql = "SELECT COUNT(*) as count FROM events";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $result = Database::queryOne($sql, $params);
        return (int) ($result['count'] ?? 0);
    }

    private function requireWorkspaceId(): int
    {
        return (new WorkspaceScopeService())->requireActiveWorkspaceId();
    }

    private function assertContactInWorkspace(?int $contactId, int $workspaceId): void
    {
        if ($contactId === null || $contactId <= 0) {
            return;
        }

        (new WorkspaceScopeService())->assertSameWorkspace('contacts', $contactId, $workspaceId);
    }

    private function assertContactAccessibleToUser(?int $contactId, int $workspaceId, int $actorUserId): void
    {
        $this->assertContactInWorkspace($contactId, $workspaceId);
        if ($contactId === null || $contactId <= 0 || $actorUserId <= 0) {
            return;
        }

        $contact = Database::queryOne(
            "SELECT id, assigned_to FROM contacts WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$workspaceId, $contactId]
        );
        $actor = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$actorUserId]);
        if (!$contact || !$actor) {
            throw new \InvalidArgumentException('Selected contact is not accessible.');
        }

        $canViewAllContacts = Authorization::can('contacts.view_all', $actor);
        if (!(new Contacts())->isVisibleToUser($contact, $actorUserId, $canViewAllContacts)) {
            throw new \InvalidArgumentException('Selected contact is not accessible.');
        }
    }

    private function assertUserInWorkspace(?int $userId, int $workspaceId): void
    {
        if ($userId === null || $userId <= 0) {
            return;
        }

        $membership = Database::queryOne(
            "SELECT id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
               AND membership_status = 'active'
             LIMIT 1",
            [$workspaceId, $userId]
        );

        if (!$membership) {
            throw new \RuntimeException('The selected user is not a member of the active workspace.');
        }
    }

    private function normalizeSchedulePayload(array $data): array
    {
        $isAllDay = !empty($data['is_all_day']) ? 1 : 0;
        $startInput = trim((string) ($data['start_time'] ?? ''));
        $endInput = trim((string) ($data['end_time'] ?? ''));

        if ($startInput === '') {
            throw new \Exception("Event title and start time are required");
        }

        if ($isAllDay) {
            $startDate = $this->normalizeDateValue($startInput);
            if ($startDate === null) {
                throw new \Exception("All-day events require a valid start date");
            }

            $endDate = null;
            if ($endInput !== '') {
                $endDate = $this->normalizeDateValue($endInput);
                if ($endDate === null) {
                    throw new \Exception("All-day events require a valid end date");
                }
                if (strtotime($endDate) < strtotime($startDate)) {
                    throw new \Exception("Event end date must be on or after the start date");
                }
            }

            return [
                'start_time' => $startDate . ' 00:00:00',
                'end_time' => $endDate !== null ? $endDate . ' 23:59:59' : null,
                'is_all_day' => 1,
            ];
        }

        $startTime = $this->normalizeDateTimeValue($startInput);
        if ($startTime === null) {
            throw new \Exception("Timed events require a valid start time");
        }

        $endTime = null;
        if ($endInput !== '') {
            $endTime = $this->normalizeDateTimeValue($endInput);
            if ($endTime === null) {
                throw new \Exception("Timed events require a valid end time");
            }
            if (strtotime($endTime) < strtotime($startTime)) {
                throw new \Exception("Event end time must be on or after the start time");
            }
        }

        return [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'is_all_day' => 0,
        ];
    }

    private function normalizeRecurrencePayload(array $data): array
    {
        $allowedRecurrence = ['none', 'daily', 'weekly', 'monthly', 'yearly'];
        $pattern = (string) ($data['recurrence_pattern'] ?? 'none');
        if (!in_array($pattern, $allowedRecurrence, true)) {
            $pattern = 'none';
        }

        $endDate = !empty($data['recurrence_end_date']) ? $this->normalizeDateValue((string) $data['recurrence_end_date']) : null;
        $count = !empty($data['recurrence_count']) ? (int) $data['recurrence_count'] : null;
        if ($count !== null && $count <= 0) {
            $count = null;
        }

        if ($pattern === 'none') {
            return [
                'recurrence_pattern' => 'none',
                'recurrence_end_date' => null,
                'recurrence_count' => null,
            ];
        }

        return [
            'recurrence_pattern' => $pattern,
            'recurrence_end_date' => $endDate,
            'recurrence_count' => $count,
        ];
    }

    private function normalizeDateValue(string $value): ?string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function normalizeDateTimeValue(string $value): ?string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param list<string> $where
     * @param list<mixed> $params
     * @return array{start_date:string,end_date:string}|null
     */
    private function applyDateRangeFilters(array &$where, array &$params, array $filters, string $alias): ?array
    {
        $startDate = !empty($filters['start_date']) ? $this->normalizeDateValue((string) $filters['start_date']) : null;
        $endDate = !empty($filters['end_date']) ? $this->normalizeDateValue((string) $filters['end_date']) : null;

        if ($startDate !== null && $endDate !== null) {
            $rangeStart = $startDate . ' 00:00:00';
            $rangeEnd = $endDate . ' 23:59:59';
            $startColumn = $alias . 'start_time';
            $endColumn = $alias . 'end_time';
            if ($this->supportsRecurrence()) {
                $where[] = "(($startColumn <= ? AND COALESCE($endColumn, $startColumn) >= ?)
                    OR ({$alias}recurrence_pattern <> 'none'
                        AND $startColumn <= ?
                        AND ({$alias}recurrence_end_date IS NULL OR {$alias}recurrence_end_date >= ?)))";
                array_push($params, $rangeEnd, $rangeStart, $rangeEnd, $startDate);
            } else {
                $where[] = "($startColumn <= ? AND COALESCE($endColumn, $startColumn) >= ?)";
                array_push($params, $rangeEnd, $rangeStart);
            }

            return ['start_date' => $startDate, 'end_date' => $endDate];
        }

        if ($startDate !== null) {
            $where[] = "DATE(COALESCE({$alias}end_time, {$alias}start_time)) >= ?";
            $params[] = $startDate;
            return ['start_date' => $startDate, 'end_date' => '9999-12-31'];
        }

        if ($endDate !== null) {
            $where[] = "DATE({$alias}start_time) <= ?";
            $params[] = $endDate;
            return ['start_date' => '1000-01-01', 'end_date' => $endDate];
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    public function expandEventsForDateRange(array $events, string $rangeStartDate, string $rangeEndDate): array
    {
        $rangeStart = new \DateTimeImmutable($rangeStartDate . ' 00:00:00');
        $rangeEnd = new \DateTimeImmutable($rangeEndDate . ' 23:59:59');
        $expanded = [];

        foreach ($events as $event) {
            if (!$this->isRecurringEvent($event)) {
                if ($this->eventOverlapsRange($event, $rangeStart, $rangeEnd)) {
                    $event['occurrence_id'] = $event['occurrence_id'] ?? $this->buildOccurrenceId($event, (string) ($event['start_time'] ?? ''));
                    $expanded[] = $event;
                }
                continue;
            }

            foreach ($this->expandRecurringEvent($event, $rangeStart, $rangeEnd) as $occurrence) {
                $expanded[] = $occurrence;
            }
        }

        usort($expanded, static function (array $a, array $b): int {
            $timeCompare = strtotime((string) ($a['start_time'] ?? '')) <=> strtotime((string) ($b['start_time'] ?? ''));
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            return strcmp((string) ($a['occurrence_id'] ?? $a['id'] ?? ''), (string) ($b['occurrence_id'] ?? $b['id'] ?? ''));
        });

        return $expanded;
    }

    private function isRecurringEvent(array $event): bool
    {
        return $this->supportsRecurrence()
            && !empty($event['recurrence_pattern'])
            && (string) $event['recurrence_pattern'] !== 'none';
    }

    private function eventOverlapsRange(array $event, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): bool
    {
        $start = $this->dateTimeFromEventValue((string) ($event['start_time'] ?? ''));
        if (!$start) {
            return false;
        }

        $end = !empty($event['end_time'])
            ? $this->dateTimeFromEventValue((string) $event['end_time'])
            : $start;
        if (!$end) {
            $end = $start;
        }

        return $start <= $rangeEnd && $end >= $rangeStart;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function expandRecurringEvent(array $event, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): array
    {
        $pattern = (string) ($event['recurrence_pattern'] ?? 'none');
        $start = $this->dateTimeFromEventValue((string) ($event['start_time'] ?? ''));
        if (!$start) {
            return [];
        }

        $end = !empty($event['end_time'])
            ? $this->dateTimeFromEventValue((string) $event['end_time'])
            : null;
        $durationSeconds = $end ? max(0, $end->getTimestamp() - $start->getTimestamp()) : null;
        $recurrenceEnd = !empty($event['recurrence_end_date'])
            ? new \DateTimeImmutable((string) $event['recurrence_end_date'] . ' 23:59:59')
            : null;
        $totalCount = !empty($event['recurrence_count']) ? max(1, (int) $event['recurrence_count']) : PHP_INT_MAX;
        $index = $this->fastForwardRecurrenceIndex($pattern, $start, $rangeStart);
        if ($index > 0) {
            $candidate = $this->advanceRecurringStart($start, $pattern, $index);
            $candidateEnd = $durationSeconds !== null ? $candidate->modify('+' . $durationSeconds . ' seconds') : $candidate;
            while ($index > 0 && $candidateEnd >= $rangeStart) {
                $index--;
                $candidate = $this->advanceRecurringStart($start, $pattern, $index);
                $candidateEnd = $durationSeconds !== null ? $candidate->modify('+' . $durationSeconds . ' seconds') : $candidate;
            }
            if ($candidateEnd < $rangeStart) {
                $index++;
            }
        }

        $occurrences = [];
        $checked = 0;
        while ($index < $totalCount && $checked < self::RECURRENCE_EXPANSION_LIMIT) {
            $occurrenceStart = $this->advanceRecurringStart($start, $pattern, $index);
            if ($occurrenceStart > $rangeEnd) {
                break;
            }
            if ($recurrenceEnd !== null && $occurrenceStart > $recurrenceEnd) {
                break;
            }

            $occurrenceEnd = $durationSeconds !== null ? $occurrenceStart->modify('+' . $durationSeconds . ' seconds') : null;
            $copy = $event;
            $copy['start_time'] = $occurrenceStart->format('Y-m-d H:i:s');
            $copy['end_time'] = $occurrenceEnd ? $occurrenceEnd->format('Y-m-d H:i:s') : null;
            $copy['occurrence_id'] = $this->buildOccurrenceId($event, $copy['start_time']);
            $copy['recurrence_index'] = $index;
            $copy['is_recurring_occurrence'] = true;

            if ($this->eventOverlapsRange($copy, $rangeStart, $rangeEnd)) {
                $occurrences[] = $copy;
            }

            $index++;
            $checked++;
        }

        return $occurrences;
    }

    private function fastForwardRecurrenceIndex(string $pattern, \DateTimeImmutable $start, \DateTimeImmutable $rangeStart): int
    {
        if ($rangeStart <= $start) {
            return 0;
        }

        $days = (int) $start->diff($rangeStart)->format('%a');
        $monthOffset = (((int) $rangeStart->format('Y') - (int) $start->format('Y')) * 12)
            + ((int) $rangeStart->format('n') - (int) $start->format('n'));
        $yearOffset = (int) $rangeStart->format('Y') - (int) $start->format('Y');

        return match ($pattern) {
            'daily' => max(0, $days - 1),
            'weekly' => max(0, intdiv($days, 7) - 1),
            'monthly' => max(0, $monthOffset - 1),
            'yearly' => max(0, $yearOffset - 1),
            default => 0,
        };
    }

    private function advanceRecurringStart(\DateTimeImmutable $start, string $pattern, int $index): \DateTimeImmutable
    {
        if ($index <= 0) {
            return $start;
        }

        return match ($pattern) {
            'daily' => $start->modify('+' . $index . ' days'),
            'weekly' => $start->modify('+' . $index . ' weeks'),
            'monthly' => $start->modify('+' . $index . ' months'),
            'yearly' => $start->modify('+' . $index . ' years'),
            default => $start,
        };
    }

    private function dateTimeFromEventValue(string $value): ?\DateTimeImmutable
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp($timestamp);
    }

    private function buildOccurrenceId(array $event, string $startTime): string
    {
        return (string) ($event['id'] ?? 'event') . ':' . date('Ymd\THis', strtotime($startTime) ?: time());
    }

    private function eventColumnExists(string $column): bool
    {
        $cacheKey = 'events.' . $column;
        if (array_key_exists($cacheKey, self::$columnSupportCache)) {
            return self::$columnSupportCache[$cacheKey];
        }

        try {
            $result = Database::queryOne(
                "SELECT COUNT(*) AS count
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'events'
                   AND COLUMN_NAME = ?",
                [$column]
            );
            self::$columnSupportCache[$cacheKey] = ((int) ($result['count'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            self::$columnSupportCache[$cacheKey] = false;
        }

        return self::$columnSupportCache[$cacheKey];
    }
}
