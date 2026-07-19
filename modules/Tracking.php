<?php
/**
 * Tracking Module
 * 
 * Server-side tracking handler
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\TouchpointIngestionService;
use CRM\Services\WorkspaceContext;

class Tracking
{
    private TouchpointIngestionService $touchpointIngestion;

    public function __construct()
    {
        $this->touchpointIngestion = new TouchpointIngestionService();
    }

    /**
     * Track page view
     */
    public function trackPageView(array $data): int
    {
        $visitorId = Security::sanitizeInput($data['visitor_id'] ?? '', 'string');
        $pagePath = Security::sanitizeInput($data['page'] ?? '', 'string');
        $referrer = Security::sanitizeInput($data['referrer'] ?? '', 'string');
        $utm = $data['utm'] ?? [];
        
        // Get or create visitor
        $visitor = $this->getOrCreateVisitor($visitorId, [
            'referrer' => $referrer,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
        $campaignId = !empty($data['campaign_id']) ? (int) $data['campaign_id'] : null;

        // Insert page view
        Database::execute(
            "INSERT INTO page_views (visitor_id, page_path, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content, campaign_id) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $visitorId,
                $pagePath,
                $referrer,
                $utm['utm_source'] ?? null,
                $utm['utm_medium'] ?? null,
                $utm['utm_campaign'] ?? null,
                $utm['utm_term'] ?? null,
                $utm['utm_content'] ?? null,
                $campaignId
            ]
        );
        
        $pageViewId = (int) Database::lastInsertId();
        
        // Try to identify contact and attach page view when possible
        $contactId = $this->tryIdentifyContact($visitorId);
        if ($contactId) {
            Database::execute(
                "UPDATE page_views SET contact_id = ? WHERE id = ?",
                [$contactId, $pageViewId]
            );
            $this->touchpointIngestion->ingestTouchpoint([
                'contact_id' => $contactId,
                'visitor_id' => $visitorId,
                'campaign_id' => $campaignId,
                'source_table' => 'page_views',
                'source_id' => $pageViewId,
                'channel' => 'web',
                'touch_type' => 'page_view',
                'occurred_at' => date('Y-m-d H:i:s'),
                'utm_source' => $utm['utm_source'] ?? null,
                'utm_medium' => $utm['utm_medium'] ?? null,
                'utm_campaign' => $utm['utm_campaign'] ?? null,
                'utm_term' => $utm['utm_term'] ?? null,
                'utm_content' => $utm['utm_content'] ?? null,
                'metadata' => [
                    'page_path' => $pagePath,
                    'referrer' => $referrer
                ]
            ]);
        }
        
        return $pageViewId;
    }
    
    /**
     * Track form submission
     */
    public function trackFormSubmission(array $data): int
    {
        $visitorId = Security::sanitizeInput($data['visitor_id'] ?? '', 'string');
        $formId = Security::sanitizeInput($data['form_id'] ?? '', 'string');
        $formDefinitionId = !empty($data['form_definition_id']) ? (int) $data['form_definition_id'] : null;
        $workspaceId = !empty($data['workspace_id'])
            ? (int) $data['workspace_id']
            : (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0 && $formDefinitionId !== null) {
            $formWorkspace = Database::queryOne(
                "SELECT workspace_id FROM forms WHERE id = ? LIMIT 1",
                [$formDefinitionId]
            );
            $workspaceId = (int) ($formWorkspace['workspace_id'] ?? 0);
        }
        $formData = $data['form_data'] ?? [];
        $pagePath = Security::sanitizeInput($data['page'] ?? '', 'string');
        $utm = $data['utm'] ?? [];
        
        // Get or create visitor
        $this->getOrCreateVisitor($visitorId);
        
        // Try to identify/create contact from form data
        $contactId = null;
        if (isset($formData['email']) && !empty($formData['email'])) {
            $contactId = $this->identifyOrCreateContact($formData, $visitorId, $workspaceId);
        }
        
        $campaignId = !empty($data['campaign_id']) ? (int) $data['campaign_id'] : null;

        // Insert form submission
        Database::execute(
            "INSERT INTO form_submissions (workspace_id, visitor_id, contact_id, form_id, form_definition_id, form_data, page_path, campaign_id, utm_source, utm_medium, utm_campaign, utm_term, utm_content)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId > 0 ? $workspaceId : null,
                $visitorId,
                $contactId,
                $formId,
                $formDefinitionId,
                json_encode($formData),
                $pagePath,
                $campaignId,
                $utm['utm_source'] ?? null,
                $utm['utm_medium'] ?? null,
                $utm['utm_campaign'] ?? null,
                $utm['utm_term'] ?? null,
                $utm['utm_content'] ?? null
            ]
        );
        
        $submissionId = (int) Database::lastInsertId();
        
        // Log activity if contact identified
        if ($contactId) {
            $this->touchpointIngestion->linkVisitorToContact($visitorId, $contactId, 'high');
            $this->touchpointIngestion->ingestTouchpoint([
                'contact_id' => $contactId,
                'visitor_id' => $visitorId,
                'campaign_id' => $campaignId,
                'source_table' => 'form_submissions',
                'source_id' => $submissionId,
                'channel' => 'form',
                'touch_type' => 'form_submit',
                'occurred_at' => date('Y-m-d H:i:s'),
                'utm_source' => $utm['utm_source'] ?? null,
                'utm_medium' => $utm['utm_medium'] ?? null,
                'utm_campaign' => $utm['utm_campaign'] ?? null,
                'utm_term' => $utm['utm_term'] ?? null,
                'utm_content' => $utm['utm_content'] ?? null,
                'metadata' => [
                    'form_id' => $formId,
                    'form_definition_id' => $formDefinitionId,
                    'page' => $pagePath
                ]
            ]);

            $activities = new Activities();
            $activities->log($contactId, 'form_submit', "Form submitted: $formId", [
                'form_id' => $formId,
                'form_data' => $formData,
                'page' => $pagePath
            ]);
        }
        
        return $submissionId;
    }
    
    /**
     * Get or create visitor
     */
    private function getOrCreateVisitor(string $visitorId, array $data = []): array
    {
        $visitor = Database::queryOne(
            "SELECT * FROM visitors WHERE visitor_id = ?",
            [$visitorId]
        );
        
        if (!$visitor) {
            Database::execute(
                "INSERT INTO visitors (visitor_id, referrer, ip_address, user_agent) 
                 VALUES (?, ?, ?, ?)",
                [
                    $visitorId,
                    $data['referrer'] ?? null,
                    $data['ip_address'] ?? null,
                    $data['user_agent'] ?? null
                ]
            );
            
            $visitor = Database::queryOne(
                "SELECT * FROM visitors WHERE visitor_id = ?",
                [$visitorId]
            );
        } else {
            // Update last seen
            Database::execute(
                "UPDATE visitors SET last_seen = NOW() WHERE visitor_id = ?",
                [$visitorId]
            );
        }
        
        return $visitor ?: [];
    }
    
    /**
     * Try to identify contact from visitor data
     */
    private function tryIdentifyContact(string $visitorId): ?int
    {
        if ($visitorId === '') {
            return null;
        }

        $linked = Database::queryOne(
            "SELECT contact_id FROM visitor_identity_links
             WHERE visitor_id = ?
             ORDER BY linked_at DESC
             LIMIT 1",
            [$visitorId]
        );
        if ($linked && !empty($linked['contact_id'])) {
            return (int) $linked['contact_id'];
        }

        $fromSubmission = Database::queryOne(
            "SELECT contact_id FROM form_submissions
             WHERE visitor_id = ? AND contact_id IS NOT NULL
             ORDER BY submitted_at DESC
             LIMIT 1",
            [$visitorId]
        );
        if ($fromSubmission && !empty($fromSubmission['contact_id'])) {
            $contactId = (int) $fromSubmission['contact_id'];
            $this->touchpointIngestion->linkVisitorToContact($visitorId, $contactId, 'medium');
            return $contactId;
        }

        return null;
    }
    
    /**
     * Identify or create contact from form data
     */
    private function identifyOrCreateContact(array $formData, string $visitorId, int $workspaceId): ?int
    {
        $email = Security::sanitizeInput($formData['email'] ?? '', 'email');
        
        if (!Security::validateEmail($email)) {
            return null;
        }
        
        // Check if contact exists
        $contact = $workspaceId > 0
            ? Database::queryOne(
                "SELECT id FROM contacts WHERE workspace_id = ? AND email = ? LIMIT 1",
                [$workspaceId, $email]
            )
            : Database::queryOne(
                "SELECT id FROM contacts WHERE email = ? LIMIT 1",
                [$email]
            );
        
        if ($contact) {
            $contactId = (int) $contact['id'];
            $this->touchpointIngestion->linkVisitorToContact($visitorId, $contactId, 'high');
            return $contactId;
        }
        
        // Create new contact
        $contacts = new Contacts();
        try {
            $result = $contacts->create([
                'first_name' => Security::sanitizeInput($formData['first_name'] ?? $formData['name'] ?? '', 'string'),
                'last_name' => Security::sanitizeInput($formData['last_name'] ?? '', 'string'),
                'email' => $email,
                'phone' => Security::sanitizeInput($formData['phone'] ?? '', 'string'),
                'company' => Security::sanitizeInput($formData['company'] ?? '', 'string'),
                'lead_source' => 'form'
            ]);
            
            if ($result['status'] === 'success') {
                $contactId = (int) $result['id'];
                $this->touchpointIngestion->linkVisitorToContact($visitorId, $contactId, 'high');
                return $contactId;
            }
        } catch (\Exception $e) {
            // Log error but don't fail
            error_log("Error creating contact from form: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Get visitor page views
     */
    public function getVisitorPageViews(string $visitorId, int $limit = 50): array
    {
        return Database::query(
            "SELECT * FROM page_views WHERE visitor_id = ? ORDER BY viewed_at DESC LIMIT ?",
            [$visitorId, $limit]
        );
    }
    
    /**
     * Get contact page views
     */
    public function getContactPageViews(int $contactId, int $limit = 50): array
    {
        return Database::query(
            "SELECT * FROM page_views WHERE contact_id = ? ORDER BY viewed_at DESC LIMIT ?",
            [$contactId, $limit]
        );
    }
}
