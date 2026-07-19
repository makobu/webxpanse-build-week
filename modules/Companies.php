<?php
/**
 * Companies Module
 *
 * Manages companies/organizations for B2B CRM
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Concurrency;
use CRM\Security;
use CRM\Auth;
use CRM\Services\ContactAssignmentAccessService;
use CRM\Services\ThirdPartyEnrichmentService;
use CRM\Services\WorkspaceScopeService;

class Companies
{
    private ?bool $hasPrimaryContactColumn = null;
    private WorkspaceScopeService $workspaceScope;
    private ContactAssignmentAccessService $assignmentAccess;

    private const IMPORT_FIELD_ALIASES = [
        'name' => ['name', 'company', 'company name', 'organization', 'organization name'],
        'website' => ['website', 'company website', 'url', 'web'],
        'phone' => ['phone', 'phone number', 'telephone'],
        'industry' => ['industry', 'sector'],
        'size' => ['size', 'company size', 'employees'],
        'address' => ['address', 'company address', 'location'],
        'assigned_to' => ['assigned_to', 'assigned to', 'owner', 'assignee', 'assigned user', 'assigned email'],
    ];

    private const EXPORT_COLUMNS = [
        'id' => 'ID',
        'uuid' => 'UUID',
        'name' => 'Name',
        'website' => 'Website',
        'phone' => 'Phone',
        'industry' => 'Industry',
        'size' => 'Size',
        'address' => 'Address',
        'assigned_to' => 'Assigned To ID',
        'assigned_to_email' => 'Assigned To Email',
        'created_at' => 'Created At',
        'updated_at' => 'Updated At',
    ];

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
        $this->assignmentAccess = new ContactAssignmentAccessService($this->workspaceScope);
    }

    public function list(int $limit = 50, int $offset = 0, ?string $search = null): array
    {
        $workspace = $this->workspaceClause('c.');
        $sql = $this->buildBaseCompanySelect();
        if ($search) {
            $term = '%' . Security::sanitizeInput($search, 'string') . '%';
            return Database::query(
                $sql . "
                 WHERE {$workspace['sql']}
                   AND (c.name LIKE ? OR c.industry LIKE ?)
                 ORDER BY c.name ASC
                 LIMIT ? OFFSET ?",
                array_merge($workspace['params'], [$term, $term, $limit, $offset])
            );
        }
        return Database::query(
            $sql . "
             WHERE {$workspace['sql']}
             ORDER BY c.name ASC
             LIMIT ? OFFSET ?",
            array_merge($workspace['params'], [$limit, $offset])
        );
    }

    public function getById(int $id): ?array
    {
        $workspace = $this->workspaceClause('c.');
        $company = Database::queryOne(
            $this->buildBaseCompanySelect() . "
             WHERE {$workspace['sql']}
               AND c.id = ?",
            array_merge($workspace['params'], [$id])
        );
        return $company ?: null;
    }

    public function search(string $term, int $limit = 20): array
    {
        $t = '%' . Security::sanitizeInput($term, 'string') . '%';
        $workspace = $this->workspaceClause();
        return Database::query(
            "SELECT id, name, industry FROM companies WHERE {$workspace['sql']} AND (name LIKE ? OR industry LIKE ?) ORDER BY name LIMIT ?",
            array_merge($workspace['params'], [$t, $t, $limit])
        );
    }

    public function findByName(string $name): ?array
    {
        $sanitized = Security::sanitizeInput($name, 'string');
        if ($sanitized === '') {
            return null;
        }

        return $this->findByNormalizedName($sanitized);
    }

    public function findOrCreateByName(string $name, array $defaults = []): ?array
    {
        $normalizedName = Security::sanitizeInput($name, 'string');
        if ($normalizedName === '') {
            return null;
        }

        $existing = $this->findByNormalizedName($normalizedName);
        if ($existing) {
            return $existing;
        }

        $payload = array_merge($defaults, ['name' => $normalizedName]);
        $id = $this->create($payload);
        return $this->getById($id);
    }

    public function create(array $data): int
    {
        $workspaceId = $this->workspaceId();
        $uuid = $this->generateUuid();
        $prepared = $this->prepareCompanyData($data);
        $name = $prepared['name'];
        $website = $prepared['website'];
        $phone = $prepared['phone'];
        $address = $prepared['address'];
        $industry = $prepared['industry'];
        $size = $prepared['size'];
        $assignedTo = $prepared['assigned_to'];
        $primaryContactId = $prepared['primary_contact_id'];

        if (empty($name)) {
            throw new \InvalidArgumentException('Company name is required');
        }

        if ($this->hasPrimaryContactColumn()) {
            if ($primaryContactId !== null) {
                throw new \InvalidArgumentException('Primary contact can only be selected after the contact is linked to the company.');
            }
            Database::execute(
                "INSERT INTO companies (workspace_id, uuid, name, website, phone, address, industry, size, assigned_to, primary_contact_id) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$workspaceId, $uuid, $name, $website ?: null, $phone ?: null, $address ?: null, $industry ?: null, $size ?: null, $assignedTo, $primaryContactId]
            );
        } else {
            Database::execute(
                "INSERT INTO companies (workspace_id, uuid, name, website, phone, address, industry, size, assigned_to) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$workspaceId, $uuid, $name, $website ?: null, $phone ?: null, $address ?: null, $industry ?: null, $size ?: null, $assignedTo]
            );
        }

        return (int) Database::lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $company = $this->getById($id);
        if (!$company) {
            return false;
        }

        $updates = [];
        $params = [];
        $submittedFields = [];

        $prepared = $this->prepareCompanyData($data, false);
        if (array_key_exists('name', $data) && $prepared['name'] === '') {
            throw new \InvalidArgumentException('Company name is required');
        }

        $fields = ['name', 'website', 'phone', 'address', 'industry', 'size', 'assigned_to'];
        if ($this->hasPrimaryContactColumn()) {
            $fields[] = 'primary_contact_id';
        }
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                if ($f === 'primary_contact_id' && !$this->isValidPrimaryContact($id, $prepared[$f])) {
                    throw new \InvalidArgumentException('Primary contact must be linked to this company');
                }
                $updates[] = "{$f} = ?";
                $params[] = $prepared[$f];
                $submittedFields[$f] = $prepared[$f];
            }
        }

        if (empty($updates)) {
            return true;
        }

        $workspaceId = $this->workspaceId();
        Concurrency::executeWorkspaceUpdate(
            'companies',
            $workspaceId,
            $id,
            $updates,
            $params,
            Concurrency::expectedVersionFromData($data),
            fn(): ?array => $this->getById($id),
            $submittedFields,
            'company'
        );

        return true;
    }

    public function delete(int $id): bool
    {
        $workspaceId = $this->workspaceId();
        $company = $this->getById($id);
        if (!$company) {
            return false;
        }

        Database::beginTransaction();
        try {
            if ($this->hasPrimaryContactColumn()) {
                Database::execute(
                    "UPDATE companies SET primary_contact_id = NULL WHERE workspace_id = ? AND id = ?",
                    [$workspaceId, $id]
                );
            }

            Database::execute(
                "UPDATE contacts SET company_id = NULL WHERE workspace_id = ? AND company_id = ?",
                [$workspaceId, $id]
            );
            Database::execute(
                "UPDATE deals SET company_id = NULL WHERE workspace_id = ? AND company_id = ?",
                [$workspaceId, $id]
            );
            Database::execute(
                "DELETE FROM companies WHERE workspace_id = ? AND id = ?",
                [$workspaceId, $id]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return true;
    }

    public function getContactCount(int $companyId): int
    {
        $workspace = $this->workspaceClause();
        $r = Database::queryOne("SELECT COUNT(*) as c FROM contacts WHERE {$workspace['sql']} AND company_id = ?", array_merge($workspace['params'], [$companyId]));
        return (int) ($r['c'] ?? 0);
    }

    public function getDealCount(int $companyId): int
    {
        $workspace = $this->workspaceClause();
        $r = Database::queryOne("SELECT COUNT(*) as c FROM deals WHERE {$workspace['sql']} AND company_id = ?", array_merge($workspace['params'], [$companyId]));
        return (int) ($r['c'] ?? 0);
    }

    public function getImportFieldAliases(): array
    {
        return self::IMPORT_FIELD_ALIASES;
    }

    public function getExportColumns(): array
    {
        return self::EXPORT_COLUMNS;
    }

    public function mapImportHeaders(array $headers): array
    {
        $normalizedHeaders = array_map([$this, 'normalizeHeader'], $headers);
        $columnMap = [];

        foreach (self::IMPORT_FIELD_ALIASES as $field => $aliases) {
            $aliasMap = array_map([$this, 'normalizeHeader'], $aliases);
            foreach ($normalizedHeaders as $index => $header) {
                if (in_array($header, $aliasMap, true)) {
                    $columnMap[$field] = $index;
                    break;
                }
            }
        }

        return $columnMap;
    }

    public function parseImportRow(array $row, array $columnMap): array
    {
        $parsed = [];
        foreach ($columnMap as $field => $index) {
            if (!array_key_exists($index, $row)) {
                continue;
            }

            $value = trim((string) $row[$index]);
            if ($value === '') {
                continue;
            }

            $parsed[$field] = $value;
        }

        return $parsed;
    }

    public function importRows(array $rows, array $columnMap): array
    {
        $results = [
            'total' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($rows as $rowNumber => $row) {
            $results['total']++;

            if (empty(array_filter($row, static fn($value) => trim((string) $value) !== ''))) {
                $results['skipped']++;
                continue;
            }

            $rawData = $this->parseImportRow($row, $columnMap);

            try {
                $prepared = $this->prepareCompanyData($rawData);
                if ($prepared['name'] === '') {
                    throw new \InvalidArgumentException('Company name is required');
                }

                $existing = $this->findByNormalizedName($prepared['name']);
                if ($existing) {
                    $updateData = [];
                    foreach (['name', 'website', 'phone', 'industry', 'size', 'address', 'assigned_to'] as $field) {
                        if (array_key_exists($field, $rawData)) {
                            $updateData[$field] = $prepared[$field];
                        }
                    }
                    $this->update((int) $existing['id'], $updateData);
                    $results['updated']++;
                } else {
                    $this->create($prepared);
                    $results['imported']++;
                }
            } catch (\InvalidArgumentException $e) {
                $results['skipped']++;
                $results['errors'][] = 'Row ' . $rowNumber . ': ' . $e->getMessage();
            } catch (\Throwable $e) {
                $results['skipped']++;
                error_log('Company import row ' . $rowNumber . ' failed: ' . $e->getMessage());
                $results['errors'][] = 'Row ' . $rowNumber . ': Company could not be saved.';
            }
        }

        return $results;
    }

    public function export(array $filters = []): array
    {
        $workspace = $this->workspaceClause('c.');
        $where = [$workspace['sql']];
        $params = $workspace['params'];

        if (!empty($filters['search'])) {
            $searchTerm = '%' . Security::sanitizeInput((string) $filters['search'], 'string') . '%';
            $where[] = '(c.name LIKE ? OR c.industry LIKE ? OR c.website LIKE ?)';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (array_key_exists('assigned_to', $filters) && $filters['assigned_to'] !== '' && $filters['assigned_to'] !== null) {
            $where[] = 'c.assigned_to = ?';
            $params[] = (int) $filters['assigned_to'];
        }

        $sql = "SELECT c.id, c.uuid, c.name, c.website, c.phone, c.industry, c.size, c.address, c.assigned_to, ";
        if ($this->hasPrimaryContactColumn()) {
            $sql .= "c.primary_contact_id, ";
        } else {
            $sql .= "NULL AS primary_contact_id, ";
        }
        $sql .= "u.email AS assigned_to_email, c.created_at, c.updated_at
                 FROM companies c
                 LEFT JOIN users u ON c.assigned_to = u.id";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY c.name ASC';

        return Database::query($sql, $params);
    }

    public function getAssignableUsers(): array
    {
        return Database::query("SELECT id, email FROM users ORDER BY email ASC");
    }

    public function getLinkedContacts(int $companyId): array
    {
        $workspace = $this->workspaceClause();
        return Database::query(
            "SELECT id, first_name, last_name, email, phone, stage
             FROM contacts
             WHERE {$workspace['sql']}
               AND company_id = ?
             ORDER BY
                CASE WHEN id = (SELECT primary_contact_id FROM companies WHERE workspace_id = ? AND id = ?) THEN 0 ELSE 1 END,
                last_name ASC,
                first_name ASC",
            array_merge($workspace['params'], [$companyId, $this->workspaceId(), $companyId])
        );
    }

    public function assignPrimaryContact(int $companyId, ?int $contactId): bool
    {
        if (!$this->hasPrimaryContactColumn()) {
            return false;
        }

        if (!$this->getById($companyId)) {
            return false;
        }

        if (!$this->isValidPrimaryContact($companyId, $contactId)) {
            throw new \InvalidArgumentException('Primary contact must be linked to this company');
        }

        Database::execute(
            "UPDATE companies SET primary_contact_id = ? WHERE workspace_id = ? AND id = ?",
            [$contactId, $this->workspaceId(), $companyId]
        );

        return true;
    }

    public function syncPrimaryContactForContactChange(int $contactId, ?int $newCompanyId): void
    {
        if (!$this->hasPrimaryContactColumn()) {
            return;
        }

        Database::execute(
            "UPDATE companies
             SET primary_contact_id = NULL
             WHERE workspace_id = ?
               AND primary_contact_id = ?
               AND (id <> ? OR ? IS NULL)",
            [$this->workspaceId(), $contactId, $newCompanyId ?? 0, $newCompanyId]
        );
    }

    public function enrichCompanyAndDiscoverContacts(int $companyId): array
    {
        $company = $this->getById($companyId);
        if (!$company) {
            throw new \RuntimeException('Company not found');
        }

        $service = new ThirdPartyEnrichmentService();
        $contactsModule = new Contacts();

        $domainSeed = (string) ($company['website'] ?? '') !== '' ? (string) $company['website'] : (string) ($company['name'] ?? '');
        $enrichment = $service->enrichWithHunter(null, null, null, (string) ($company['name'] ?? ''), $domainSeed);

        if (($enrichment['status'] ?? 'error') !== 'success' || empty($enrichment['data'])) {
            return [
                'status' => 'success',
                'company_updated' => false,
                'contacts_created' => 0,
                'contacts_linked' => 0,
                'primary_contact_set' => false,
                'message' => 'No provider enrichment results were available for this company.',
                'provider_response' => $enrichment,
            ];
        }

        $data = is_array($enrichment['data']) ? $enrichment['data'] : [];

        $companyUpdates = [];
        if (!empty($data['company']) && Security::sanitizeInput((string) $data['company'], 'string') !== '') {
            $companyUpdates['name'] = Security::sanitizeInput((string) $data['company'], 'string');
        }
        if (!empty($data['company_website'])) {
            $companyUpdates['website'] = Security::sanitizeInput((string) $data['company_website'], 'string');
        }
        if (!empty($data['phone']) && empty($company['phone'])) {
            $companyUpdates['phone'] = is_array($data['phone']) ? implode(', ', array_filter(array_map('strval', $data['phone']))) : (string) $data['phone'];
        }
        if (!empty($data['company_industry'])) {
            $companyUpdates['industry'] = is_array($data['company_industry']) ? implode(', ', array_filter(array_map('strval', $data['company_industry']))) : (string) $data['company_industry'];
        }
        if (!empty($data['company_size'])) {
            $companyUpdates['size'] = is_scalar($data['company_size']) ? (string) $data['company_size'] : json_encode($data['company_size']);
        }

        $companyUpdated = false;
        if (!empty($companyUpdates)) {
            $this->update($companyId, $companyUpdates);
            $companyUpdated = true;
            $company = $this->getById($companyId) ?? $company;
        }

        $createdCount = 0;
        $linkedCount = 0;
        $primaryContactSet = false;
        $createdContactIds = [];

        $emails = is_array($data['emails'] ?? null) ? $data['emails'] : [];
        foreach ($emails as $candidate) {
            $email = trim((string) ($candidate['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $candidatePayload = [
                'first_name' => trim((string) ($candidate['first_name'] ?? '')) !== '' ? (string) $candidate['first_name'] : 'Discovered',
                'last_name' => (string) ($candidate['last_name'] ?? ''),
                'email' => $email,
                'phone' => (string) ($candidate['phone'] ?? ''),
                'company' => (string) ($company['name'] ?? ''),
                'company_id' => $companyId,
                'job_title' => (string) ($candidate['job_title'] ?? ''),
                'linkedin_url' => (string) ($candidate['linkedin_url'] ?? ''),
                'twitter_url' => (string) ($candidate['twitter_url'] ?? ''),
                'company_website' => (string) ($company['website'] ?? ''),
                'company_industry' => (string) ($company['industry'] ?? ''),
                'lead_source' => 'company_enrichment',
            ];

            $createResult = $contactsModule->create($candidatePayload);
            if (($createResult['status'] ?? '') === 'success') {
                $contactId = (int) ($createResult['id'] ?? 0);
                $createdCount++;
                $createdContactIds[] = $contactId;
                $linkedCount++;

                $provenance = [];
                foreach (['job_title', 'linkedin_url', 'twitter_url', 'company_website'] as $field) {
                    if (!empty($candidatePayload[$field])) {
                        $provenance[$field] = $contactsModule->buildFieldProvenanceEntry(
                            'api_verified',
                            'company_enrichment:' . $companyId,
                            'Company enrichment provider',
                            isset($candidate['confidence_score']) ? (float) $candidate['confidence_score'] / 100 : null
                        );
                    }
                }
                if (!empty($provenance)) {
                    $contactsModule->saveFieldProvenance($contactId, $provenance);
                }
            } elseif (($createResult['status'] ?? '') === 'duplicate') {
                $duplicateId = $this->resolveDuplicateContactId($createResult['matches'] ?? []);
                if ($duplicateId > 0) {
                    $existingContact = $contactsModule->getById($duplicateId);
                    if ($existingContact) {
                        $contactsModule->update($duplicateId, [
                            'company' => (string) ($company['name'] ?? ''),
                            'company_id' => $companyId,
                        ]);
                        $linkedCount++;
                    }
                }
            }
        }

        if ($this->hasPrimaryContactColumn() && empty($company['primary_contact_id'])) {
            $primaryCandidateId = $createdContactIds[0] ?? null;
            if ($primaryCandidateId) {
                $this->assignPrimaryContact($companyId, $primaryCandidateId);
                $primaryContactSet = true;
            }
        }

        return [
            'status' => 'success',
            'company_updated' => $companyUpdated,
            'contacts_created' => $createdCount,
            'contacts_linked' => $linkedCount,
            'primary_contact_set' => $primaryContactSet,
            'message' => $createdCount > 0 || $linkedCount > 0
                ? 'Company enrichment completed and contacts were linked.'
                : 'Provider returned company details, but no contact candidates were created.',
            'provider_response' => $enrichment,
        ];
    }

    public function resolveAssignedTo($value): ?int
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            $user = Database::queryOne("SELECT id FROM users WHERE email = ?", [$trimmed]);
            if ($user) {
                $userId = (int) $user['id'];
                try {
                    $this->assignmentAccess->assertAssignableUser($userId);
                } catch (\RuntimeException $e) {
                    throw new \InvalidArgumentException('The selected user is not a member of the active workspace.');
                }
                return $userId;
            }
        }

        if (ctype_digit($trimmed)) {
            $user = Database::queryOne("SELECT id FROM users WHERE id = ?", [(int) $trimmed]);
            if ($user) {
                $userId = (int) $user['id'];
                try {
                    $this->assignmentAccess->assertAssignableUser($userId);
                } catch (\RuntimeException $e) {
                    throw new \InvalidArgumentException('The selected user is not a member of the active workspace.');
                }
                return $userId;
            }
        }

        throw new \InvalidArgumentException('Assigned user not found: ' . $trimmed);
    }

    private function prepareCompanyData(array $data, bool $requireName = true): array
    {
        $name = array_key_exists('name', $data) ? Security::sanitizeInput((string) $data['name'], 'string') : null;
        $website = array_key_exists('website', $data) ? $this->normalizeWebsite($data['website']) : null;
        $phone = array_key_exists('phone', $data) ? (Security::sanitizeInput((string) $data['phone'], 'string') ?: null) : null;
        $address = array_key_exists('address', $data) ? (Security::sanitizeInput((string) $data['address'], 'string') ?: null) : null;
        $industry = array_key_exists('industry', $data) ? (Security::sanitizeInput((string) $data['industry'], 'string') ?: null) : null;
        $size = array_key_exists('size', $data) ? (Security::sanitizeInput((string) $data['size'], 'string') ?: null) : null;

        $prepared = [
            'name' => $name ?? '',
            'website' => $website,
            'phone' => $phone,
            'address' => $address,
            'industry' => $industry,
            'size' => $size,
            'assigned_to' => null,
            'primary_contact_id' => null,
        ];

        if (array_key_exists('assigned_to', $data)) {
            $prepared['assigned_to'] = $this->resolveAssignedTo($data['assigned_to']);
        }
        if (array_key_exists('primary_contact_id', $data)) {
            $prepared['primary_contact_id'] = !empty($data['primary_contact_id']) ? (int) $data['primary_contact_id'] : null;
        }

        if ($requireName && $prepared['name'] === '') {
            throw new \InvalidArgumentException('Company name is required');
        }

        return $prepared;
    }

    private function normalizeWebsite($value): ?string
    {
        $website = trim((string) ($value ?? ''));
        if ($website === '') {
            return null;
        }

        if (str_contains($website, "\r") || str_contains($website, "\n") || str_contains($website, "\0") || str_starts_with($website, '//')) {
            throw new \InvalidArgumentException('Company website URL is invalid.');
        }

        $validated = filter_var($website, FILTER_VALIDATE_URL);
        $scheme = strtolower((string) parse_url($website, PHP_URL_SCHEME));
        if ($validated === false || !in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Company website must use HTTP or HTTPS.');
        }

        return $website;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        return preg_replace('/[\s_\-]+/', ' ', $header) ?? $header;
    }

    private function normalizeCompanyName(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    }

    private function findByNormalizedName(string $name): ?array
    {
        $normalized = $this->normalizeCompanyName($name);
        $workspace = $this->workspaceClause();
        $companies = Database::query("SELECT * FROM companies WHERE {$workspace['sql']}", $workspace['params']);

        foreach ($companies as $company) {
            if ($this->normalizeCompanyName((string) ($company['name'] ?? '')) === $normalized) {
                return $company;
            }
        }

        return null;
    }

    private function isValidPrimaryContact(int $companyId, ?int $contactId): bool
    {
        if (!$this->hasPrimaryContactColumn()) {
            return true;
        }

        if ($contactId === null) {
            return true;
        }

        $contact = Database::queryOne(
            "SELECT id FROM contacts WHERE workspace_id = ? AND id = ? AND company_id = ?",
            [$this->workspaceId(), $contactId, $companyId]
        );

        return !empty($contact);
    }

    private function resolveDuplicateContactId(array $matches): int
    {
        foreach (['email', 'phone'] as $key) {
            if (!empty($matches[$key][0]['id'])) {
                return (int) $matches[$key][0]['id'];
            }
        }

        return 0;
    }

    private function hasPrimaryContactColumn(): bool
    {
        if ($this->hasPrimaryContactColumn !== null) {
            return $this->hasPrimaryContactColumn;
        }

        try {
            $row = Database::queryOne(
                "SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'companies'
                   AND COLUMN_NAME = 'primary_contact_id'"
            );
            $this->hasPrimaryContactColumn = !empty($row);
        } catch (\Throwable $e) {
            $this->hasPrimaryContactColumn = false;
        }

        return $this->hasPrimaryContactColumn;
    }

    private function buildBaseCompanySelect(): string
    {
        $sql = "SELECT c.*, u.email as assigned_to_email, ";
        if ($this->hasPrimaryContactColumn()) {
            $sql .= "pc.first_name AS primary_contact_first_name,
                     pc.last_name AS primary_contact_last_name,
                     pc.email AS primary_contact_email ";
        } else {
            $sql .= "NULL AS primary_contact_first_name,
                     NULL AS primary_contact_last_name,
                     NULL AS primary_contact_email ";
        }
        $sql .= "FROM companies c
                 LEFT JOIN users u ON c.assigned_to = u.id ";
        if ($this->hasPrimaryContactColumn()) {
            $sql .= "LEFT JOIN contacts pc ON c.primary_contact_id = pc.id AND pc.workspace_id = c.workspace_id ";
        }

        return $sql;
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

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
