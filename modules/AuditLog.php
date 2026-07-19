<?php
/**
 * Audit Log Module
 * 
 * Handles audit logging and retrieval of user actions
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Auth;
use CRM\Security;

class AuditLog
{
    /**
     * Log an action
     */
    public function log(string $action, ?string $entityType = null, ?int $entityId = null, ?array $oldValues = null, ?array $newValues = null): int
    {
        $userId = Auth::userId();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        Database::execute(
            "INSERT INTO audit_log (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $userId,
                Security::sanitizeInput($action, 'string'),
                $entityType ? Security::sanitizeInput($entityType, 'string') : null,
                $entityId,
                $oldValues ? json_encode($oldValues) : null,
                $newValues ? json_encode($newValues) : null,
                $ipAddress,
                $userAgent
            ]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get audit logs with filters
     */
    public function getLogs(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $conditions = [];
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $conditions[] = "al.user_id = ?";
            $params[] = (int) $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $conditions[] = "al.action = ?";
            $params[] = Security::sanitizeInput($filters['action'], 'string');
        }
        
        if (!empty($filters['entity_type'])) {
            $conditions[] = "al.entity_type = ?";
            $params[] = Security::sanitizeInput($filters['entity_type'], 'string');
        }
        
        if (!empty($filters['entity_id'])) {
            $conditions[] = "al.entity_id = ?";
            $params[] = (int) $filters['entity_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $conditions[] = "al.created_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $conditions[] = "al.created_at <= ?";
            $params[] = $filters['date_to'];
        }
        
        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return Database::query(
            "SELECT al.*, u.email as user_email, u.role as user_role
             FROM audit_log al
             LEFT JOIN users u ON al.user_id = u.id
             $whereClause
             ORDER BY al.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }
    
    /**
     * Get total count of audit logs with filters
     */
    public function getCount(array $filters = []): int
    {
        $conditions = [];
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $conditions[] = "user_id = ?";
            $params[] = (int) $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $conditions[] = "action = ?";
            $params[] = Security::sanitizeInput($filters['action'], 'string');
        }
        
        if (!empty($filters['entity_type'])) {
            $conditions[] = "entity_type = ?";
            $params[] = Security::sanitizeInput($filters['entity_type'], 'string');
        }
        
        if (!empty($filters['entity_id'])) {
            $conditions[] = "entity_id = ?";
            $params[] = (int) $filters['entity_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $conditions[] = "created_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $conditions[] = "created_at <= ?";
            $params[] = $filters['date_to'];
        }
        
        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
        
        $result = Database::queryOne(
            "SELECT COUNT(*) as total FROM audit_log $whereClause",
            $params
        );
        
        return (int) ($result['total'] ?? 0);
    }
    
    /**
     * Get audit logs for a specific entity
     */
    public function getByEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        return $this->getLogs(
            ['entity_type' => $entityType, 'entity_id' => $entityId],
            $limit,
            0
        );
    }
    
    /**
     * Get audit logs for a specific user
     */
    public function getByUser(int $userId, int $limit = 50): array
    {
        return $this->getLogs(
            ['user_id' => $userId],
            $limit,
            0
        );
    }
    
    /**
     * Get action statistics
     */
    public function getActionStats(int $days = 30): array
    {
        return Database::query(
            "SELECT action, COUNT(*) as count
             FROM audit_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY action
             ORDER BY count DESC",
            [$days]
        );
    }
    
    /**
     * Get entity type statistics
     */
    public function getEntityTypeStats(int $days = 30): array
    {
        return Database::query(
            "SELECT entity_type, COUNT(*) as count
             FROM audit_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND entity_type IS NOT NULL
             GROUP BY entity_type
             ORDER BY count DESC",
            [$days]
        );
    }
    
    /**
     * Log a page view (for user activity metrics)
     */
    public function logPageView(string $page): int
    {
        return $this->log('page_view', $page, null, null, null);
    }
    
    /**
     * Get count of active users (login or page_view) in last N hours
     */
    public function getActiveUsersCount(int $hours = 24): int
    {
        $result = Database::queryOne(
            "SELECT COUNT(DISTINCT user_id) as count FROM audit_log 
             WHERE user_id IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             AND action IN ('login', 'page_view')",
            [$hours]
        );
        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Get top pages by view count in last N hours
     */
    public function getTopPages(int $hours = 24, int $limit = 10): array
    {
        return Database::query(
            "SELECT entity_type as page, COUNT(*) as views
             FROM audit_log
             WHERE action = 'page_view' AND entity_type IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY entity_type
             ORDER BY views DESC
             LIMIT ?",
            [$hours, $limit]
        );
    }
    
    /**
     * Format action label
     */
    public function formatAction(string $action): string
    {
        $labels = [
            'create' => 'Created',
            'update' => 'Updated',
            'delete' => 'Deleted',
            'view' => 'Viewed',
            'export' => 'Exported',
            'import' => 'Imported',
            'login' => 'Logged In',
            'logout' => 'Logged Out',
            'password_change' => 'Changed Password',
            'permission_change' => 'Changed Permissions',
            'assign' => 'Assigned',
            'unassign' => 'Unassigned',
            'status_change' => 'Changed Status',
            'stage_change' => 'Changed Stage',
            'page_view' => 'Page View',
            'api_call' => 'API Call'
        ];
        
        return $labels[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }
    
    /**
     * Format entity type label
     */
    public function formatEntityType(?string $entityType): string
    {
        if (!$entityType) {
            return 'System';
        }
        
        $labels = [
            'contact' => 'Contact',
            'deal' => 'Deal',
            'task' => 'Task',
            'event' => 'Event',
            'email' => 'Email',
            'activity' => 'Activity',
            'note' => 'Note',
            'document' => 'Document',
            'user' => 'User',
            'workflow' => 'Workflow',
            'report' => 'Report',
            'tag' => 'Tag'
        ];
        
        return $labels[$entityType] ?? ucfirst($entityType);
    }
    
    /**
     * Get entity URL
     */
    public function getEntityUrl(?string $entityType, ?int $entityId): ?string
    {
        if (!$entityType || !$entityId) {
            return null;
        }
        
        $urls = [
            'contact' => publicUrl("contact_view.php?id={$entityId}"),
            'deal' => publicUrl("deal_view.php?id={$entityId}"),
            'task' => publicUrl("task_view.php?id={$entityId}"),
            'event' => publicUrl("event_view.php?id={$entityId}"),
            'email' => publicUrl("email_view.php?id={$entityId}"),
            'activity' => publicUrl("activity_view.php?id={$entityId}"),
            'note' => "#",
            'document' => publicUrl("document_view.php?id={$entityId}"),
            'user' => publicUrl("user_view.php?id={$entityId}"),
            'workflow' => publicUrl("workflow_view.php?id={$entityId}"),
            'report' => publicUrl("report_view.php?id={$entityId}"),
            'tag' => publicUrl("tag_view.php?id={$entityId}")
        ];
        
        return $urls[$entityType] ?? null;
    }
}
