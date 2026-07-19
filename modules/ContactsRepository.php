<?php
/**
 * Contacts Repository
 * 
 * Data access layer for contacts
 */

namespace CRM\Modules;

use CRM\Database;

class ContactsRepository
{
    /**
     * Find contact by criteria
     */
    public function findBy(array $criteria): array
    {
        $where = [];
        $params = [];
        
        foreach ($criteria as $field => $value) {
            $where[] = "$field = ?";
            $params[] = $value;
        }
        
        $sql = "SELECT * FROM contacts";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        return Database::query($sql, $params);
    }
    
    /**
     * Count contacts by stage
     */
    public function countByStage(?string $stage = null): int
    {
        if ($stage) {
            $result = Database::queryOne("SELECT COUNT(*) as count FROM contacts WHERE stage = ?", [$stage]);
        } else {
            $result = Database::queryOne("SELECT COUNT(*) as count FROM contacts");
        }
        
        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Get contacts by assigned user
     */
    public function findByAssignedTo(int $userId): array
    {
        return Database::query(
            "SELECT * FROM contacts WHERE assigned_to = ? ORDER BY created_at DESC",
            [$userId]
        );
    }
}
