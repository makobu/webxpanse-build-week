<?php
/**
 * Draft Templates Module
 * 
 * Manages AI-generated draft templates for emails and messages
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\WorkspaceScopeService;

class DraftTemplates
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    /**
     * Create a draft template
     */
    public function create(array $data): int
    {
        if (empty($data['name']) || empty($data['type'])) {
            throw new \Exception("Name and type are required");
        }
        
        $name = Security::sanitizeInput($data['name'], 'string');
        $type = Security::sanitizeInput($data['type'], 'string'); // 'email' or 'whatsapp'
        $purpose = Security::sanitizeInput($data['purpose'] ?? '', 'string');
        $tone = Security::sanitizeInput($data['tone'] ?? 'professional', 'string');
        $subject = !empty($data['subject']) ? Security::sanitizeInput($data['subject'], 'string') : null;
        $body = Security::sanitizeInput($data['body'] ?? '', 'string');
        $variables = !empty($data['variables']) ? (is_array($data['variables']) ? json_encode($data['variables']) : $data['variables']) : null;
        $createdBy = (int) ($data['created_by'] ?? $_SESSION['user_id'] ?? 0);
        $workspaceId = $this->requireWorkspaceId();
        
        // Validate type
        $allowedTypes = ['email', 'whatsapp'];
        if (!in_array($type, $allowedTypes)) {
            throw new \Exception("Invalid template type");
        }
        
        Database::execute(
            "INSERT INTO draft_templates (workspace_id, name, type, purpose, tone, subject, body, variables, created_by) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$workspaceId, $name, $type, $purpose, $tone, $subject, $body, $variables, $createdBy]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get template by ID
     */
    public function getById(int $id): ?array
    {
        // Check if table exists first
        try {
            Database::queryOne("SELECT 1 FROM draft_templates LIMIT 1");
        } catch (\PDOException $e) {
            // Table doesn't exist, return null
            return null;
        }
        
        $template = Database::queryOne(
            "SELECT dt.*, u.email as created_by_email
             FROM draft_templates dt
             LEFT JOIN users u ON dt.created_by = u.id
             WHERE dt.id = ? AND dt.workspace_id = ?",
            [$id, $this->requireWorkspaceId()]
        );
        
        if ($template) {
            $template['variables'] = !empty($template['variables']) ? json_decode($template['variables'], true) : [];
        }
        
        return $template;
    }
    
    /**
     * Get all templates
     */
    public function getAll(string $type = null, int $userId = null): array
    {
        // Check if table exists first
        try {
            Database::queryOne("SELECT 1 FROM draft_templates LIMIT 1");
        } catch (\PDOException $e) {
            // Table doesn't exist, return empty array
            return [];
        }
        
        $where = [];
        $params = [];
        $where[] = "dt.workspace_id = ?";
        $params[] = $this->requireWorkspaceId();
        
        if ($type) {
            $where[] = "dt.type = ?";
            $params[] = $type;
        }
        
        if ($userId) {
            $where[] = "dt.created_by = ?";
            $params[] = $userId;
        }
        
        $sql = "SELECT dt.*, u.email as created_by_email
                FROM draft_templates dt
                LEFT JOIN users u ON dt.created_by = u.id";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " ORDER BY dt.created_at DESC";
        
        $templates = Database::query($sql, $params);
        
        foreach ($templates as &$template) {
            $template['variables'] = !empty($template['variables']) ? json_decode($template['variables'], true) : [];
        }
        
        return $templates;
    }
    
    /**
     * Update template
     */
    public function update(int $id, array $data): bool
    {
        $template = $this->getById($id);
        if (!$template) {
            throw new \Exception("Template not found");
        }
        
        $updates = [];
        $params = [];
        
        $allowedFields = ['name', 'purpose', 'tone', 'subject', 'body', 'variables'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'variables') {
                    $value = is_array($data[$field]) ? json_encode($data[$field]) : $data[$field];
                } else {
                    $value = Security::sanitizeInput($data[$field], 'string');
                }
                $updates[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $id;
        
        Database::execute(
            "UPDATE draft_templates SET " . implode(', ', $updates) . " WHERE id = ? AND workspace_id = ?",
            array_merge($params, [$this->requireWorkspaceId()])
        );
        
        return true;
    }
    
    /**
     * Delete template
     */
    public function delete(int $id): bool
    {
        $template = $this->getById($id);
        if (!$template) {
            return false;
        }
        
        Database::execute("DELETE FROM draft_templates WHERE id = ? AND workspace_id = ?", [$id, $this->requireWorkspaceId()]);
        
        return true;
    }
    
    /**
     * Get templates by purpose
     */
    public function getByPurpose(string $purpose, string $type = 'email'): array
    {
        // Check if table exists first
        try {
            Database::queryOne("SELECT 1 FROM draft_templates LIMIT 1");
        } catch (\PDOException $e) {
            // Table doesn't exist, return empty array
            return [];
        }
        
        return Database::query(
            "SELECT * FROM draft_templates 
             WHERE workspace_id = ? AND purpose = ? AND type = ? 
             ORDER BY created_at DESC",
            [$this->requireWorkspaceId(), $purpose, $type]
        );
    }
    
    /**
     * Apply template to contact
     */
    public function applyToContact(int $templateId, int $contactId): array
    {
        $template = $this->getById($templateId);
        if (!$template) {
            throw new \Exception("Template not found");
        }
        
        $contact = Database::queryOne(
            "SELECT * FROM contacts WHERE id = ? AND workspace_id = ?",
            [$contactId, $this->requireWorkspaceId()]
        );
        if (!$contact) {
            throw new \Exception("Contact not found");
        }
        
        // Replace variables in template
        $replacements = [
            '{first_name}' => $contact['first_name'] ?? '',
            '{last_name}' => $contact['last_name'] ?? '',
            '{full_name}' => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')),
            '{email}' => $contact['email'] ?? '',
            '{company}' => $contact['company'] ?? '',
            '{phone}' => $contact['phone'] ?? ''
        ];
        
        $subject = $template['subject'] ?? '';
        $body = $template['body'] ?? '';
        
        foreach ($replacements as $key => $value) {
            $subject = str_replace($key, $value, $subject);
            $body = str_replace($key, $value, $body);
        }
        
        return [
            'subject' => $subject,
            'body' => $body,
            'type' => $template['type'],
            'tone' => $template['tone']
        ];
    }

    private function requireWorkspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }
}
