<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\PlatformLegalIdentityService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class PlatformLegalIdentityServiceTest extends DatabaseTestCase
{
    public function testSystemLegalNameUsesDefaultWorkspaceProfileInsteadOfActiveWorkspace(): void
    {
        $this->saveDefaultProfile('Default Platform Legal Ltd', 'Default Platform');

        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Tenant Legal Identity Workspace',
            'workspace_slug' => 'tenant-legal-identity-workspace',
            'first_name' => 'Tenant',
            'last_name' => 'Owner',
            'email' => 'tenant.legal.identity@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $tenantWorkspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        WorkspaceContext::activateRuntimeWorkspace($tenantWorkspaceId, (int) ($provisioned['user_id'] ?? 0), 'owner');

        Database::execute(
            "INSERT INTO company_profile (workspace_id, company_name, company_legal_name, is_active)
             VALUES (?, 'Tenant Display Name', 'Tenant Legal Ltd', 1)",
            [$tenantWorkspaceId]
        );

        $this->assertSame('Default Platform Legal Ltd', (new PlatformLegalIdentityService())->systemLegalName());
    }

    public function testSystemLegalNameFallsBackToDefaultCompanyNameAndIgnoresPlaceholder(): void
    {
        $this->saveDefaultProfile('Your Company Name', 'Default Platform Display');

        $this->assertSame('Default Platform Display', (new PlatformLegalIdentityService())->systemLegalName());

        $this->saveDefaultProfile('', 'Your Company Name');

        $this->assertSame('', (new PlatformLegalIdentityService())->systemLegalName());
    }

    private function saveDefaultProfile(string $legalName, string $companyName): void
    {
        $profile = Database::queryOne(
            "SELECT id
             FROM company_profile
             WHERE workspace_id = 1
               AND is_active = 1
             ORDER BY id ASC
             LIMIT 1"
        );

        if ($profile) {
            Database::execute(
                "UPDATE company_profile
                 SET company_legal_name = ?,
                     company_name = ?,
                     is_active = 1
                 WHERE id = ?",
                [$legalName, $companyName, (int) $profile['id']]
            );
            return;
        }

        Database::execute(
            "INSERT INTO company_profile (workspace_id, company_name, company_legal_name, is_active)
             VALUES (1, ?, ?, 1)",
            [$companyName, $legalName]
        );
    }
}
