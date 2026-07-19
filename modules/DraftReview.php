<?php
/**
 * Draft Review Module
 * 
 * Handles draft review, editing, and approval workflow
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Auth;
use CRM\Services\WorkspaceScopeService;

class DraftReview
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    /**
     * Create a draft for review
     */
    public function createDraft(array $data): int
    {
        if (empty($data['draft_type']) || empty($data['body'])) {
            throw new \Exception("Draft type and body are required");
        }
        
        $draftType = Security::sanitizeInput($data['draft_type'], 'string');
        $contactId = !empty($data['contact_id']) ? (int) $data['contact_id'] : null;
        $subject = !empty($data['subject']) ? Security::sanitizeInput($data['subject'], 'string') : null;
        $body = Security::sanitizeInput($data['body'], 'string');
        $originalBody = !empty($data['original_body']) ? Security::sanitizeInput($data['original_body'], 'string') : $body;
        $tone = Security::sanitizeInput($data['tone'] ?? 'professional', 'string');
        $createdBy = Auth::userId() ?? (int) ($data['created_by'] ?? 0);
        $workspaceId = $this->resolveWorkspaceIdForDraft($contactId);
        
        // Validate draft type
        $allowedTypes = ['email', 'whatsapp'];
        if (!in_array($draftType, $allowedTypes)) {
            throw new \Exception("Invalid draft type");
        }
        
        Database::execute(
            "INSERT INTO draft_reviews (workspace_id, draft_type, contact_id, subject, body, original_body, tone, status, created_by) 
             VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)",
            [$workspaceId, $draftType, $contactId, $subject, $body, $originalBody, $tone, $createdBy]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get draft by ID
     */
    public function getById(int $id): ?array
    {
        $draft = Database::queryOne(
            "SELECT dr.*, 
                    c.first_name as contact_first_name, c.last_name as contact_last_name, c.email as contact_email,
                    u1.email as created_by_email,
                    u2.email as reviewed_by_email
             FROM draft_reviews dr
             LEFT JOIN contacts c ON dr.contact_id = c.id AND c.workspace_id = dr.workspace_id
             LEFT JOIN users u1 ON dr.created_by = u1.id
             LEFT JOIN users u2 ON dr.reviewed_by = u2.id
             WHERE dr.id = ? AND dr.workspace_id = ?",
            [$id, $this->requireWorkspaceId()]
        );
        
        return $draft;
    }
    
    /**
     * Update draft
     */
    public function updateDraft(int $id, array $data): bool
    {
        $draft = $this->getById($id);
        if (!$draft) {
            throw new \Exception("Draft not found");
        }
        
        // Only allow editing if status is 'draft'
        if ($draft['status'] !== 'draft') {
            throw new \Exception("Cannot edit draft that is not in draft status");
        }
        
        $updates = [];
        $params = [];
        
        $allowedFields = ['subject', 'body', 'tone'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $value = Security::sanitizeInput($data[$field], 'string');
                $updates[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $id;
        
        Database::execute(
            "UPDATE draft_reviews SET " . implode(', ', $updates) . " WHERE id = ? AND workspace_id = ?",
            array_merge($params, [$draft['workspace_id']])
        );
        
        return true;
    }
    
    /**
     * Review and approve draft
     */
    public function reviewDraft(int $id, string $action, array $edits = []): bool
    {
        $draft = $this->getById($id);
        if (!$draft) {
            throw new \Exception("Draft not found");
        }
        
        $reviewedBy = Auth::userId();
        if (!$reviewedBy) {
            throw new \Exception("User must be logged in to review drafts");
        }
        
        // Apply edits if provided
        if (!empty($edits)) {
            $this->updateDraft($id, $edits);
        }
        
        // Update status
        $status = $action === 'approve' ? 'approved' : ($action === 'reject' ? 'draft' : 'reviewed');
        
        Database::execute(
            "UPDATE draft_reviews 
             SET status = ?, reviewed_by = ?, reviewed_at = NOW() 
             WHERE id = ? AND workspace_id = ?",
            [$status, $reviewedBy, $id, $draft['workspace_id']]
        );
        
        return true;
    }
    
    /**
     * Get drafts for review
     */
    public function getDraftsForReview(string $status = 'draft', int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        return Database::query(
            "SELECT dr.*, 
                    c.first_name as contact_first_name, c.last_name as contact_last_name, c.email as contact_email,
                    u.email as created_by_email
             FROM draft_reviews dr
             LEFT JOIN contacts c ON dr.contact_id = c.id AND c.workspace_id = dr.workspace_id
             LEFT JOIN users u ON dr.created_by = u.id
             WHERE dr.workspace_id = ? AND dr.status = ?
             ORDER BY dr.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            [$this->requireWorkspaceId(), $status]
        );
    }
    
    /**
     * Get user's drafts
     */
    public function getUserDrafts(int $userId, string $status = null): array
    {
        $where = ["dr.created_by = ?"];
        $params = [$userId];
        $where[] = "dr.workspace_id = ?";
        $params[] = $this->requireWorkspaceId();
        
        if ($status) {
            $where[] = "dr.status = ?";
            $params[] = $status;
        }
        
        return Database::query(
            "SELECT dr.*, 
                    c.first_name as contact_first_name, c.last_name as contact_last_name, c.email as contact_email
             FROM draft_reviews dr
             LEFT JOIN contacts c ON dr.contact_id = c.id AND c.workspace_id = dr.workspace_id
             WHERE " . implode(" AND ", $where) . "
             ORDER BY dr.created_at DESC",
            $params
        );
    }
    
    /**
     * Delete draft
     */
    public function deleteDraft(int $id): bool
    {
        $draft = $this->getById($id);
        if (!$draft) {
            return false;
        }
        
        // Only allow deletion of drafts or rejected drafts
        if (!in_array($draft['status'], ['draft', 'rejected'])) {
            throw new \Exception("Cannot delete draft that has been reviewed");
        }
        
        Database::execute("DELETE FROM draft_reviews WHERE id = ? AND workspace_id = ?", [$id, $draft['workspace_id']]);
        
        return true;
    }
    
    /**
     * Compare original and edited versions
     */
    public function getDiff(int $id): array
    {
        $draft = $this->getById($id);
        if (!$draft) {
            throw new \Exception("Draft not found");
        }
        
        return [
            'original' => $draft['original_body'] ?? $draft['body'],
            'current' => $draft['body'],
            'has_changes' => ($draft['original_body'] ?? $draft['body']) !== $draft['body']
        ];
    }

    private function requireWorkspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }

    private function resolveWorkspaceIdForDraft(?int $contactId): int
    {
        $workspaceId = $this->requireWorkspaceId();
        if ($contactId !== null) {
            $this->workspaceScope->assertSameWorkspace('contacts', $contactId, $workspaceId);
        }

        return $workspaceId;
    }
}
