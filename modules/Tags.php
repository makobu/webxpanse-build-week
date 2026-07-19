<?php
/**
 * Tags Management Module
 * 
 * Handles tag creation and assignment to entities
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\WorkspaceScopeService;

class Tags
{
    private const DEFAULT_COLOR = '#33cc33';
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
    }

    /**
     * Create a new tag
     */
    public function create(array $data): int
    {
        // Validate required fields
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException("Tag name is required");
        }

        // Sanitize inputs
        $name = Security::sanitizeInput($name, 'string');
        if (mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Tag name must be 100 characters or fewer');
        }
        $color = $this->normalizeColor((string) ($data['color'] ?? self::DEFAULT_COLOR));
        $description = Security::sanitizeInput($data['description'] ?? '', 'string');
        $createdBy = (int) ($data['created_by'] ?? $_SESSION['user_id'] ?? 0);

        try {
            Database::execute(
                "INSERT INTO tags (workspace_id, name, color, description, created_by)
                 VALUES (?, ?, ?, ?, ?)",
                [$this->workspaceId(), $name, $color, $description, $createdBy]
            );
            
            return (int) Database::lastInsertId();
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                throw new \InvalidArgumentException("A tag with this name already exists");
            }
            throw $e;
        }
    }
    
    /**
     * Get tag by ID
     */
    public function getById(int $id): ?array
    {
        $workspace = $this->workspaceClause('t.');
        return $this->normalizeTag(Database::queryOne(
            "SELECT t.*, u.email as created_by_email,
                    (SELECT COUNT(*) FROM tag_assignments WHERE workspace_id = t.workspace_id AND tag_id = t.id) as usage_count
             FROM tags t
             LEFT JOIN users u ON t.created_by = u.id
             WHERE {$workspace['sql']} AND t.id = ?",
            array_merge($workspace['params'], [$id])
        ));
    }
    
    /**
     * Get tag by name
     */
    public function getByName(string $name): ?array
    {
        $workspace = $this->workspaceClause();
        return $this->normalizeTag(Database::queryOne(
            "SELECT * FROM tags WHERE {$workspace['sql']} AND name = ?",
            array_merge($workspace['params'], [$name])
        ));
    }
    
    /**
     * Update tag
     */
    public function update(int $id, array $data): bool
    {
        $tag = $this->getById($id);
        if (!$tag) {
            throw new \Exception("Tag not found");
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new \InvalidArgumentException("Tag name is required");
            }
            $name = Security::sanitizeInput($name, 'string');
            if (mb_strlen($name) > 100) {
                throw new \InvalidArgumentException('Tag name must be 100 characters or fewer');
            }
            $updates[] = "name = ?";
            $params[] = $name;
        }
        
        if (isset($data['color'])) {
            $updates[] = "color = ?";
            $params[] = $this->normalizeColor((string) $data['color'], (string) ($tag['color'] ?? self::DEFAULT_COLOR));
        }
        
        if (isset($data['description'])) {
            $updates[] = "description = ?";
            $params[] = Security::sanitizeInput($data['description'], 'string');
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $this->workspaceId();
        $params[] = $id;
        
        try {
            Database::execute(
                "UPDATE tags SET " . implode(', ', $updates) . " WHERE workspace_id = ? AND id = ?",
                $params
            );
            
            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                throw new \InvalidArgumentException("A tag with this name already exists");
            }
            throw $e;
        }
    }
    
    /**
     * Delete tag
     */
    public function delete(int $id): bool
    {
        $tag = $this->getById($id);
        if (!$tag) {
            return false;
        }

        Database::beginTransaction();
        try {
            Database::execute("DELETE FROM tag_assignments WHERE workspace_id = ? AND tag_id = ?", [$this->workspaceId(), $id]);
            Database::execute("DELETE FROM tags WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $id]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        
        return true;
    }
    
    /**
     * Get all tags
     */
    public function getAll(): array
    {
        $workspace = $this->workspaceClause('t.');
        return array_map(
            fn(array $tag): array => $this->normalizeTag($tag) ?? $tag,
            Database::query(
            "SELECT t.*, 
                    u.email as created_by_email,
                    (SELECT COUNT(*) FROM tag_assignments WHERE workspace_id = t.workspace_id AND tag_id = t.id) as usage_count
             FROM tags t
             LEFT JOIN users u ON t.created_by = u.id
             WHERE {$workspace['sql']}
             ORDER BY t.name ASC",
                $workspace['params']
            )
        );
    }

    public function getUsageCount(int $id): int
    {
        if (!$this->getById($id)) {
            return 0;
        }

        $result = Database::queryOne(
            "SELECT COUNT(*) AS count FROM tag_assignments WHERE workspace_id = ? AND tag_id = ?",
            [$this->workspaceId(), $id]
        );

        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Assign tag to entity
     */
    public function assign(int $tagId, string $entityType, int $entityId): bool
    {
        // Validate entity type
        $allowedTypes = ['contact', 'task', 'event', 'email', 'activity'];
        if (!in_array($entityType, $allowedTypes)) {
            throw new \Exception("Invalid entity type");
        }

        if (!$this->getById($tagId)) {
            throw new \Exception("Tag not found");
        }

        if (!$this->entityBelongsToWorkspace($entityType, $entityId)) {
            throw new \Exception("Entity not found");
        }
        
        try {
            Database::execute(
                "INSERT INTO tag_assignments (workspace_id, tag_id, entity_type, entity_id)
                 VALUES (?, ?, ?, ?)",
                [$this->workspaceId(), $tagId, $entityType, $entityId]
            );
            
            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                // Already assigned
                return false;
            }
            throw $e;
        }
    }
    
    /**
     * Remove tag assignment
     */
    public function unassign(int $tagId, string $entityType, int $entityId): bool
    {
        Database::execute(
            "DELETE FROM tag_assignments 
             WHERE workspace_id = ? AND tag_id = ? AND entity_type = ? AND entity_id = ?",
            [$this->workspaceId(), $tagId, $entityType, $entityId]
        );
        
        return true;
    }
    
    /**
     * Get tags for entity
     */
    public function getEntityTags(string $entityType, int $entityId): array
    {
        return Database::query(
            "SELECT t.* 
             FROM tags t
             INNER JOIN tag_assignments ta ON t.id = ta.tag_id AND ta.workspace_id = t.workspace_id
             WHERE ta.workspace_id = ? AND t.workspace_id = ? AND ta.entity_type = ? AND ta.entity_id = ?
             ORDER BY t.name ASC",
            [$this->workspaceId(), $this->workspaceId(), $entityType, $entityId]
        );
    }

    /**
     * @param array<int, int> $entityIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getTagsForEntities(string $entityType, array $entityIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $entityIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::query(
            "SELECT ta.entity_id, t.*
             FROM tag_assignments ta
             INNER JOIN tags t ON t.id = ta.tag_id AND t.workspace_id = ta.workspace_id
             WHERE ta.workspace_id = ?
               AND ta.entity_type = ?
               AND ta.entity_id IN ($placeholders)
             ORDER BY ta.entity_id ASC, t.name ASC",
            array_merge([$this->workspaceId(), $entityType], $ids)
        );

        $tagsByEntity = [];
        foreach ($rows as $row) {
            $entityId = (int) ($row['entity_id'] ?? 0);
            if ($entityId <= 0) {
                continue;
            }
            unset($row['entity_id']);
            $tagsByEntity[$entityId][] = $this->normalizeTag($row) ?? $row;
        }

        return $tagsByEntity;
    }
    
    /**
     * Get entities by tag
     */
    public function getEntitiesByTag(int $tagId, string $entityType, int $limit = 100, int $offset = 0): array
    {
        if (!$this->getById($tagId)) {
            return [];
        }

        $sql = "SELECT ta.entity_id 
                FROM tag_assignments ta
                WHERE ta.workspace_id = ? AND ta.tag_id = ? AND ta.entity_type = ?
                LIMIT ? OFFSET ?";
        
        $results = Database::query($sql, [$this->workspaceId(), $tagId, $entityType, $limit, $offset]);
        
        return array_column($results, 'entity_id');
    }
    
    /**
     * Get tag statistics
     */
    public function getStatistics(): array
    {
        $workspaceId = $this->workspaceId();
        $totalTags = (int) Database::queryOne("SELECT COUNT(*) as count FROM tags WHERE workspace_id = ?", [$workspaceId])['count'] ?? 0;
        $totalAssignments = (int) Database::queryOne("SELECT COUNT(*) as count FROM tag_assignments WHERE workspace_id = ?", [$workspaceId])['count'] ?? 0;
        
        $tagsByType = Database::query(
            "SELECT entity_type, COUNT(*) as count 
             FROM tag_assignments
             WHERE workspace_id = ?
             GROUP BY entity_type",
            [$workspaceId]
        );
        
        $mostUsed = Database::query(
            "SELECT t.*, COUNT(ta.id) as usage_count
             FROM tags t
             LEFT JOIN tag_assignments ta ON t.id = ta.tag_id AND ta.workspace_id = t.workspace_id
             WHERE t.workspace_id = ?
             GROUP BY t.id
             ORDER BY usage_count DESC
             LIMIT 10",
            [$workspaceId]
        );
        
        return [
            'total_tags' => $totalTags,
            'total_assignments' => $totalAssignments,
            'tags_by_type' => $tagsByType,
            'most_used' => $mostUsed
        ];
    }
    
    /**
     * Bulk assign tags to entity
     */
    public function bulkAssign(array $tagIds, string $entityType, int $entityId): int
    {
        $assigned = 0;
        foreach ($tagIds as $tagId) {
            if ($this->assign((int) $tagId, $entityType, $entityId)) {
                $assigned++;
            }
        }
        return $assigned;
    }
    
    /**
     * Bulk remove tags from entity
     */
    public function bulkUnassign(array $tagIds, string $entityType, int $entityId): int
    {
        $removed = 0;
        foreach ($tagIds as $tagId) {
            if ($this->unassign((int) $tagId, $entityType, $entityId)) {
                $removed++;
            }
        }
        return $removed;
    }

    private function normalizeTag(?array $tag): ?array
    {
        if ($tag === null) {
            return null;
        }

        if (isset($tag['color'])) {
            $tag['color'] = $this->normalizeColor((string) $tag['color']);
        }

        return $tag;
    }

    private function normalizeColor(string $color, ?string $fallback = null): string
    {
        $color = trim($color);
        if ($color === '') {
            return $fallback !== null ? $this->normalizeColor($fallback) : self::DEFAULT_COLOR;
        }

        if (preg_match('/^#([0-9A-Fa-f]{3})$/', $color, $matches)) {
            $expanded = strtolower($matches[1]);
            return sprintf(
                '#%1$s%1$s%2$s%2$s%3$s%3$s',
                $expanded[0],
                $expanded[1],
                $expanded[2]
            );
        }

        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return strtolower($color);
        }

        return $fallback !== null ? $this->normalizeColor($fallback) : self::DEFAULT_COLOR;
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

    private function entityBelongsToWorkspace(string $entityType, int $entityId): bool
    {
        if ($entityId <= 0) {
            return false;
        }

        $tables = [
            'contact' => 'contacts',
            'task' => 'tasks',
            'event' => 'events',
            'email' => 'communications',
            'activity' => 'activities',
        ];

        $table = $tables[$entityType] ?? null;
        if ($table === null || !$this->tableHasWorkspaceColumn($table)) {
            return true;
        }

        $row = Database::queryOne(
            "SELECT id FROM {$table} WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$this->workspaceId(), $entityId]
        );

        return $row !== null;
    }

    private function tableHasWorkspaceColumn(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = 'workspace_id'",
            [$table]
        );

        return (int) ($row['c'] ?? 0) > 0;
    }
}
