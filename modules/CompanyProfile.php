<?php
/**
 * Company Profile Module
 * 
 * Manages company profile information
 */

namespace CRM\Modules;

use CRM\Concurrency;
use CRM\Database;
use CRM\Services\WorkspaceScopeService;

class CompanyProfile
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
    }

    /**
     * Get active company profile
     */
    public function get(): ?array
    {
        $workspace = $this->workspaceScope->workspaceClause();
        return Database::queryOne(
            "SELECT * FROM company_profile WHERE {$workspace['sql']} AND is_active = TRUE LIMIT 1",
            $workspace['params']
        );
    }
    
    /**
     * Update company profile (creates if doesn't exist)
     */
    public function update(array $data): bool
    {
        $profile = $this->get();
        if (!$profile) {
            return $this->create($data);
        }
        
        $allowedFields = [
            'company_name', 'company_legal_name', 'company_tax_id', 'company_tagline', 'company_description',
            'company_mission', 'company_values', 'owner_company_context', 'company_website',
            'company_email', 'company_phone', 'company_address',
            'company_location', 'company_timezone', 'company_industry',
            'company_founded', 'company_size', 'company_logo_url',
            'social_linkedin', 'social_twitter', 'social_facebook',
            'icp_job_titles', 'icp_industries', 'icp_pain_points', 'icp_channels'
        ];
        
        $updates = [];
        $params = [];
        $submittedFields = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "{$field} = ?";
                $params[] = $data[$field] ?: null;
                $submittedFields[$field] = $data[$field] ?: null;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        Concurrency::executeWorkspaceUpdate(
            'company_profile',
            $workspaceId,
            (int) $profile['id'],
            $updates,
            $params,
            Concurrency::expectedVersionFromData($data),
            fn(): ?array => $this->get(),
            $submittedFields,
            'company profile'
        );
        
        return true;
    }
    
    /**
     * Create new company profile
     */
    public function create(array $data): bool
    {
        $allowedFields = [
            'company_name', 'company_legal_name', 'company_tax_id', 'company_tagline', 'company_description',
            'company_mission', 'company_values', 'owner_company_context', 'company_website',
            'company_email', 'company_phone', 'company_address',
            'company_location', 'company_timezone', 'company_industry',
            'company_founded', 'company_size', 'company_logo_url',
            'social_linkedin', 'social_twitter', 'social_facebook',
            'icp_job_titles', 'icp_industries', 'icp_pain_points', 'icp_channels'
        ];
        
        $fields = [];
        $values = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = $field;
                $values[] = '?';
                $params[] = $data[$field] ?: null;
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        array_unshift($fields, 'workspace_id');
        array_unshift($values, '?');
        array_unshift($params, $this->workspaceScope->requireActiveWorkspaceId());

        Database::execute(
            "INSERT INTO company_profile (" . implode(', ', $fields) . ", is_active) 
             VALUES (" . implode(', ', $values) . ", TRUE)",
            $params
        );
        
        return true;
    }
}
