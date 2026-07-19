<?php
/**
 * Saved Searches Module
 * 
 * Handles saved search queries
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Auth;

class SavedSearches
{
    /** @var array<int, string> */
    private const ALLOWED_ENTITY_TYPES = ['all', 'contacts', 'deals', 'tasks', 'events'];

    /**
     * Create a saved search
     */
    public function create(array $data): int
    {
        if (empty($data['name']) || empty($data['entity_type'])) {
            throw new \Exception("Name and entity type are required");
        }
        
        $userId = Auth::userId();
        if (!$userId) {
            throw new \Exception("User must be logged in");
        }
        
        $name = Security::sanitizeInput($data['name'], 'string');
        $entityType = Security::sanitizeInput($data['entity_type'], 'string');
        if (!in_array($entityType, self::ALLOWED_ENTITY_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid saved search entity type');
        }
        $searchQuery = Security::sanitizeInput($data['search_query'] ?? '', 'string');
        $filters = $this->normalizeFilters($data['filters'] ?? null);
        
        Database::execute(
            "INSERT INTO saved_searches (user_id, name, entity_type, search_query, filters) 
             VALUES (?, ?, ?, ?, ?)",
            [$userId, $name, $entityType, $searchQuery, $filters]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get saved searches for user
     */
    public function getUserSearches(string $entityType = null): array
    {
        $userId = Auth::userId();
        if (!$userId) {
            return [];
        }
        if ($entityType !== null && !in_array($entityType, self::ALLOWED_ENTITY_TYPES, true)) {
            return [];
        }
        
        $sql = "SELECT * FROM saved_searches WHERE user_id = ?";
        $params = [$userId];
        
        if ($entityType) {
            $sql .= " AND entity_type = ?";
            $params[] = $entityType;
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $searches = Database::query($sql, $params);
        
        foreach ($searches as &$search) {
            $search['filters'] = !empty($search['filters']) ? json_decode($search['filters'], true) : null;
        }
        
        return $searches;
    }
    
    /**
     * Get saved search by ID
     */
    public function getById(int $id): ?array
    {
        $userId = Auth::userId();
        if (!$userId) {
            return null;
        }
        
        $search = Database::queryOne(
            "SELECT * FROM saved_searches WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        
        if ($search) {
            $search['filters'] = !empty($search['filters']) ? json_decode($search['filters'], true) : null;
        }
        
        return $search;
    }
    
    /**
     * Delete saved search
     */
    public function delete(int $id): bool
    {
        $userId = Auth::userId();
        if (!$userId) {
            return false;
        }

        if (!$this->getById($id)) {
            return false;
        }
        
        $deletedRows = Database::execute(
            "DELETE FROM saved_searches WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        
        return $deletedRows > 0;
    }

    private function normalizeFilters(mixed $filters): ?string
    {
        if ($filters === null || $filters === '' || $filters === []) {
            return null;
        }

        if (is_string($filters)) {
            $decoded = json_decode($filters, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Saved search filters must be valid JSON');
            }
            $filters = $decoded;
        }

        if (!is_array($filters)) {
            throw new \InvalidArgumentException('Saved search filters must be an object');
        }

        return json_encode($filters);
    }
}
