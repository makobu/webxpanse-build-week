<?php
/**
 * Document Categories Module
 * 
 * Handles document category management
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\WorkspaceScopeService;

class DocumentCategories
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
    }

    /**
     * Create a new category
     */
    public function create(array $data): int
    {
        if (empty($data['name'])) {
            throw new \Exception("Category name is required");
        }
        
        $name = Security::sanitizeInput($data['name'], 'string');
        $description = !empty($data['description']) ? Security::sanitizeInput($data['description'], 'string') : null;
        $color = !empty($data['color']) ? Security::sanitizeInput($data['color'], 'string') : '#3B82F6';
        $createdBy = (int) ($data['created_by'] ?? $_SESSION['user_id'] ?? 0);
        
        // Validate color format
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#3B82F6';
        }
        
        Database::execute(
            "INSERT INTO document_categories (workspace_id, name, description, color, created_by)
             VALUES (?, ?, ?, ?, ?)",
            [$this->workspaceId(), $name, $description, $color, $createdBy]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get category by ID
     */
    public function getById(int $id): ?array
    {
        $workspace = $this->workspaceClause('dc.');
        return Database::queryOne(
            "SELECT dc.*, u.email as created_by_email,
                    (SELECT COUNT(*) FROM documents WHERE workspace_id = dc.workspace_id AND category_id = dc.id) as document_count
             FROM document_categories dc
             LEFT JOIN users u ON dc.created_by = u.id
             WHERE {$workspace['sql']} AND dc.id = ?",
            array_merge($workspace['params'], [$id])
        );
    }
    
    /**
     * Update category
     */
    public function update(int $id, array $data): bool
    {
        $category = $this->getById($id);
        if (!$category) {
            throw new \Exception("Category not found");
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['name'])) {
            $updates[] = "name = ?";
            $params[] = Security::sanitizeInput($data['name'], 'string');
        }
        
        if (isset($data['description'])) {
            $updates[] = "description = ?";
            $params[] = Security::sanitizeInput($data['description'], 'string');
        }
        
        if (isset($data['color'])) {
            $color = Security::sanitizeInput($data['color'], 'string');
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $updates[] = "color = ?";
                $params[] = $color;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $this->workspaceId();
        $params[] = $id;
        
        Database::execute(
            "UPDATE document_categories SET " . implode(', ', $updates) . " WHERE workspace_id = ? AND id = ?",
            $params
        );
        
        return true;
    }
    
    /**
     * Delete category
     */
    public function delete(int $id): bool
    {
        $category = $this->getById($id);
        if (!$category) {
            return false;
        }
        
        // Set documents in this category to NULL (cascade handled by foreign key)
        Database::execute(
            "UPDATE documents SET category_id = NULL WHERE workspace_id = ? AND category_id = ?",
            [$this->workspaceId(), $id]
        );
        
        // Delete category
        Database::execute("DELETE FROM document_categories WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $id]);
        
        return true;
    }
    
    /**
     * Get all categories
     */
    public function getAll(): array
    {
        $workspace = $this->workspaceClause('dc.');
        return Database::query(
            "SELECT dc.*, u.email as created_by_email,
                    (SELECT COUNT(*) FROM documents WHERE workspace_id = dc.workspace_id AND category_id = dc.id) as document_count
             FROM document_categories dc
             LEFT JOIN users u ON dc.created_by = u.id
             WHERE {$workspace['sql']}
             ORDER BY dc.name ASC",
            $workspace['params']
        );
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
}
