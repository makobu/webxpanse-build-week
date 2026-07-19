<?php
/**
 * Global Search Module
 * 
 * Provides unified search across all CRM entities
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\WorkspaceScopeService;

class Search
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
    }

    /**
     * Perform global search across all entities
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }
        
        $searchTerm = '%' . Security::sanitizeInput($query, 'string') . '%';
        $limit = max(1, min(50, (int) $limit));
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $results = [];
        
        // Search Contacts
        $contacts = Database::query(
            "SELECT id, first_name, last_name, email, company, stage, 'contact' as entity_type
             FROM contacts
             WHERE workspace_id = ?
               AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR company LIKE ?)
             ORDER BY first_name, last_name ASC
             LIMIT ?",
            [$workspaceId, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]
        );
        if (!empty($contacts)) {
            $results['contacts'] = $contacts;
        }
        
        // Search Deals
        $deals = Database::query(
            "SELECT d.id, d.title, d.stage, d.value, d.currency, 'deal' as entity_type,
                    c.first_name, c.last_name, c.email as contact_email
             FROM deals d
             LEFT JOIN contacts c ON d.contact_id = c.id AND c.workspace_id = d.workspace_id
             WHERE d.workspace_id = ?
               AND (d.title LIKE ? OR d.description LIKE ?)
             ORDER BY d.created_at DESC
             LIMIT ?",
            [$workspaceId, $searchTerm, $searchTerm, $limit]
        );
        if (!empty($deals)) {
            $results['deals'] = $deals;
        }
        
        // Search Tasks
        $tasks = Database::query(
            "SELECT t.id, t.title, t.status, t.priority, t.due_date, 'task' as entity_type,
                    c.first_name, c.last_name, c.email as contact_email
             FROM tasks t
             LEFT JOIN contacts c ON t.contact_id = c.id AND c.workspace_id = t.workspace_id
             WHERE t.workspace_id = ?
               AND (t.title LIKE ? OR t.description LIKE ?)
             ORDER BY t.created_at DESC
             LIMIT ?",
            [$workspaceId, $searchTerm, $searchTerm, $limit]
        );
        if (!empty($tasks)) {
            $results['tasks'] = $tasks;
        }
        
        // Search Events
        $events = Database::query(
            "SELECT e.id, e.title, e.event_type, e.start_time, e.status, 'event' as entity_type,
                    c.first_name, c.last_name, c.email as contact_email
             FROM events e
             LEFT JOIN contacts c ON e.contact_id = c.id AND c.workspace_id = e.workspace_id
             WHERE e.workspace_id = ?
               AND (e.title LIKE ? OR e.description LIKE ? OR e.location LIKE ?)
             ORDER BY e.start_time ASC
             LIMIT ?",
            [$workspaceId, $searchTerm, $searchTerm, $searchTerm, $limit]
        );
        if (!empty($events)) {
            $results['events'] = $events;
        }
        
        // Search Emails
        $emails = Database::query(
            "SELECT e.id, e.subject, e.to_email, e.status, e.created_at, 'email' as entity_type,
                    c.first_name, c.last_name, c.email as contact_email
             FROM emails e
             LEFT JOIN contacts c ON e.contact_id = c.id AND c.workspace_id = e.workspace_id
             WHERE e.workspace_id = ?
               AND (e.subject LIKE ? OR e.body LIKE ? OR e.to_email LIKE ?)
             ORDER BY e.created_at DESC
             LIMIT ?",
            [$workspaceId, $searchTerm, $searchTerm, $searchTerm, $limit]
        );
        if (!empty($emails)) {
            $results['emails'] = $emails;
        }
        
        // Search Activities
        $activities = Database::query(
            "SELECT a.id, a.activity_type, a.description, a.created_at, 'activity' as entity_type,
                    c.first_name, c.last_name, c.email as contact_email
             FROM activities a
             LEFT JOIN contacts c ON a.contact_id = c.id AND c.workspace_id = a.workspace_id
             WHERE a.workspace_id = ?
               AND (a.description LIKE ? OR a.activity_type LIKE ?)
             ORDER BY a.created_at DESC
             LIMIT ?",
            [$workspaceId, $searchTerm, $searchTerm, $limit]
        );
        if (!empty($activities)) {
            $results['activities'] = $activities;
        }
        
        // Search Notes
        $notes = Database::query(
            "SELECT n.id, n.title, n.content, n.entity_type, n.entity_id, n.created_at, 'note' as search_entity_type,
                    c.first_name, c.last_name, c.email as contact_email
             FROM notes n
             LEFT JOIN contacts c ON (n.entity_type = 'contact' AND n.entity_id = c.id AND c.workspace_id = n.workspace_id)
             WHERE n.workspace_id = ?
               AND (n.title LIKE ? OR n.content LIKE ?)
             ORDER BY n.created_at DESC
             LIMIT ?",
            [$workspaceId, $searchTerm, $searchTerm, $limit]
        );
        if (!empty($notes)) {
            $results['notes'] = $notes;
        }
        
        return $results;
    }
    
    /**
     * Get search suggestions (quick results)
     */
    public function getSuggestions(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }
        
        $searchTerm = '%' . Security::sanitizeInput($query, 'string') . '%';
        $limit = max(1, min(20, (int) $limit));
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $suggestions = [];
        
        // Quick contact suggestions
        $contacts = Database::query(
            "SELECT id, first_name, last_name, email, 'contact' as type
             FROM contacts
             WHERE workspace_id = ?
               AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)
             ORDER BY first_name, last_name ASC
             LIMIT ?",
            [$workspaceId, $searchTerm, $searchTerm, $searchTerm, $limit]
        );
        
        foreach ($contacts as $contact) {
            $suggestions[] = [
                'id' => $contact['id'],
                'title' => ($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''),
                'subtitle' => $contact['email'] ?? '',
                'type' => 'contact',
                'url' => publicUrl("contact_view.php?id={$contact['id']}")
            ];
        }
        
        // Quick deal suggestions
        $deals = Database::query(
            "SELECT id, title, 'deal' as type
             FROM deals
             WHERE workspace_id = ?
               AND title LIKE ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$workspaceId, $searchTerm, $limit]
        );
        
        foreach ($deals as $deal) {
            $suggestions[] = [
                'id' => $deal['id'],
                'title' => $deal['title'],
                'subtitle' => 'Deal',
                'type' => 'deal',
                'url' => publicUrl("deal_view.php?id={$deal['id']}")
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Get entity icon
     */
    public function getEntityIcon(string $entityType): string
    {
        $icons = [
            'contact' => '👤',
            'deal' => '💰',
            'task' => '✅',
            'event' => '📅',
            'email' => '📧',
            'activity' => '📝',
            'note' => '📄'
        ];
        
        return $icons[$entityType] ?? '📋';
    }
    
    /**
     * Get entity label (plural)
     */
    public function getEntityLabel(string $entityType, bool $plural = true): string
    {
        $labels = [
            'contact' => $plural ? 'Contacts' : 'Contact',
            'deal' => $plural ? 'Deals' : 'Deal',
            'task' => $plural ? 'Tasks' : 'Task',
            'event' => $plural ? 'Events' : 'Event',
            'email' => $plural ? 'Emails' : 'Email',
            'activity' => $plural ? 'Activities' : 'Activity',
            'note' => $plural ? 'Notes' : 'Note'
        ];
        
        return $labels[$entityType] ?? ($plural ? ucfirst($entityType) . 's' : ucfirst($entityType));
    }
    
    /**
     * Get entity URL
     */
    public function getEntityUrl(string $entityType, int $id): string
    {
        $urls = [
            'contact' => publicUrl("contact_view.php?id={$id}"),
            'deal' => publicUrl("deal_view.php?id={$id}"),
            'task' => publicUrl("task_view.php?id={$id}"),
            'event' => publicUrl("event_view.php?id={$id}"),
            'email' => publicUrl("email_view.php?id={$id}"),
            'activity' => publicUrl("activity_view.php?id={$id}"),
            'note' => "#" // Notes don't have a dedicated view page
        ];
        
        return $urls[$entityType] ?? '#';
    }
}
