<?php

namespace CRM\Services;

use CRM\Database;

class PlatformLegalIdentityService
{
    public function systemLegalName(): string
    {
        try {
            if (!Database::tableExists('workspaces') || !Database::tableExists('company_profile')) {
                return '';
            }

            $legalNameSelect = Database::columnExists('company_profile', 'company_legal_name')
                ? 'cp.company_legal_name'
                : "''";

            $profile = Database::queryOne(
                "SELECT {$legalNameSelect} AS company_legal_name, cp.company_name
                 FROM company_profile cp
                 INNER JOIN workspaces w ON w.id = cp.workspace_id
                 WHERE cp.workspace_id = ?
                   AND w.slug = ?
                   AND cp.is_active = TRUE
                 LIMIT 1",
                [DefaultWorkspaceService::DEFAULT_ID, DefaultWorkspaceService::DEFAULT_SLUG]
            );
        } catch (\Throwable $e) {
            return '';
        }

        if (!$profile) {
            return '';
        }

        $legalName = $this->usefulName($profile['company_legal_name'] ?? '');
        if ($legalName !== '') {
            return $legalName;
        }

        return $this->usefulName($profile['company_name'] ?? '');
    }

    private function usefulName(mixed $value): string
    {
        $name = trim((string) $value);
        if ($name === '' || strcasecmp($name, 'Your Company Name') === 0) {
            return '';
        }

        return $name;
    }
}
