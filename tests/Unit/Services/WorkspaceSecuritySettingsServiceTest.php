<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceSecuritySettingsService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceSecuritySettingsServiceTest extends DatabaseTestCase
{
    public function testDefaultsToNotRequiringWorkspaceMember2FA(): void
    {
        $service = new WorkspaceSecuritySettingsService();

        $settings = $service->getSettings(1);

        $this->assertFalse($settings['require_member_2fa']);
        $this->assertFalse($service->requiresMember2FA(1));
    }

    public function testCanToggleWorkspaceMember2FARequirement(): void
    {
        $service = new WorkspaceSecuritySettingsService();

        $service->setRequireMember2FA(1, true, 0);

        $row = Database::queryOne("SELECT require_member_2fa, updated_by_user_id FROM workspace_security_settings WHERE workspace_id = 1");
        $this->assertSame(1, (int) ($row['require_member_2fa'] ?? 0));
        $this->assertNull($row['updated_by_user_id'] ?? null);
        $this->assertTrue($service->requiresMember2FA(1));

        $service->setRequireMember2FA(1, false, 0);

        $this->assertFalse($service->requiresMember2FA(1));
    }

    public function testMemberAccessRequires2FAOnlyWhenWorkspacePolicyIsEnabled(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Workspace 2FA Policy Test',
            'workspace_slug' => 'workspace-2fa-policy-test',
            'first_name' => 'Policy',
            'last_name' => 'Owner',
            'email' => 'workspace.2fa.policy@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $service = new WorkspaceSecuritySettingsService();

        $this->assertTrue($service->canUserAccessWorkspace($workspaceId, $userId));

        $service->setRequireMember2FA($workspaceId, true, $userId);

        $this->assertFalse($service->canUserAccessWorkspace($workspaceId, $userId));

        Database::execute(
            "UPDATE users SET two_factor_enabled = 1, two_factor_secret = ? WHERE id = ?",
            ['JBSWY3DPEHPK3PXP', $userId]
        );

        $this->assertTrue($service->canUserAccessWorkspace($workspaceId, $userId));
    }
}
