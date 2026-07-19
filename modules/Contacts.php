<?php
/**
 * Contact Management Module
 * 
 * Handles contact CRUD operations and duplicate detection
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Concurrency;
use CRM\Security;
use CRM\Modules\AuditLog;
use CRM\Modules\Documents;
use CRM\Services\OutcomeEventService;
use CRM\Services\ContactIntelligenceService;
use CRM\Services\ContactAssignmentAccessService;
use CRM\Services\ContactStageHistoryService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\TargetIntelligenceService;
use CRM\Services\WorkspaceScopeService;

class Contacts
{
    private const ALLOWED_STAGES = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
    private const ALLOWED_LEAD_SOURCES = ['form', 'whatsapp', 'ad', 'referral', 'social', 'import', 'web_assessment', 'other'];

    private ?bool $hasMetadataJsonColumn = null;
    private WorkspaceScopeService $workspaceScope;
    private DemoSessionScopeService $demoScope;
    private ContactAssignmentAccessService $assignmentAccess;

    /** @var array<int, string> */
    private const STRUCTURED_SOURCE_CONTROLLED_FIELDS = [
        'job_title',
        'location',
        'company_website',
        'linkedin_url',
        'twitter_url',
        'timezone',
    ];

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
        $this->demoScope = new DemoSessionScopeService();
        $this->assignmentAccess = new ContactAssignmentAccessService($this->workspaceScope);
    }

    /**
     * Create a new contact
     */
    public function create(array $data): array
    {
        $workspaceId = $this->workspaceId();
        // Validate required fields
        $required = ['first_name', 'email'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \Exception("Missing required field: $field");
            }
        }
        
        // Sanitize inputs
        $first_name = Security::sanitizeInput($data['first_name'], 'string');
        $last_name = Security::sanitizeInput($data['last_name'] ?? '', 'string');
        $email = Security::sanitizeInput($data['email'], 'email');
        $phone = Security::sanitizeInput($data['phone'] ?? '', 'string');
        $companyLink = $this->resolveCompanyLinkData($data);
        $company = $companyLink['company'];
        $companyId = $companyLink['company_id'];
        $created_by = !empty($data['created_by']) ? (int) $data['created_by'] : ((int) ($_SESSION['user_id'] ?? 0) ?: null);
        $lead_source = $this->normalizeLeadSource($data['lead_source'] ?? 'form');
        $assigned_to = $this->resolveAssignedOwner($data['assigned_to'] ?? null, $created_by);
        
        // Sanitize enrichment fields
        $job_title = Security::sanitizeInput($data['job_title'] ?? '', 'string');
        $location = Security::sanitizeInput($data['location'] ?? '', 'string');
        $company_website = Security::sanitizeInput($data['company_website'] ?? '', 'string');
        $company_size = Security::sanitizeInput($data['company_size'] ?? '', 'string');
        $company_industry = Security::sanitizeInput($data['company_industry'] ?? '', 'string');
        $company_description = Security::sanitizeInput($data['company_description'] ?? '', 'string');
        $company_founded = $this->normalizeFoundedYear($data['company_founded'] ?? null);
        $company_revenue = Security::sanitizeInput($data['company_revenue'] ?? '', 'string');
        $linkedin_url = Security::sanitizeInput($data['linkedin_url'] ?? '', 'string');
        $twitter_url = Security::sanitizeInput($data['twitter_url'] ?? '', 'string');
        $timezone = Security::sanitizeInput($data['timezone'] ?? '', 'string');
        $email_verified = isset($data['email_verified']) && $data['email_verified'] ? 1 : 0;

        if ($first_name === '') {
            throw new \Exception("First name is required");
        }
        
        // Validate email
        if (!$email || !Security::validateEmail($email)) {
            throw new \Exception("Invalid email address");
        }
        
        // Check for duplicates
        $duplicates = $this->findDuplicates($email, $phone);
        if (!empty($duplicates['email']) || !empty($duplicates['phone'])) {
            return [
                'status' => 'duplicate',
                'matches' => $duplicates
            ];
        }
        
        // Generate UUID
        $uuid = $this->generateUuid();
        
        $metadataJson = null;
        if ($this->hasMetadataJsonColumn()) {
            $fieldProvenance = $this->buildManualFieldProvenance([
                'job_title' => $job_title,
                'location' => $location,
                'company_website' => $company_website,
                'linkedin_url' => $linkedin_url,
                'twitter_url' => $twitter_url,
                'timezone' => $timezone,
            ], $created_by);
            $metadataJson = $this->encodeMetadata([
                'field_provenance' => $fieldProvenance,
                'suggested_structured_fields' => [],
            ]);
        }

        if ($this->hasMetadataJsonColumn()) {
            Database::execute(
                "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, company, company_id, lead_source, stage, assigned_to, created_by,
                 job_title, location, company_website, company_size, company_industry, company_description,
                 company_founded, company_revenue, linkedin_url, twitter_url, timezone, email_verified, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$workspaceId, $uuid, $first_name, $last_name, $email, $phone, $company, $companyId, $lead_source, $assigned_to, $created_by,
                 $job_title, $location, $company_website, $company_size, $company_industry, $company_description,
                 $company_founded, $company_revenue, $linkedin_url, $twitter_url, $timezone, $email_verified, $metadataJson]
            );
        } else {
            Database::execute(
                "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, company, company_id, lead_source, stage, assigned_to, created_by,
                 job_title, location, company_website, company_size, company_industry, company_description,
                 company_founded, company_revenue, linkedin_url, twitter_url, timezone, email_verified)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$workspaceId, $uuid, $first_name, $last_name, $email, $phone, $company, $companyId, $lead_source, $assigned_to, $created_by,
                 $job_title, $location, $company_website, $company_size, $company_industry, $company_description,
                 $company_founded, $company_revenue, $linkedin_url, $twitter_url, $timezone, $email_verified]
            );
        }
        
        $contactId = (int) Database::lastInsertId();
        
        // Log activity
        $this->logActivity($contactId, 'contact_created', [
            'by' => $_SESSION['user_id'] ?? null,
            'data' => $data
        ]);
        
        // Log audit trail
        $auditLog = new AuditLog();
        $auditLog->log('create', 'contact', $contactId, null, $data);
        
        // Route contact notifications by ownership: assigned contacts are personal,
        // unassigned contacts stay visible to the shared queue until claimed.
        $notifications = new \CRM\Modules\Notifications();
        $notifications->createForContact(
            $contactId,
            'contact_created',
            'New Contact Created',
            "A new contact has been created: {$first_name} {$last_name}",
            [
                'link' => publicUrl("contact_view.php?id=$contactId")
            ]
        );
        
        // Publish event for webhooks
        \CRM\EventBus::publish('contact.created', [
            'contact_id' => $contactId,
            'contact' => [
                'id' => $contactId,
                'uuid' => $uuid,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
                'company' => $company,
                'company_id' => $companyId
            ]
        ]);

        try {
            $outcomes = new OutcomeEventService();
            $outcomes->track('contact.created', [
                'user_id' => $created_by ? (int) $created_by : (int) ($_SESSION['user_id'] ?? 0),
                'contact_id' => $contactId,
                'event_source' => 'contacts.create',
                'metadata' => [
                    'lead_source' => (string) $lead_source,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('Contacts::create outcome tracking failed: ' . $e->getMessage());
        }
        
        // Auto-enrichment if enabled
        if (($_ENV['AUTO_ENRICH_CONTACTS'] ?? 'false') === 'true') {
            try {
                $enrichmentService = new \CRM\Services\AIEnrichmentService();
                $enrichmentService->enrichContact($contactId, ['auto' => true]);
            } catch (\Exception $e) {
                // Log but don't fail contact creation
                error_log("Auto-enrichment error for contact $contactId: " . $e->getMessage());
            }
        }

        try {
            (new ContactIntelligenceService())->computeAndPersist($contactId);
        } catch (\Throwable $e) {
            error_log('Contacts::create intelligence refresh failed: ' . $e->getMessage());
        }
        try {
            (new TargetIntelligenceService())->refreshAfterEntityChange('contacts', $contactId, [
                'assigned_to' => $assigned_to ? (int) $assigned_to : 0,
                'company' => $company,
            ]);
        } catch (\Throwable $e) {
            error_log('Contacts::create target intelligence refresh failed: ' . $e->getMessage());
        }
        try {
            (new \CRM\Services\VoiceContactPhoneIndexService())->syncContact($workspaceId, $contactId);
        } catch (\Throwable $e) {
            error_log('Contacts::create voice phone index refresh failed: ' . $e->getMessage());
        }
        
        return [
            'status' => 'success',
            'id' => $contactId,
            'uuid' => $uuid
        ];
    }
    
    /**
     * Get contact by ID
     */
    public function getById(int $id): ?array
    {
        $workspace = $this->workspaceClause();
        return Database::queryOne(
            "SELECT * FROM contacts WHERE {$workspace['sql']} AND id = ?",
            array_merge($workspace['params'], [$id])
        );
    }

    /**
     * Return contacts that are valid recipients for a communication channel.
     * This intentionally follows the current hard-delete model: only rows that
     * still exist in contacts are returned.
     */
    public function getSelectableContactsForChannel(?string $channel = null, int $limit = 1000): array
    {
        $limit = max(1, (int) $limit);
        $workspace = $this->workspaceClause();
        $params = $workspace['params'];
        $where = [$workspace['sql']];

        $normalizedChannel = strtolower(trim((string) $channel));
        if ($normalizedChannel === 'email') {
            $where[] = "TRIM(COALESCE(email, '')) != ''";
        } elseif ($normalizedChannel === 'whatsapp') {
            $where[] = "TRIM(COALESCE(phone, '')) != ''";
        }

        $sql = "SELECT id, first_name, last_name, email, phone, company, company_id
                FROM contacts";
        if ($where !== []) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= "
            ORDER BY
                CASE WHEN TRIM(COALESCE(first_name, '')) = '' AND TRIM(COALESCE(last_name, '')) = '' THEN 1 ELSE 0 END,
                first_name ASC,
                last_name ASC,
                id DESC
            LIMIT " . $limit;

        return Database::query($sql, $params);
    }
    
    /**
     * Get contact by UUID
     */
    public function getByUuid(string $uuid): ?array
    {
        $workspace = $this->workspaceClause();
        return Database::queryOne(
            "SELECT * FROM contacts WHERE {$workspace['sql']} AND uuid = ?",
            array_merge($workspace['params'], [$uuid])
        );
    }
    
    /**
     * Update contact
     */
    public function update(int $id, array $data): bool
    {
        $contact = $this->getById($id);
        if (!$contact) {
            throw new \Exception("Contact not found");
        }
        
        // Store old values for audit log
        $oldValues = [];
        $newValues = [];
        
        $updates = [];
        $params = [];
        $submittedFields = [];
        
        $allowedFields = ['first_name', 'last_name', 'email', 'phone', 'company', 'company_id', 'lead_source', 'stage', 'assigned_to',
                         'job_title', 'location', 'company_website', 'company_size', 'company_industry', 
                         'company_description', 'company_founded', 'company_revenue', 'linkedin_url', 
                         'twitter_url', 'timezone', 'email_verified'];
        $manualProvenanceUpdates = [];
        $companyLink = $this->resolveCompanyLinkData($data, $contact);
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                if ($field === 'email') {
                    $value = Security::sanitizeInput($data[$field], 'email');
                    if (!Security::validateEmail($value)) {
                        throw new \Exception("Invalid email address");
                    }
                } elseif ($field === 'first_name') {
                    $value = Security::sanitizeInput($data[$field], 'string');
                    if ($value === '') {
                        throw new \Exception("First name is required");
                    }
                } elseif ($field === 'company') {
                    $value = $companyLink['company'];
                } elseif ($field === 'company_id') {
                    $value = $companyLink['company_id'];
                } elseif ($field === 'company_founded') {
                    $value = $this->normalizeFoundedYear($data[$field]);
                } elseif ($field === 'email_verified') {
                    $value = isset($data[$field]) && $data[$field] ? 1 : 0;
                } elseif ($field === 'stage') {
                    $value = Security::sanitizeInput($data[$field], 'string');
                    if (!in_array($value, self::ALLOWED_STAGES, true)) {
                        throw new \InvalidArgumentException('Invalid contact stage');
                    }
                } elseif ($field === 'lead_source') {
                    $value = $this->normalizeLeadSource($data[$field]);
                } elseif ($field === 'assigned_to') {
                    $value = !empty($data[$field]) ? (int) $data[$field] : null;
                    $this->assignmentAccess->assertAssignableUser($value);
                } else {
                    $value = Security::sanitizeInput($data[$field], 'string');
                }
                
                // Track changes for audit log
                if (isset($contact[$field]) && $contact[$field] != $value) {
                    $oldValues[$field] = $contact[$field];
                    $newValues[$field] = $value;
                }

                if (in_array($field, self::STRUCTURED_SOURCE_CONTROLLED_FIELDS, true)) {
                    if ($value === null || $value === '') {
                        $manualProvenanceUpdates[$field] = null;
                    } else {
                        $manualProvenanceUpdates[$field] = $this->buildFieldProvenanceEntry(
                            'manual',
                            'user:' . (string) ((int) ($_SESSION['user_id'] ?? 0)),
                            'Manual edit',
                            null
                        );
                    }
                }
                
                $updates[] = "$field = ?";
                $params[] = $value;
                $submittedFields[$field] = $value;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        if ($this->hasMetadataJsonColumn() && !empty($manualProvenanceUpdates)) {
            $metadata = $this->decodeMetadata($contact['metadata_json'] ?? null);
            $metadata['field_provenance'] = $this->mergeFieldProvenance($metadata['field_provenance'] ?? [], $manualProvenanceUpdates);
            $updates[] = "metadata_json = ?";
            $params[] = $this->encodeMetadata($metadata);
        }

        Concurrency::executeWorkspaceUpdate(
            'contacts',
            $this->workspaceId(),
            $id,
            $updates,
            $params,
            Concurrency::expectedVersionFromData($data),
            fn() => $this->getById($id),
            $submittedFields,
            'contact'
        );

        (new Companies())->syncPrimaryContactForContactChange($id, $companyLink['company_id']);
        
        // Log activity
        $this->logActivity($id, 'contact_updated', [
            'by' => $_SESSION['user_id'] ?? null,
            'changes' => $data
        ]);
        
        // Log audit trail
        if (!empty($oldValues) || !empty($newValues)) {
            $auditLog = new AuditLog();
            $auditLog->log('update', 'contact', $id, $oldValues, $newValues);
        }

        $oldAssignedTo = (int) ($oldValues['assigned_to'] ?? $contact['assigned_to'] ?? 0);
        $newAssignedTo = isset($newValues['assigned_to'])
            ? (int) $newValues['assigned_to']
            : (int) ($contact['assigned_to'] ?? 0);
        if ($oldAssignedTo === 0 && $newAssignedTo > 0) {
            (new Notifications())->retireContactScopeNotifications($id);
        }
        
        // Publish event for webhooks and workflows
        $updatedContact = $this->getById($id);
        if ($updatedContact) {
            $eventData = [
                'contact_id' => $id,
                'contact' => $updatedContact,
                'changes' => $newValues
            ];
            
            // Check if stage changed
            if (isset($newValues['stage']) && isset($oldValues['stage']) && $newValues['stage'] !== $oldValues['stage']) {
                $eventData['from_stage'] = $oldValues['stage'];
                $eventData['to_stage'] = $newValues['stage'];
                (new ContactStageHistoryService())->record(
                    $this->workspaceId(),
                    $id,
                    (string) $oldValues['stage'],
                    (string) $newValues['stage'],
                    !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null
                );
                \CRM\EventBus::publish('stage.changed', $eventData);
            }
            
            \CRM\EventBus::publish('contact.updated', $eventData);
        }

        try {
            (new ContactIntelligenceService())->computeAndPersist($id);
        } catch (\Throwable $e) {
            error_log('Contacts::update intelligence refresh failed: ' . $e->getMessage());
        }
        try {
            $freshContact = $this->getById($id);
            (new TargetIntelligenceService())->refreshAfterEntityChange('contacts', $id, [
                'assigned_to' => (int) ($freshContact['assigned_to'] ?? 0),
                'company' => (string) ($freshContact['company'] ?? ''),
                'stage' => (string) ($freshContact['stage'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            error_log('Contacts::update target intelligence refresh failed: ' . $e->getMessage());
        }
        try {
            (new \CRM\Services\VoiceContactPhoneIndexService())->syncContact($this->workspaceId(), $id);
        } catch (\Throwable $e) {
            error_log('Contacts::update voice phone index refresh failed: ' . $e->getMessage());
        }
        
        return true;
    }

    private function normalizeLeadSource($value): string
    {
        $leadSource = Security::sanitizeInput((string) $value, 'string');
        if ($leadSource === '') {
            return 'form';
        }
        if (!in_array($leadSource, self::ALLOWED_LEAD_SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid lead source');
        }

        return $leadSource;
    }

    private function normalizeFoundedYear($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $year = (int) $value;
        $currentYear = (int) date('Y');
        if ($year < 1800 || $year > $currentYear) {
            throw new \InvalidArgumentException('Founded year must be between 1800 and ' . $currentYear);
        }

        return $year;
    }

    public function getFieldProvenanceMap(array $contact): array
    {
        $metadata = $this->decodeMetadata($contact['metadata_json'] ?? null);
        return is_array($metadata['field_provenance'] ?? null) ? $metadata['field_provenance'] : [];
    }

    public function getSuggestedStructuredFields(array $contact): array
    {
        $metadata = $this->decodeMetadata($contact['metadata_json'] ?? null);
        $suggestions = $metadata['suggested_structured_fields'] ?? [];
        return is_array($suggestions) ? $suggestions : [];
    }

    public function saveFieldProvenance(int $contactId, array $provenanceMap): void
    {
        if (!$this->hasMetadataJsonColumn()) {
            return;
        }

        $contact = $this->getById($contactId);
        if (!$contact) {
            return;
        }

        $metadata = $this->decodeMetadata($contact['metadata_json'] ?? null);
        $metadata['field_provenance'] = $this->mergeFieldProvenance($metadata['field_provenance'] ?? [], $provenanceMap);

        Database::execute(
            "UPDATE contacts SET metadata_json = ? WHERE workspace_id = ? AND id = ?",
            [$this->encodeMetadata($metadata), $this->workspaceId(), $contactId]
        );
    }

    public function saveSuggestedStructuredFields(int $contactId, array $suggestions): void
    {
        if (!$this->hasMetadataJsonColumn()) {
            return;
        }

        $contact = $this->getById($contactId);
        if (!$contact) {
            return;
        }

        $metadata = $this->decodeMetadata($contact['metadata_json'] ?? null);
        $metadata['suggested_structured_fields'] = $suggestions;

        Database::execute(
            "UPDATE contacts SET metadata_json = ? WHERE workspace_id = ? AND id = ?",
            [$this->encodeMetadata($metadata), $this->workspaceId(), $contactId]
        );
    }

    public function applySuggestedStructuredField(int $contactId, string $field): bool
    {
        if (!$this->hasMetadataJsonColumn()) {
            return false;
        }

        $contact = $this->getById($contactId);
        if (!$contact) {
            return false;
        }

        $metadata = $this->decodeMetadata($contact['metadata_json'] ?? null);
        $suggestions = is_array($metadata['suggested_structured_fields'] ?? null) ? $metadata['suggested_structured_fields'] : [];
        $suggestion = $suggestions[$field] ?? null;
        if (!is_array($suggestion) || empty($suggestion['value'])) {
            return false;
        }

        $this->update($contactId, [$field => (string) $suggestion['value']]);

        unset($suggestions[$field]);
        $metadata = $this->decodeMetadata($this->getById($contactId)['metadata_json'] ?? null);
        $metadata['suggested_structured_fields'] = $suggestions;
        Database::execute(
            "UPDATE contacts SET metadata_json = ? WHERE workspace_id = ? AND id = ?",
            [$this->encodeMetadata($metadata), $this->workspaceId(), $contactId]
        );

        return true;
    }

    public function dismissSuggestedStructuredField(int $contactId, string $field): bool
    {
        if (!$this->hasMetadataJsonColumn()) {
            return false;
        }

        $contact = $this->getById($contactId);
        if (!$contact) {
            return false;
        }

        $metadata = $this->decodeMetadata($contact['metadata_json'] ?? null);
        $suggestions = is_array($metadata['suggested_structured_fields'] ?? null) ? $metadata['suggested_structured_fields'] : [];
        if (!isset($suggestions[$field])) {
            return false;
        }

        unset($suggestions[$field]);
        $metadata['suggested_structured_fields'] = $suggestions;

        Database::execute(
            "UPDATE contacts SET metadata_json = ? WHERE workspace_id = ? AND id = ?",
            [$this->encodeMetadata($metadata), $this->workspaceId(), $contactId]
        );

        return true;
    }
    
    /**
     * Delete contact
     * 
     * Safely deletes a contact and all related data:
     * - Deletes documents and their physical files
     * - Deletes notes
     * - Deletes tag assignments
     * - Database foreign keys handle: activities, emails, whatsapp_messages, etc. (CASCADE)
     * - Database foreign keys handle: tasks, events, deals, etc. (SET NULL)
     */
    public function delete(int $id): bool
    {
        $contact = $this->getById($id);
        if (!$contact) {
            return false;
        }

        (new Companies())->syncPrimaryContactForContactChange($id, null);
        
        // Get all documents for this contact before deletion
        $documents = Database::query(
            "SELECT id FROM documents WHERE workspace_id = ? AND entity_type = 'contact' AND entity_id = ?",
            [$this->workspaceId(), $id]
        );
        
        // Delete documents and their physical files
        if (!empty($documents)) {
            $documentsModule = new Documents();
            foreach ($documents as $doc) {
                try {
                    $documentsModule->delete($doc['id']);
                } catch (\Exception $e) {
                    // Log error but continue with deletion
                    error_log("Error deleting document {$doc['id']} for contact {$id}: " . $e->getMessage());
                }
            }
        }
        
        // Delete notes (no foreign key constraint, must be done manually)
        Database::execute(
            "DELETE FROM notes WHERE workspace_id = ? AND entity_type = 'contact' AND entity_id = ?",
            [$this->workspaceId(), $id]
        );
        
        // Delete tag assignments (no foreign key constraint, must be done manually)
        Database::execute(
            "DELETE FROM tag_assignments WHERE entity_type = 'contact' AND entity_id = ?",
            [$id]
        );
        
        // Log audit trail before deletion
        $auditLog = new AuditLog();
        $auditLog->log('delete', 'contact', $id, $contact, null);
        
        // Publish event for webhooks before deletion
        \CRM\EventBus::publish('contact.deleted', [
            'contact_id' => $id,
            'contact' => $contact
        ]);
        
        // Delete the contact (database foreign keys will handle related records)
        Database::execute("DELETE FROM contacts WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $id]);
        
        return true;
    }
    
    /**
     * Search contacts
     */
    public function search(string $query, int $limit = 50, int $offset = 0, ?string $ownerScope = null, ?int $userId = null): array
    {
        $searchTerm = '%' . Security::sanitizeInput($query, 'string') . '%';
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);

        $workspace = $this->workspaceClause('c.');
        $params = array_merge($workspace['params'], [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        $sql = "SELECT c.*, co.name AS linked_company_name
             FROM contacts c
             LEFT JOIN companies co ON co.id = c.company_id AND co.workspace_id = c.workspace_id
             WHERE {$workspace['sql']}
               AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.company LIKE ? OR co.name LIKE ?)";

        $scope = $this->buildOwnerScopeClause($ownerScope, $userId, 'c.');
        if ($scope['sql'] !== '') {
            $sql .= " AND " . $scope['sql'];
            $params = array_merge($params, $scope['params']);
        }

        $sql .= " ORDER BY c.created_at DESC LIMIT {$limit} OFFSET {$offset}";
        return Database::query($sql, $params);
    }

    public function countSearch(string $query, ?string $ownerScope = null, ?int $userId = null): int
    {
        $searchTerm = '%' . Security::sanitizeInput($query, 'string') . '%';
        $workspace = $this->workspaceClause('c.');
        $params = array_merge($workspace['params'], [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        $sql = "SELECT COUNT(DISTINCT c.id) AS count
             FROM contacts c
             LEFT JOIN companies co ON co.id = c.company_id AND co.workspace_id = c.workspace_id
             WHERE {$workspace['sql']}
               AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.company LIKE ? OR co.name LIKE ?)";

        $scope = $this->buildOwnerScopeClause($ownerScope, $userId, 'c.');
        if ($scope['sql'] !== '') {
            $sql .= " AND " . $scope['sql'];
            $params = array_merge($params, $scope['params']);
        }

        $result = Database::queryOne($sql, $params);
        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Get all contacts with pagination
     */
    public function getAll(int $limit = 50, int $offset = 0, ?string $stage = null, ?string $ownerScope = null, ?int $userId = null): array
    {
        $workspace = $this->workspaceClause('c.');
        $sql = "SELECT c.*, co.name AS linked_company_name FROM contacts c LEFT JOIN companies co ON co.id = c.company_id AND co.workspace_id = c.workspace_id";
        $params = $workspace['params'];
        $where = [$workspace['sql']];
        
        if ($stage) {
            $where[] = "c.stage = ?";
            $params[] = $stage;
        }

        $scope = $this->buildOwnerScopeClause($ownerScope, $userId, 'c.');
        if ($scope['sql'] !== '') {
            $where[] = $scope['sql'];
            $params = array_merge($params, $scope['params']);
        }

        if ($where !== []) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        $sql .= " ORDER BY c.created_at DESC LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
        
        return Database::query($sql, $params);
    }

    public function countAll(?string $stage = null, ?string $ownerScope = null, ?int $userId = null): int
    {
        $workspace = $this->workspaceClause('c.');
        $where = [$workspace['sql']];
        $params = $workspace['params'];

        if ($stage) {
            $where[] = "c.stage = ?";
            $params[] = $stage;
        }

        $scope = $this->buildOwnerScopeClause($ownerScope, $userId, 'c.');
        if ($scope['sql'] !== '') {
            $where[] = $scope['sql'];
            $params = array_merge($params, $scope['params']);
        }

        $result = Database::queryOne(
            "SELECT COUNT(*) AS count FROM contacts c WHERE " . implode(' AND ', $where),
            $params
        );

        return (int) ($result['count'] ?? 0);
    }

    public function countCreatedSince(?string $since, ?string $ownerScope = null, ?int $userId = null): int
    {
        $since = trim((string) $since);
        if ($since === '' || strtotime($since) === false) {
            return 0;
        }

        $workspace = $this->workspaceClause('c.');
        $where = [$workspace['sql'], 'c.created_at > ?'];
        $params = array_merge($workspace['params'], [$since]);

        $scope = $this->buildOwnerScopeClause($ownerScope, $userId, 'c.');
        if ($scope['sql'] !== '') {
            $where[] = $scope['sql'];
            $params = array_merge($params, $scope['params']);
        }

        $result = Database::queryOne(
            "SELECT COUNT(*) AS count FROM contacts c WHERE " . implode(' AND ', $where),
            $params
        );

        return (int) ($result['count'] ?? 0);
    }

    public function getByTag(int $tagId, int $limit = 50, int $offset = 0, ?string $ownerScope = null, ?int $userId = null): array
    {
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);
        $workspace = $this->workspaceClause('c.');
        $params = array_merge($workspace['params'], [$tagId]);
        $where = [
            $workspace['sql'],
            "ta.entity_type = 'contact'",
            "ta.tag_id = ?",
        ];

        $scope = $this->buildOwnerScopeClause($ownerScope, $userId, 'c.');
        if ($scope['sql'] !== '') {
            $where[] = $scope['sql'];
            $params = array_merge($params, $scope['params']);
        }

        $sql = "SELECT c.*, co.name AS linked_company_name
                FROM contacts c
                INNER JOIN tag_assignments ta ON ta.entity_id = c.id
                LEFT JOIN companies co ON co.id = c.company_id AND co.workspace_id = c.workspace_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.created_at DESC
                LIMIT {$limit} OFFSET {$offset}";

        return Database::query($sql, $params);
    }

    public function countByTag(int $tagId, ?string $ownerScope = null, ?int $userId = null): int
    {
        $workspace = $this->workspaceClause('c.');
        $params = array_merge($workspace['params'], [$tagId]);
        $where = [
            $workspace['sql'],
            "ta.entity_type = 'contact'",
            "ta.tag_id = ?",
        ];

        $scope = $this->buildOwnerScopeClause($ownerScope, $userId, 'c.');
        if ($scope['sql'] !== '') {
            $where[] = $scope['sql'];
            $params = array_merge($params, $scope['params']);
        }

        $result = Database::queryOne(
            "SELECT COUNT(DISTINCT c.id) AS count
             FROM contacts c
             INNER JOIN tag_assignments ta ON ta.entity_id = c.id
             WHERE " . implode(' AND ', $where),
            $params
        );

        return (int) ($result['count'] ?? 0);
    }

    /**
     * @return array{sql:string, params:array<int, mixed>}
     */
    public function buildOwnerScopeClause(?string $ownerScope, ?int $userId, string $prefix = 'c.'): array
    {
        // Strict ownership — only contacts explicitly assigned to this user (used by mobile)
        if ($ownerScope === 'mine_only') {
            if ($userId !== null && $userId > 0) {
                return [
                    'sql'    => '(' . $prefix . 'assigned_to = ?)',
                    'params' => [$userId],
                ];
            }
            // Safety: no userId means show nothing
            return ['sql' => '1 = 0', 'params' => []];
        }

        if ($ownerScope !== 'mine_unassigned') {
            return ['sql' => '', 'params' => []];
        }

        if ($userId !== null && $userId > 0) {
            return [
                'sql' => '(' . $prefix . 'assigned_to = ? OR ' . $prefix . 'assigned_to IS NULL OR ' . $prefix . 'assigned_to = 0)',
                'params' => [$userId],
            ];
        }

        return [
            'sql' => '(' . $prefix . 'assigned_to IS NULL OR ' . $prefix . 'assigned_to = 0)',
            'params' => [],
        ];
    }

    /**
     * Mirrors the default "mine_unassigned" contact list scope for direct record access.
     *
     * @param array<string,mixed> $contact
     */
    public function isVisibleToUser(array $contact, ?int $userId, bool $canViewAll = false): bool
    {
        if ($canViewAll) {
            return true;
        }

        $assignedTo = (int) ($contact['assigned_to'] ?? 0);
        return $assignedTo === 0 || ($userId !== null && $userId > 0 && $assignedTo === $userId);
    }
    
    /**
     * Get contacts by combined filters (tag, stage, search, assigned_to, contact_ids)
     * Used for bulk messaging recipient selection
     */
    public function getByFilters(array $filters, int $limit = 1000, int $offset = 0): array
    {
        $where = [];
        $params = [];
        $joins = '';
        
        // Explicit contact IDs take precedence
        if (!empty($filters['contact_ids']) && is_array($filters['contact_ids'])) {
            $contactIds = array_map('intval', array_filter($filters['contact_ids']));
            if (empty($contactIds)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            $where[] = "c.id IN ($placeholders)";
            $params = array_merge($params, $contactIds);
        } else {
            // Tag filter - requires JOIN
            // Use isset + !== '' instead of !empty() so that tag_id=0 is handled correctly
            // (!empty(0) === false in PHP, which would silently skip the filter)
            if (isset($filters['tag_id']) && $filters['tag_id'] !== '') {
                $joins = " INNER JOIN tag_assignments ta ON ta.workspace_id = c.workspace_id AND ta.entity_id = c.id AND ta.entity_type = 'contact' AND ta.tag_id = ?";
                $params[] = (int) $filters['tag_id'];
            }
            
            // Stage filter
            if (!empty($filters['stage'])) {
                $where[] = "c.stage = ?";
                $params[] = Security::sanitizeInput($filters['stage'], 'string');
            }
            
            // Search filter
            if (!empty($filters['search'])) {
                $searchTerm = '%' . Security::sanitizeInput($filters['search'], 'string') . '%';
                $where[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.company LIKE ?)";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            }
            
            // Assigned to filter
            if (isset($filters['assigned_to']) && $filters['assigned_to'] !== '' && $filters['assigned_to'] !== null) {
                $where[] = "c.assigned_to = ?";
                $params[] = (int) $filters['assigned_to'];
            }

            if (isset($filters['company_id']) && $filters['company_id'] !== '' && $filters['company_id'] !== null) {
                $where[] = "c.company_id = ?";
                $params[] = (int) $filters['company_id'];
            }
        }
        
        $workspace = $this->workspaceClause('c.');
        $where[] = $workspace['sql'];
        $params = array_merge($params, $workspace['params']);

        $sql = "SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.company, c.company_id FROM contacts c" . $joins;
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY c.created_at DESC LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
        
        return Database::query($sql, $params);
    }
    
    /**
     * Count contacts matching filters (for bulk messaging preview)
     */
    public function countByFilters(array $filters, ?string $recipientChannel = null): int
    {
        $where = [];
        $params = [];
        $joins = '';
        
        if (!empty($filters['contact_ids']) && is_array($filters['contact_ids'])) {
            $contactIds = array_map('intval', array_filter($filters['contact_ids']));
            if (empty($contactIds)) {
                return 0;
            }
            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            $where[] = "c.id IN ($placeholders)";
            $params = array_merge($params, $contactIds);
        } else {
            // Use isset + !== '' instead of !empty() so that tag_id=0 is handled correctly
            if (isset($filters['tag_id']) && $filters['tag_id'] !== '') {
                $joins = " INNER JOIN tag_assignments ta ON ta.workspace_id = c.workspace_id AND ta.entity_id = c.id AND ta.entity_type = 'contact' AND ta.tag_id = ?";
                $params[] = (int) $filters['tag_id'];
            }
            if (!empty($filters['stage'])) {
                $where[] = "c.stage = ?";
                $params[] = Security::sanitizeInput($filters['stage'], 'string');
            }
            if (!empty($filters['search'])) {
                $searchTerm = '%' . Security::sanitizeInput($filters['search'], 'string') . '%';
                $where[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.company LIKE ?)";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            }
            if (isset($filters['assigned_to']) && $filters['assigned_to'] !== '' && $filters['assigned_to'] !== null) {
                $where[] = "c.assigned_to = ?";
                $params[] = (int) $filters['assigned_to'];
            }
            if (isset($filters['company_id']) && $filters['company_id'] !== '' && $filters['company_id'] !== null) {
                $where[] = "c.company_id = ?";
                $params[] = (int) $filters['company_id'];
            }
        }

        $recipientChannel = strtolower(trim((string) $recipientChannel));
        if ($recipientChannel === 'email') {
            $where[] = "TRIM(COALESCE(c.email, '')) != '' AND c.email REGEXP '^[^@[:space:]]+@[^@[:space:]]+[.][^@[:space:]]+$'";
        } elseif ($recipientChannel === 'phone') {
            $where[] = "LENGTH(TRIM(LEADING '0' FROM REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.phone, ''), ' ', ''), '-', ''), '(', ''), ')', ''))) >= 10";
        }
        
        $workspace = $this->workspaceClause('c.');
        $where[] = $workspace['sql'];
        $params = array_merge($params, $workspace['params']);

        $sql = "SELECT COUNT(*) as count FROM contacts c" . $joins;
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        $result = Database::queryOne($sql, $params);
        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Find duplicate contacts
     */
    private function findDuplicates(?string $email, ?string $phone): array
    {
        $duplicates = [];
        
        if (!empty($email)) {
            $workspace = $this->workspaceClause();
            $emailMatches = Database::query(
                "SELECT id, uuid, first_name, last_name, email FROM contacts WHERE {$workspace['sql']} AND email = ?",
                array_merge($workspace['params'], [$email])
            );
            if (!empty($emailMatches)) {
                $duplicates['email'] = $emailMatches;
            }
        }
        
        if (!empty($phone)) {
            $workspace = $this->workspaceClause();
            $phoneMatches = Database::query(
                "SELECT id, uuid, first_name, last_name, phone FROM contacts WHERE {$workspace['sql']} AND phone = ?",
                array_merge($workspace['params'], [$phone])
            );
            if (!empty($phoneMatches)) {
                $duplicates['phone'] = $phoneMatches;
            }
        }
        
        return $duplicates;
    }

    private function resolveAssignedOwner(mixed $assignedTo, ?int $createdBy): ?int
    {
        if ($assignedTo !== null && $assignedTo !== '' && (int) $assignedTo > 0) {
            $resolved = (int) $assignedTo;
            $this->assignmentAccess->assertAssignableUser($resolved);
            return $resolved;
        }

        if ($createdBy !== null && $createdBy > 0) {
            $this->assignmentAccess->assertAssignableUser($createdBy);
            return $createdBy;
        }

        $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
        if ($sessionUserId > 0) {
            $this->assignmentAccess->assertAssignableUser($sessionUserId);
            return $sessionUserId;
        }

        return null;
    }

    private function resolveCompanyLinkData(array $data, ?array $existingContact = null): array
    {
        $companies = new Companies();
        $existingCompanyName = Security::sanitizeInput((string) ($existingContact['company'] ?? ''), 'string');
        $existingCompanyId = !empty($existingContact['company_id']) ? (int) $existingContact['company_id'] : null;

        $hasCompanyInput = array_key_exists('company', $data);
        $hasCompanyIdInput = array_key_exists('company_id', $data);

        $companyInput = $hasCompanyInput ? Security::sanitizeInput((string) ($data['company'] ?? ''), 'string') : $existingCompanyName;
        $companyIdInput = $hasCompanyIdInput ? (!empty($data['company_id']) ? (int) $data['company_id'] : null) : $existingCompanyId;

        if ($hasCompanyInput && $companyInput === '') {
            return [
                'company' => '',
                'company_id' => null,
            ];
        }

        if (!$hasCompanyInput && $hasCompanyIdInput && $companyIdInput) {
            $companyById = $companies->getById($companyIdInput);
            if ($companyById) {
                return [
                    'company' => (string) ($companyById['name'] ?? ''),
                    'company_id' => (int) $companyById['id'],
                ];
            }
        }

        if ($companyInput !== '') {
            if ($companyIdInput) {
                $companyById = $companies->getById($companyIdInput);
                if ($companyById) {
                    $normalizedInput = strtolower(trim((string) preg_replace('/\s+/', ' ', $companyInput)));
                    $normalizedStored = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) ($companyById['name'] ?? ''))));
                    if ($normalizedInput === $normalizedStored) {
                        return [
                            'company' => (string) ($companyById['name'] ?? $companyInput),
                            'company_id' => (int) $companyById['id'],
                        ];
                    }
                }
            }

            $linkedCompany = $companies->findOrCreateByName($companyInput, [
                'website' => $data['company_website'] ?? null,
                'phone' => $data['phone'] ?? null,
                'industry' => $data['company_industry'] ?? null,
                'size' => $data['company_size'] ?? null,
            ]);

            return [
                'company' => (string) ($linkedCompany['name'] ?? $companyInput),
                'company_id' => !empty($linkedCompany['id']) ? (int) $linkedCompany['id'] : null,
            ];
        }

        if ($companyIdInput) {
            $companyById = $companies->getById($companyIdInput);
            if ($companyById) {
                return [
                    'company' => (string) ($companyById['name'] ?? ''),
                    'company_id' => (int) $companyById['id'],
                ];
            }
        }

        return [
            'company' => $existingCompanyName,
            'company_id' => $existingCompanyId,
        ];
    }
    
    /**
     * Log activity
     */
    private function logActivity(int $contactId, string $activityType, array $metadata = []): void
    {
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, user_id, activity_type, description, metadata) 
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $this->workspaceId(),
                $contactId,
                $metadata['by'] ?? null,
                $activityType,
                'Contact ' . $activityType,
                json_encode($metadata)
            ]
        );
    }
    
    /**
     * Bulk update contacts
     */
    public function bulkUpdate(array $contactIds, array $data): int
    {
        if (empty($contactIds)) {
            return 0;
        }
        
        $updates = [];
        $params = [];
        $previousStages = [];
        if (array_key_exists('stage', $data)) {
            $scopePlaceholders = implode(',', array_fill(0, count($contactIds), '?'));
            foreach (Database::query(
                "SELECT id, stage FROM contacts WHERE workspace_id = ? AND id IN ({$scopePlaceholders})",
                array_merge([$this->workspaceId()], array_map('intval', $contactIds))
            ) as $row) {
                $previousStages[(int) $row['id']] = (string) ($row['stage'] ?? '');
            }
        }
        
        $allowedFields = ['stage', 'assigned_to', 'lead_source'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                if ($field === 'stage') {
                    $value = Security::sanitizeInput($data[$field], 'string');
                    if (!in_array($value, self::ALLOWED_STAGES, true)) {
                        throw new \InvalidArgumentException('Invalid contact stage');
                    }
                } elseif ($field === 'lead_source') {
                    $value = $this->normalizeLeadSource($data[$field]);
                } elseif ($field === 'assigned_to') {
                    $value = !empty($data[$field]) ? (int) $data[$field] : null;
                    $this->assignmentAccess->assertAssignableUser($value);
                } else {
                    $value = Security::sanitizeInput($data[$field], 'string');
                }
                $updates[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        if (empty($updates)) {
            return 0;
        }
        
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $params = array_merge($params, [$this->workspaceId()], $contactIds);
        
        $sql = "UPDATE contacts SET " . implode(', ', $updates) . " WHERE workspace_id = ? AND id IN ($placeholders)";
        Database::execute($sql, $params);

        if (array_key_exists('stage', $data)) {
            $newStage = (string) Security::sanitizeInput($data['stage'], 'string');
            $history = new ContactStageHistoryService();
            foreach ($previousStages as $contactId => $oldStage) {
                $history->record(
                    $this->workspaceId(),
                    $contactId,
                    $oldStage,
                    $newStage,
                    !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                    'contact_bulk_update'
                );
            }
        }
        
        // Log audit trail for each contact
        $auditLog = new AuditLog();
        foreach ($contactIds as $contactId) {
            $auditLog->log('bulk_update', 'contact', $contactId, null, $data);
        }
        
        return count($contactIds);
    }
    
    /**
     * Bulk delete contacts
     * 
     * Uses the same safe deletion logic as delete() for each contact
     */
    public function bulkDelete(array $contactIds): int
    {
        if (empty($contactIds)) {
            return 0;
        }
        
        $deleted = 0;
        
        foreach ($contactIds as $contactId) {
            try {
                if ($this->delete((int) $contactId)) {
                    $deleted++;
                }
            } catch (\Throwable $e) {
                // Keep processing the rest; one bad contact should not block the batch.
                error_log("Bulk delete failed for contact {$contactId}: " . $e->getMessage());
            }
        }
        
        return $deleted;
    }
    
    /**
     * Merge contacts (merge source into target)
     */
    public function merge(int $sourceId, int $targetId, array $fieldPreferences = []): bool
    {
        $source = $this->getById($sourceId);
        $target = $this->getById($targetId);
        
        if (!$source || !$target) {
            throw new \Exception("One or both contacts not found");
        }
        
        if ($sourceId === $targetId) {
            throw new \Exception("Cannot merge a contact with itself");
        }
        
        // Merge data - prefer target values unless specified in preferences
        $mergedData = [];
        $fields = [
            'first_name', 'last_name', 'email', 'phone', 'company', 'lead_source', 'stage', 'assigned_to',
            'job_title', 'location', 'company_website', 'company_size', 'company_industry', 'company_description',
            'company_founded', 'company_revenue', 'linkedin_url', 'twitter_url', 'timezone', 'email_verified'
        ];
        
        foreach ($fields as $field) {
            if (isset($fieldPreferences[$field])) {
                $mergedData[$field] = $fieldPreferences[$field] === 'source' ? $source[$field] : $target[$field];
            } else {
                // Default: prefer target, but use source if target is empty
                $mergedData[$field] = !empty($target[$field]) ? $target[$field] : ($source[$field] ?? null);
            }
        }
        
        // Update target with merged data
        $this->update($targetId, $mergedData);
        
        // Move activities from source to target
        Database::execute(
            "UPDATE activities SET contact_id = ? WHERE workspace_id = ? AND contact_id = ?",
            [$targetId, $this->workspaceId(), $sourceId]
        );
        
        // Move notes from source to target
        Database::execute(
            "UPDATE notes SET entity_id = ? WHERE workspace_id = ? AND entity_type = 'contact' AND entity_id = ?",
            [$targetId, $this->workspaceId(), $sourceId]
        );
        
        // Move documents from source to target
        Database::execute(
            "UPDATE documents SET entity_id = ? WHERE workspace_id = ? AND entity_type = 'contact' AND entity_id = ?",
            [$targetId, $this->workspaceId(), $sourceId]
        );
        
        // Move deals from source to target
        Database::execute(
            "UPDATE deals SET contact_id = ? WHERE workspace_id = ? AND contact_id = ?",
            [$targetId, $this->workspaceId(), $sourceId]
        );
        
        // Move tasks from source to target
        Database::execute(
            "UPDATE tasks SET contact_id = ? WHERE workspace_id = ? AND contact_id = ?",
            [$targetId, $this->workspaceId(), $sourceId]
        );
        
        // Move events from source to target
        Database::execute(
            "UPDATE events SET contact_id = ? WHERE contact_id = ?",
            [$targetId, $sourceId]
        );
        
        // Move tags from source to target
        $sourceTags = Database::query(
            "SELECT tag_id FROM tag_assignments WHERE entity_type = 'contact' AND entity_id = ?",
            [$sourceId]
        );
        foreach ($sourceTags as $tag) {
            // Check if target already has this tag
            $exists = Database::queryOne(
                "SELECT id FROM tag_assignments WHERE entity_type = 'contact' AND entity_id = ? AND tag_id = ?",
                [$targetId, $tag['tag_id']]
            );
            if (!$exists) {
                Database::execute(
                    "UPDATE tag_assignments SET entity_id = ? WHERE entity_type = 'contact' AND entity_id = ? AND tag_id = ?",
                    [$targetId, $sourceId, $tag['tag_id']]
                );
            } else {
                // Remove duplicate tag from source
                Database::execute(
                    "DELETE FROM tag_assignments WHERE entity_type = 'contact' AND entity_id = ? AND tag_id = ?",
                    [$sourceId, $tag['tag_id']]
                );
            }
        }
        
        // Log merge in audit trail
        $auditLog = new AuditLog();
        $auditLog->log('merge', 'contact', $targetId, ['source_id' => $sourceId, 'source' => $source], ['merged' => $mergedData]);
        
        // Delete source contact
        $this->delete($sourceId);

        try {
            (new ContactIntelligenceService())->computeAndPersist($targetId);
        } catch (\Throwable $e) {
            error_log('Contacts::merge intelligence refresh failed: ' . $e->getMessage());
        }
        
        return true;
    }
    
    /**
     * Generate UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function hasMetadataJsonColumn(): bool
    {
        if ($this->hasMetadataJsonColumn !== null) {
            return $this->hasMetadataJsonColumn;
        }

        try {
            $row = Database::queryOne(
                "SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'contacts'
                   AND COLUMN_NAME = 'metadata_json'"
            );
            $this->hasMetadataJsonColumn = !empty($row);
        } catch (\Throwable $e) {
            $this->hasMetadataJsonColumn = false;
        }

        return $this->hasMetadataJsonColumn;
    }

    /**
     * @return array{sql:string, params:array<int, int>}
     */
    private function workspaceClause(string $alias = '', string $column = 'workspace_id'): array
    {
        $workspace = $this->workspaceScope->workspaceClause($alias, $column);
        if ($column !== 'workspace_id' || !Database::columnExists('contacts', 'demo_visibility')) {
            return $workspace;
        }

        $demo = $this->demoScope->entityOwnershipClause('contacts', $alias);
        if ($demo['sql'] === '') {
            return $workspace;
        }

        return [
            'sql' => '(' . $workspace['sql'] . ' AND ' . $demo['sql'] . ')',
            'params' => array_merge($workspace['params'], $demo['params']),
        ];
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }

    private function decodeMetadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeMetadata(array $metadata): string
    {
        return json_encode($metadata, JSON_UNESCAPED_SLASHES);
    }

    private function buildManualFieldProvenance(array $data, ?int $actorUserId): array
    {
        $provenance = [];
        foreach ($data as $field => $value) {
            if (!in_array($field, self::STRUCTURED_SOURCE_CONTROLLED_FIELDS, true)) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $provenance[$field] = $this->buildFieldProvenanceEntry(
                'manual',
                'user:' . (string) ($actorUserId ?? 0),
                'Manual entry',
                null
            );
        }

        return $provenance;
    }

    public function buildFieldProvenanceEntry(string $sourceType, string $sourceRef, string $sourceLabel, ?float $confidence = null): array
    {
        $entry = [
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'source_label' => $sourceLabel,
            'updated_at' => date('c'),
        ];

        if ($confidence !== null) {
            $entry['confidence'] = round($confidence, 4);
        }

        return $entry;
    }

    private function mergeFieldProvenance(array $existing, array $updates): array
    {
        foreach ($updates as $field => $value) {
            if ($value === null) {
                unset($existing[$field]);
                continue;
            }
            $existing[$field] = $value;
        }

        return $existing;
    }
}
