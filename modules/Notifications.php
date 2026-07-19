<?php
/**
 * Notifications Management Module
 * 
 * Handles user notifications and alerts
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\MobilePushNotificationService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\WorkspaceScopeService;

class Notifications
{
    /** @var string[] */
    private const CONTACT_SCOPED_TYPES = [
        'contact_created',
        'whatsapp_message_received',
    ];
    private WorkspaceScopeService $workspaceScope;
    private DemoSessionScopeService $demoScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
        $this->demoScope = new DemoSessionScopeService();
    }

    /**
     * Create a notification
     */
    public function create(int $userId, string $type, string $title, string $message, array $options = []): int
    {
        $entityType = !empty($options['entity_type']) ? Security::sanitizeInput($options['entity_type'], 'string') : null;
        $entityId = !empty($options['entity_id']) ? (int) $options['entity_id'] : null;
        $link = !empty($options['link']) ? Security::sanitizeInput($options['link'], 'string') : null;
        $severity = !empty($options['severity']) ? Security::sanitizeInput($options['severity'], 'string') : null;
        $aiInsight = !empty($options['ai_insight']) ? $this->sanitizePlainText((string) $options['ai_insight']) : null;
        $aiAction = !empty($options['ai_action']) ? $this->sanitizePlainText((string) $options['ai_action']) : null;
        
        $title = $this->sanitizePlainText($title);
        $message = $this->sanitizePlainText($message);
        $type = Security::sanitizeInput($type, 'string');
        $workspaceId = $this->workspaceId();
        
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, entity_type, entity_id, link, severity, ai_insight, ai_action) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$workspaceId, $userId, $type, $title, $message, $entityType, $entityId, $link, $severity, $aiInsight, $aiAction]
        );

        $notificationId = (int) Database::lastInsertId();
        $this->markDemoNotificationIfNeeded($notificationId, $workspaceId, $options);
        try {
            (new MobilePushNotificationService())->sendForNotification($notificationId);
        } catch (\Throwable $e) {
            error_log('Notifications::create mobile push send failed for notification_id=' . $notificationId . ': ' . $e->getMessage());
        }

        return $notificationId;
    }
    
    /**
     * Create notification for multiple users
     */
    public function createForUsers(array $userIds, string $type, string $title, string $message, array $options = []): void
    {
        $uniqueUserIds = array_values(array_unique(array_map('intval', $userIds)));
        foreach ($uniqueUserIds as $userId) {
            if ($userId > 0) {
                $this->create($userId, $type, $title, $message, $options);
            }
        }
    }
    
    /**
     * Create notification for all admin users
     */
    public function createForAdmins(string $type, string $title, string $message, array $options = []): void
    {
        $admins = Database::query("SELECT id FROM users WHERE role = 'admin'");
        $adminIds = array_column($admins, 'id');
        
        if (!empty($adminIds)) {
            $this->createForUsers($adminIds, $type, $title, $message, $options);
        }
    }
    
    /**
     * Create notification for all users
     */
    public function createForAllUsers(string $type, string $title, string $message, array $options = []): void
    {
        $userIds = $this->getAllUserIds();
        if (!empty($userIds)) {
            $this->createForUsers($userIds, $type, $title, $message, $options);
        }
    }

    /**
     * Create notification for the right contact audience.
     */
    public function createForContact(int $contactId, string $type, string $title, string $message, array $options = []): void
    {
        if ($contactId <= 0) {
            return;
        }

        $options['entity_type'] = 'contact';
        $options['entity_id'] = $contactId;

        $userIds = $this->resolveContactAudienceUserIds($contactId);
        if ($userIds !== []) {
            $this->createForUsers($userIds, $type, $title, $message, $options);
        }
    }

    /**
     * Determine which users should receive contact-linked notifications.
     *
     * @return int[]
     */
    public function resolveContactAudienceUserIds(int $contactId): array
    {
        if ($contactId <= 0) {
            return [];
        }

        $contact = Database::queryOne(
            "SELECT assigned_to
             FROM contacts
             WHERE workspace_id = ?
               AND id = ?
             LIMIT 1",
            [$this->workspaceId(), $contactId]
        ) ?: [];

        $assignedTo = (int) ($contact['assigned_to'] ?? 0);
        if ($assignedTo > 0) {
            return [$assignedTo];
        }

        return $this->getAllUserIds();
    }

    /**
     * Remove stale contact-scoped notifications after an unassigned contact is claimed.
     */
    public function retireContactScopeNotifications(int $contactId, array $types = self::CONTACT_SCOPED_TYPES): int
    {
        if ($contactId <= 0) {
            return 0;
        }

        $normalizedTypes = array_values(array_unique(array_filter(
            array_map(static fn($type): string => trim((string) $type), $types),
            static fn(string $type): bool => $type !== ''
        )));
        if ($normalizedTypes === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($normalizedTypes), '?'));
        $params = array_merge([$contactId], $normalizedTypes);

        return Database::execute(
            "DELETE FROM notifications
             WHERE workspace_id = ?
               AND entity_type = 'contact'
               AND entity_id = ?
               AND type IN ($placeholders)",
            array_merge([$this->workspaceId()], $params)
        );
    }
    
    /**
     * Get notifications for user
     */
    public function getUserNotifications(int $userId, int $limit = 20, int $offset = 0, bool $unreadOnly = false): array
    {
        return $this->getNotifications($limit, $offset, $unreadOnly, $userId);
    }

    /**
     * Get notifications for a user or across all users when no user is supplied.
     */
    public function getNotifications(int $limit = 20, int $offset = 0, bool $unreadOnly = false, ?int $userId = null): array
    {
        $where = ["workspace_id = ?"];
        $params = [$this->workspaceId()];
        [$demoSql, $demoParams] = $this->demoNotificationClause();
        if ($demoSql !== '') {
            $where[] = $demoSql;
            $params = array_merge($params, $demoParams);
        }

        if ($userId !== null) {
            $where[] = "user_id = ?";
            $params[] = $userId;
            [$contactSql, $contactParams] = $this->buildContactNotificationVisibilityClause($userId);
            if ($contactSql !== '') {
                $where[] = $contactSql;
                $params = array_merge($params, $contactParams);
            }
        }
        
        if ($unreadOnly) {
            $where[] = "is_read = 0";
        }
        
        $sql = "SELECT * FROM notifications";
        if ($where !== []) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY created_at DESC LIMIT " . max(1, (int) $limit) . " OFFSET " . max(0, (int) $offset);
        
        return array_map(
            fn(array $notification): array => $this->normalizeNotificationForDisplay($notification),
            Database::query($sql, $params)
        );
    }

    /**
     * Group notification rows into render-ready threads.
     *
     * @param array<int,array<string,mixed>> $notifications
     * @return array<int,array<string,mixed>>
     */
    public function groupNotifications(array $notifications): array
    {
        $groups = [];

        foreach ($notifications as $notification) {
            $type = trim((string) ($notification['type'] ?? ''));
            $entityType = trim((string) ($notification['entity_type'] ?? ''));
            $key = ($type !== '' ? $type : 'notification') . '|' . ($entityType !== '' ? $entityType : 'general');

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'type' => $type,
                    'entity_type' => $entityType,
                    'label' => $this->groupLabel($type, $entityType),
                    'icon' => $this->groupIcon($type, $entityType),
                    'color' => $this->getColor($type),
                    'total_count' => 0,
                    'unread_count' => 0,
                    'latest_at' => (string) ($notification['created_at'] ?? ''),
                    'latest_title' => (string) ($notification['title'] ?? 'Notification'),
                    'latest_message' => (string) ($notification['message'] ?? ''),
                    'notifications' => [],
                ];
            }

            $groups[$key]['total_count']++;
            if (empty($notification['is_read'])) {
                $groups[$key]['unread_count']++;
            }
            $groups[$key]['notifications'][] = $notification;

            $currentLatest = strtotime((string) ($groups[$key]['latest_at'] ?? '')) ?: 0;
            $candidateLatest = strtotime((string) ($notification['created_at'] ?? '')) ?: 0;
            if ($candidateLatest >= $currentLatest) {
                $groups[$key]['latest_at'] = (string) ($notification['created_at'] ?? '');
                $groups[$key]['latest_title'] = (string) ($notification['title'] ?? 'Notification');
                $groups[$key]['latest_message'] = (string) ($notification['message'] ?? '');
                $groups[$key]['icon'] = $this->groupIcon($type, $entityType);
                $groups[$key]['color'] = $this->getColor($type);
            }
        }

        $groups = array_values($groups);
        usort($groups, static function (array $a, array $b): int {
            return (strtotime((string) ($b['latest_at'] ?? '')) ?: 0) <=> (strtotime((string) ($a['latest_at'] ?? '')) ?: 0);
        });

        return $groups;
    }
    
    /**
     * Get unread count for user
     */
    public function getUnreadCount(?int $userId): int
    {
        if ($userId === null) {
            [$demoSql, $demoParams] = $this->demoNotificationClause();
            $sql = "SELECT COUNT(*) as count FROM notifications WHERE workspace_id = ? AND is_read = 0";
            $params = [$this->workspaceId()];
            if ($demoSql !== '') {
                $sql .= " AND " . $demoSql;
                $params = array_merge($params, $demoParams);
            }
            $result = Database::queryOne($sql, $params);
        } else {
            [$contactSql, $contactParams] = $this->buildContactNotificationVisibilityClause($userId);
            $sql = "SELECT COUNT(*) as count FROM notifications WHERE workspace_id = ? AND user_id = ? AND is_read = 0";
            $params = [$this->workspaceId(), $userId];
            [$demoSql, $demoParams] = $this->demoNotificationClause();
            if ($demoSql !== '') {
                $sql .= " AND " . $demoSql;
                $params = array_merge($params, $demoParams);
            }
            if ($contactSql !== '') {
                $sql .= " AND " . $contactSql;
                $params = array_merge($params, $contactParams);
            }
            $result = Database::queryOne(
                $sql,
                $params
            );
        }
        
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Return the two navigation badge counts with one scoped aggregate query.
     *
     * @return array{unread_count:int,new_count:int}
     */
    public function getBadgeCounts(int $userId, ?string $lastOpenedAt): array
    {
        if ($userId <= 0) {
            return ['unread_count' => 0, 'new_count' => 0];
        }

        $lastOpenedAt = trim((string) $lastOpenedAt);
        $newCountExpression = $lastOpenedAt === ''
            ? 'is_read = 0'
            : 'created_at > ?';
        $params = [];
        if ($lastOpenedAt !== '') {
            $params[] = $lastOpenedAt;
        }

        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS unread_count,
                    COALESCE(SUM(CASE WHEN {$newCountExpression} THEN 1 ELSE 0 END), 0) AS new_count
                FROM notifications
                WHERE workspace_id = ?
                  AND user_id = ?";
        $params[] = $this->workspaceId();
        $params[] = $userId;

        [$demoSql, $demoParams] = $this->demoNotificationClause();
        if ($demoSql !== '') {
            $sql .= ' AND ' . $demoSql;
            $params = array_merge($params, $demoParams);
        }

        [$contactSql, $contactParams] = $this->buildContactNotificationVisibilityClause($userId);
        if ($contactSql !== '') {
            $sql .= ' AND ' . $contactSql;
            $params = array_merge($params, $contactParams);
        }

        $result = Database::queryOne($sql, $params) ?: [];
        return [
            'unread_count' => (int) ($result['unread_count'] ?? 0),
            'new_count' => (int) ($result['new_count'] ?? 0),
        ];
    }

    /**
     * Count notifications created after the user last opened the notifications page.
     */
    public function getNewSinceCount(int $userId, ?string $lastOpenedAt): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $lastOpenedAt = trim((string) $lastOpenedAt);
        if ($lastOpenedAt === '') {
            return $this->getUnreadCount($userId);
        }

        [$contactSql, $contactParams] = $this->buildContactNotificationVisibilityClause($userId);
        $sql = "SELECT COUNT(*) as count
                FROM notifications
                WHERE workspace_id = ?
                  AND user_id = ?
                  AND created_at > ?";
        $params = [$this->workspaceId(), $userId, $lastOpenedAt];

        [$demoSql, $demoParams] = $this->demoNotificationClause();
        if ($demoSql !== '') {
            $sql .= " AND " . $demoSql;
            $params = array_merge($params, $demoParams);
        }

        if ($contactSql !== '') {
            $sql .= " AND " . $contactSql;
            $params = array_merge($params, $contactParams);
        }

        $result = Database::queryOne($sql, $params);

        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        // Verify notification belongs to user
        [$demoSql, $demoParams] = $this->demoNotificationClause();
        $sql = "SELECT id FROM notifications WHERE workspace_id = ? AND id = ? AND user_id = ?";
        $params = [$this->workspaceId(), $notificationId, $userId];
        if ($demoSql !== '') {
            $sql .= " AND " . $demoSql;
            $params = array_merge($params, $demoParams);
        }
        $notification = Database::queryOne(
            $sql,
            $params
        );
        
        if (!$notification) {
            return false;
        }
        
        $updateSql = "UPDATE notifications SET is_read = 1, read_at = CURRENT_TIMESTAMP WHERE workspace_id = ? AND id = ?";
        $updateParams = [$this->workspaceId(), $notificationId];
        if ($demoSql !== '') {
            $updateSql .= " AND " . $demoSql;
            $updateParams = array_merge($updateParams, $demoParams);
        }
        Database::execute($updateSql, $updateParams);
        
        return true;
    }
    
    /**
     * Mark all notifications as read for user
     */
    public function markAllAsRead(int $userId): bool
    {
        [$demoSql, $demoParams] = $this->demoNotificationClause();
        $sql = "UPDATE notifications SET is_read = 1, read_at = CURRENT_TIMESTAMP WHERE workspace_id = ? AND user_id = ? AND is_read = 0";
        $params = [$this->workspaceId(), $userId];
        if ($demoSql !== '') {
            $sql .= " AND " . $demoSql;
            $params = array_merge($params, $demoParams);
        }
        Database::execute($sql, $params);
        
        return true;
    }

    /**
     * Mark one grouped notification thread as read for the active workspace/user.
     */
    public function markGroupAsRead(int $userId, string $type, ?string $entityType): int
    {
        $type = Security::sanitizeInput($type, 'string');
        $entityType = trim((string) $entityType);
        $entityType = $entityType !== '' ? Security::sanitizeInput($entityType, 'string') : null;

        if ($userId <= 0 || $type === '') {
            return 0;
        }

        $sql = "UPDATE notifications
                SET is_read = 1, read_at = CURRENT_TIMESTAMP
                WHERE workspace_id = ?
                  AND user_id = ?
                  AND type = ?
                  AND is_read = 0";
        $params = [$this->workspaceId(), $userId, $type];

        if ($entityType === null) {
            $sql .= " AND (entity_type IS NULL OR entity_type = '')";
        } else {
            $sql .= " AND entity_type = ?";
            $params[] = $entityType;
        }

        [$demoSql, $demoParams] = $this->demoNotificationClause();
        if ($demoSql !== '') {
            $sql .= " AND " . $demoSql;
            $params = array_merge($params, $demoParams);
        }

        [$contactSql, $contactParams] = $this->buildContactNotificationVisibilityClause($userId);
        if ($contactSql !== '') {
            $sql .= " AND " . $contactSql;
            $params = array_merge($params, $contactParams);
        }

        return Database::execute($sql, $params);
    }
    
    /**
     * Delete notification
     */
    public function delete(int $notificationId, int $userId): bool
    {
        // Verify notification belongs to user
        [$demoSql, $demoParams] = $this->demoNotificationClause();
        $sql = "SELECT id FROM notifications WHERE workspace_id = ? AND id = ? AND user_id = ?";
        $params = [$this->workspaceId(), $notificationId, $userId];
        if ($demoSql !== '') {
            $sql .= " AND " . $demoSql;
            $params = array_merge($params, $demoParams);
        }
        $notification = Database::queryOne(
            $sql,
            $params
        );
        
        if (!$notification) {
            return false;
        }
        
        $deleteSql = "DELETE FROM notifications WHERE workspace_id = ? AND id = ? AND user_id = ?";
        $deleteParams = [$this->workspaceId(), $notificationId, $userId];
        if ($demoSql !== '') {
            $deleteSql .= " AND " . $demoSql;
            $deleteParams = array_merge($deleteParams, $demoParams);
        }
        $deleted = Database::execute($deleteSql . " LIMIT 1", $deleteParams);

        return $deleted > 0;
    }
    
    /**
     * Delete all read notifications for user
     */
    public function deleteAllRead(int $userId): bool
    {
        [$demoSql, $demoParams] = $this->demoNotificationClause();
        $sql = "DELETE FROM notifications WHERE workspace_id = ? AND user_id = ? AND is_read = 1";
        $params = [$this->workspaceId(), $userId];
        if ($demoSql !== '') {
            $sql .= " AND " . $demoSql;
            $params = array_merge($params, $demoParams);
        }
        Database::execute($sql, $params);
        
        return true;
    }
    
    /**
     * Get notification by ID
     */
    public function getById(int $id): ?array
    {
        [$demoSql, $demoParams] = $this->demoNotificationClause();
        $sql = "SELECT * FROM notifications WHERE workspace_id = ? AND id = ?";
        $params = [$this->workspaceId(), $id];
        if ($demoSql !== '') {
            $sql .= " AND " . $demoSql;
            $params = array_merge($params, $demoParams);
        }
        return Database::queryOne($sql, $params);
    }

    /**
     * @return int[]
     */
    private function getAllUserIds(): array
    {
        if ($this->demoScope->isActiveDemoWorkspace()) {
            $session = $this->demoScope->activeSession();
            $userId = (int) ($session['user_id'] ?? $session['guest_user_id'] ?? 0);
            return $userId > 0 ? [$userId] : [];
        }

        $users = Database::query(
            "SELECT DISTINCT wm.user_id AS id
             FROM workspace_memberships wm
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'",
            [$this->workspaceId()]
        );
        return array_values(array_unique(array_map('intval', array_column($users, 'id'))));
    }

    /**
     * Hide stale contact-scoped notifications when a contact is now assigned to someone else.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function buildContactNotificationVisibilityClause(int $userId): array
    {
        $types = self::CONTACT_SCOPED_TYPES;
        if ($userId <= 0 || $types === []) {
            return ['', []];
        }

        $placeholders = implode(', ', array_fill(0, count($types), '?'));
        $sql = "NOT (
            entity_type = 'contact'
            AND type IN ($placeholders)
            AND EXISTS (
                SELECT 1
                FROM contacts c
                WHERE c.id = notifications.entity_id
                  AND c.workspace_id = notifications.workspace_id
                  AND c.assigned_to IS NOT NULL
                  AND c.assigned_to > 0
                  AND c.assigned_to <> ?
            )
        )";

        return [$sql, array_merge($types, [$userId])];
    }
    
    /**
     * Get notification icon based on type
     */
    public function getIcon(string $type): string
    {
        $icons = [
            'deal' => '💰',
            'task' => '✅',
            'event' => '📅',
            'contact' => '👤',
            'email' => '📧',
            'activity' => '📝',
            'system' => '🔔',
            'reminder' => '⏰',
            'assignment' => '👥',
            'task_assigned' => '✅',
            'mention' => '@',
            'warning' => '⚠️',
            'success' => '✓',
            'info' => 'ℹ️',
            'whatsapp_message_received' => '📱',
            'system_alert' => '🚨',
            'ai_coach_nudge' => '🧭'
        ];
        
        return $icons[$type] ?? '🔔';
    }
    
    /**
     * Get notification color based on type
     */
    public function getColor(string $type): string
    {
        $colors = [
            'deal' => '#3c3',
            'task' => '#36c',
            'event' => '#f90',
            'contact' => '#9c3',
            'email' => '#c33',
            'activity' => '#999',
            'system' => '#666',
            'reminder' => '#f90',
            'assignment' => '#36c',
            'task_assigned' => '#2563eb',
            'mention' => '#9c3',
            'warning' => '#f90',
            'success' => '#3c3',
            'info' => '#36c',
            'contact_created' => '#16a34a',
            'workspace_created' => '#2563eb',
            'whatsapp_message_received' => '#25D366',
            'system_alert' => '#d33',
            'ai_coach_nudge' => '#5b8def'
        ];
        
        return $colors[$type] ?? '#666';
    }

    private function groupLabel(string $type, string $entityType): string
    {
        if ($type === 'contact_created' && $entityType === 'contact') {
            return 'New contacts';
        }

        if ($type === 'workspace_created' && $entityType === 'workspace') {
            return 'New workspaces';
        }

        if ($type === 'task_assigned' && $entityType === 'task') {
            return 'Task assignments';
        }

        $source = $entityType !== '' ? $entityType : $type;
        $label = trim(str_replace('_', ' ', $source));
        if ($label === '') {
            return 'Notifications';
        }

        return ucwords($label) . ' notifications';
    }

    private function groupIcon(string $type, string $entityType): string
    {
        if ($type === 'contact_created' && $entityType === 'contact') {
            return '&#128100;';
        }

        if ($type === 'workspace_created' && $entityType === 'workspace') {
            return '&#127970;';
        }

        return $this->getIcon($type);
    }

    /**
     * Give older task assignment notifications a specific title without rewriting history.
     *
     * @param array<string,mixed> $notification
     * @return array<string,mixed>
     */
    private function normalizeNotificationForDisplay(array $notification): array
    {
        $notification = $this->decodeNotificationTextFields($notification);
        $type = (string) ($notification['type'] ?? '');
        $title = trim((string) ($notification['title'] ?? ''));
        if ($type !== 'task_assigned' || !in_array($title, ['New Task Assigned', 'Task Reassigned'], true)) {
            return $notification;
        }

        $message = trim((string) ($notification['message'] ?? ''));
        $taskTitle = $this->extractLegacyTaskAssignmentTitle($message);
        if ($taskTitle === '') {
            return $notification;
        }

        $isReassignment = $title === 'Task Reassigned';
        $notification['title'] = $isReassignment ? 'Reassigned: ' . $taskTitle : $taskTitle;
        $notification['message'] = $isReassignment
            ? 'This task has been reassigned to you.'
            : 'You have been assigned this task.';

        return $notification;
    }

    private function sanitizePlainText(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(strip_tags($decoded));
    }

    /**
     * @param array<string,mixed> $notification
     * @return array<string,mixed>
     */
    private function decodeNotificationTextFields(array $notification): array
    {
        foreach (['title', 'message', 'ai_insight', 'ai_action'] as $field) {
            if (isset($notification[$field]) && is_scalar($notification[$field])) {
                $notification[$field] = html_entity_decode((string) $notification[$field], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $notification;
    }

    private function extractLegacyTaskAssignmentTitle(string $message): string
    {
        foreach ([
            'You have been assigned a new task: ',
            'A task has been reassigned to you: ',
        ] as $prefix) {
            if (str_starts_with($message, $prefix)) {
                return trim(substr($message, strlen($prefix)));
            }
        }

        return '';
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function demoNotificationClause(): array
    {
        if (!Database::columnExists('notifications', 'demo_visibility')) {
            return ['', []];
        }

        $clause = $this->demoScope->entityOwnershipClause('notifications');
        return [$clause['sql'], $clause['params']];
    }

    private function markDemoNotificationIfNeeded(int $notificationId, int $workspaceId, array $options = []): void
    {
        if ($notificationId <= 0 || !Database::columnExists('notifications', 'demo_visibility')) {
            return;
        }

        $sessionId = (int) ($options['demo_session_id'] ?? 0);
        if ($sessionId <= 0) {
            $session = $this->demoScope->activeSession($workspaceId);
            $sessionId = (int) ($session['id'] ?? 0);
        }
        if ($sessionId <= 0) {
            return;
        }

        Database::execute(
            "UPDATE notifications
             SET demo_visibility = 'session_private', demo_session_id = ?
             WHERE workspace_id = ? AND id = ?",
            [$sessionId, $workspaceId, $notificationId]
        );
        $this->demoScope->registerEntity($sessionId, $workspaceId, 'notifications', $notificationId);
    }
}
