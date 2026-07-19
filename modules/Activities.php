<?php
/**
 * Activities Module
 * 
 * Handles activity logging and retrieval
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\WorkspaceScopeService;
use InvalidArgumentException;

class Activities
{
    private const ACTIVITY_TYPE_PATTERN = '/\A[a-z0-9_]{1,64}\z/';

    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
    }

    /**
     * Log an activity
     */
    public function log(int $contactId, string $activityType, ?string $description = null, array|int|null $metadata = [], ?int $userId = null): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $this->workspaceScope->assertSameWorkspace('contacts', $contactId, $workspaceId);
        $activityType = self::normalizeActivityType($activityType);
        if (is_int($metadata) && $userId === null) {
            $userId = $metadata;
            $metadata = [];
        }
        $metadata = is_array($metadata) ? $metadata : [];
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);
        $description = $description ? Security::sanitizeInput($description, 'string') : null;
        $metadataJson = !empty($metadata) ? json_encode($metadata) : null;
        
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, user_id, activity_type, description, metadata) 
             VALUES (?, ?, ?, ?, ?, ?)",
            [$workspaceId, $contactId, $userId, $activityType, $description, $metadataJson]
        );

        $activityId = (int) Database::lastInsertId();
        \CRM\EventBus::publish('activity.created', [
            'activity_id' => $activityId,
            'contact_id' => $contactId,
            'activity_type' => $activityType,
            'description' => $description
        ]);
        return $activityId;
    }

    /**
     * Activity types a user can manually create from public forms.
     *
     * @return array<string,string>
     */
    public static function manualTypeOptions(): array
    {
        return [
            'note' => 'Note',
            'call' => 'Call',
            'email' => 'Email',
            'meeting' => 'Meeting',
            'status_change' => 'Status Change',
            'form_submit' => 'Form Submit',
        ];
    }

    public static function isManualType(string $activityType): bool
    {
        try {
            $activityType = self::normalizeActivityType($activityType);
        } catch (InvalidArgumentException $e) {
            return false;
        }

        return array_key_exists($activityType, self::manualTypeOptions());
    }

    public static function isValidActivityType(string $activityType): bool
    {
        $activityType = trim(strtolower($activityType));
        return preg_match(self::ACTIVITY_TYPE_PATTERN, $activityType) === 1;
    }

    public static function normalizeActivityType(string $activityType): string
    {
        $activityType = trim(strtolower($activityType));
        if (!self::isValidActivityType($activityType)) {
            throw new InvalidArgumentException('Invalid activity type.');
        }

        return $activityType;
    }

    public static function formatTypeLabel(?string $activityType): string
    {
        $activityType = trim((string) $activityType);
        if ($activityType === '') {
            return 'Activity';
        }

        $manualTypes = self::manualTypeOptions();
        if (isset($manualTypes[$activityType])) {
            return $manualTypes[$activityType];
        }

        return ucwords(str_replace('_', ' ', $activityType));
    }
    
    /**
     * Get activities for a contact
     */
    public function getByContact(int $contactId, int $limit = 50, int $offset = 0): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $limit = max(0, (int) $limit);
        $offset = max(0, (int) $offset);

        return Database::query(
            "SELECT a.*, c.first_name, c.last_name, c.email as contact_email, u.email as user_email
             FROM activities a
             JOIN contacts c ON a.contact_id = c.id AND c.workspace_id = a.workspace_id
             LEFT JOIN users u ON a.user_id = u.id
             WHERE a.workspace_id = ?
               AND a.contact_id = ?
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            [$workspaceId, $contactId]
        );
    }
    
    /**
     * Get activities by type
     */
    public function getByType(string $activityType, int $limit = 50, int $offset = 0, ?int $viewerUserId = null, bool $canViewAll = true): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $activityType = self::normalizeActivityType($activityType);
        $limit = max(0, (int) $limit);
        $offset = max(0, (int) $offset);

        $visibilitySql = '';
        $params = [$workspaceId, $activityType];
        if (!$canViewAll) {
            $visibilitySql = ' AND (c.assigned_to = ? OR c.assigned_to IS NULL OR c.assigned_to = 0)';
            $params[] = (int) $viewerUserId;
        }

        return Database::query(
            "SELECT a.*, c.first_name, c.last_name, c.email as contact_email
             FROM activities a
             JOIN contacts c ON a.contact_id = c.id AND c.workspace_id = a.workspace_id
             WHERE a.workspace_id = ?
               AND a.activity_type = ?{$visibilitySql}
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }
    
    /**
     * Get recent activities
     */
    public function getRecent(int $limit = 20, ?int $userId = null, int $offset = 0, ?int $viewerUserId = null, bool $canViewAll = true): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $limit = max(0, (int) $limit);
        $offset = max(0, (int) $offset);
        $sql = "SELECT a.*, c.first_name, c.last_name, c.email as contact_email, u.email as user_email
                FROM activities a
                JOIN contacts c ON a.contact_id = c.id AND c.workspace_id = a.workspace_id
                LEFT JOIN users u ON a.user_id = u.id";
        
        $params = [$workspaceId];
        $sql .= " WHERE a.workspace_id = ?";
        
        if ($userId) {
            $sql .= " AND a.user_id = ?";
            $params[] = $userId;
        }

        if (!$canViewAll) {
            $sql .= " AND (c.assigned_to = ? OR c.assigned_to IS NULL OR c.assigned_to = 0)";
            $params[] = (int) $viewerUserId;
        }
        
        $sql .= " ORDER BY a.created_at DESC, a.id DESC LIMIT {$limit} OFFSET {$offset}";
        
        return Database::query($sql, $params);
    }
    
    /**
     * Get activity by ID
     */
    public function getById(int $id, ?int $viewerUserId = null, bool $canViewAll = true): ?array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $visibilitySql = '';
        $params = [$workspaceId, $id];
        if (!$canViewAll) {
            $visibilitySql = ' AND (c.assigned_to = ? OR c.assigned_to IS NULL OR c.assigned_to = 0)';
            $params[] = (int) $viewerUserId;
        }

        return Database::queryOne(
            "SELECT a.*, c.first_name, c.last_name, c.email as contact_email, u.email as user_email
             FROM activities a
             JOIN contacts c ON a.contact_id = c.id AND c.workspace_id = a.workspace_id
             LEFT JOIN users u ON a.user_id = u.id
             WHERE a.workspace_id = ?
               AND a.id = ?{$visibilitySql}",
            $params
        );
    }
    
    /**
     * Count activities for a contact
     */
    public function countByContact(int $contactId): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $result = Database::queryOne(
            "SELECT COUNT(*) as count
             FROM activities a
             JOIN contacts c ON a.contact_id = c.id AND c.workspace_id = a.workspace_id
             WHERE a.workspace_id = ?
               AND a.contact_id = ?",
            [$workspaceId, $contactId]
        );
        
        return (int) ($result['count'] ?? 0);
    }

    public function countByType(string $activityType): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $activityType = self::normalizeActivityType($activityType);
        $result = Database::queryOne(
            "SELECT COUNT(*) as count
             FROM activities a
             JOIN contacts c ON a.contact_id = c.id AND c.workspace_id = a.workspace_id
             WHERE a.workspace_id = ?
               AND a.activity_type = ?",
            [$workspaceId, $activityType]
        );

        return (int) ($result['count'] ?? 0);
    }

    public function countAll(): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $result = Database::queryOne(
            "SELECT COUNT(*) as count
             FROM activities a
             JOIN contacts c ON a.contact_id = c.id AND c.workspace_id = a.workspace_id
             WHERE a.workspace_id = ?",
            [$workspaceId]
        );

        return (int) ($result['count'] ?? 0);
    }

    /**
     * @return array<int,array{type:string,label:string,count:int,is_manual:bool}>
     */
    public function getTypeCounts(?int $viewerUserId = null, bool $canViewAll = true): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $visibilitySql = '';
        $params = [$workspaceId];
        if (!$canViewAll) {
            $visibilitySql = ' AND (c.assigned_to = ? OR c.assigned_to IS NULL OR c.assigned_to = 0)';
            $params[] = (int) $viewerUserId;
        }
        $rows = Database::query(
            "SELECT a.activity_type, COUNT(*) as count
             FROM activities a
             JOIN contacts c ON a.contact_id = c.id AND c.workspace_id = a.workspace_id
             WHERE a.workspace_id = ?{$visibilitySql}
             GROUP BY a.activity_type",
            $params
        );

        $counts = [];
        foreach ($rows as $row) {
            $type = (string) ($row['activity_type'] ?? '');
            if ($type === '') {
                continue;
            }
            $counts[$type] = (int) ($row['count'] ?? 0);
        }

        $manualTypes = self::manualTypeOptions();
        $allTypes = array_values(array_unique(array_merge(array_keys($manualTypes), array_keys($counts))));

        usort($allTypes, static function (string $a, string $b) use ($manualTypes): int {
            $aManual = array_key_exists($a, $manualTypes);
            $bManual = array_key_exists($b, $manualTypes);
            if ($aManual && $bManual) {
                return array_search($a, array_keys($manualTypes), true) <=> array_search($b, array_keys($manualTypes), true);
            }
            if ($aManual !== $bManual) {
                return $aManual ? -1 : 1;
            }
            return strcasecmp(self::formatTypeLabel($a), self::formatTypeLabel($b));
        });

        return array_map(static fn(string $type): array => [
            'type' => $type,
            'label' => self::formatTypeLabel($type),
            'count' => $counts[$type] ?? 0,
            'is_manual' => array_key_exists($type, $manualTypes),
        ], $allTypes);
    }
}
