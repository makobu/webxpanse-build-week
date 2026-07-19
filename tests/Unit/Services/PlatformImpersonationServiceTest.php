<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Services\PlatformImpersonationService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class PlatformImpersonationServiceTest extends DatabaseTestCase
{
    public function testBeginAndEndImpersonationSwitchesSessionAndRestoresOriginalContext(): void
    {
        $platform = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Platform Ops Workspace',
            'workspace_slug' => 'platform-ops-workspace',
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'email' => 'platform.admin@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $target = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Target Tenant Workspace',
            'workspace_slug' => 'target-tenant-workspace',
            'first_name' => 'Tenant',
            'last_name' => 'Owner',
            'email' => 'tenant.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        Session::set('user_id', (int) ($platform['user_id'] ?? 0));
        Session::set('user_uuid', 'platform-uuid');
        Session::set('user_email', 'platform.admin@example.com');
        Session::set('user_role', 'admin');
        $superAdminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        Authorization::assignUserRole((int) ($platform['user_id'] ?? 0), (int) ($superAdminRole['id'] ?? 0), (int) ($platform['user_id'] ?? 0));
        WorkspaceContext::activateForUser((int) ($platform['user_id'] ?? 0));

        $service = new PlatformImpersonationService();
        $actor = Auth::user();
        $start = $service->begin($actor ?? [], (int) ($target['workspace_id'] ?? 0), null, 'Investigating tenant billing state');

        $this->assertTrue($service->isActive());
        $this->assertSame((int) ($target['user_id'] ?? 0), (int) Session::get('user_id'));
        $this->assertSame((int) ($target['workspace_id'] ?? 0), (int) (WorkspaceContext::currentWorkspaceId() ?? 0));
        $this->assertSame((int) ($target['user_id'] ?? 0), (int) ($start['context']['impersonated_user_id'] ?? 0));

        $restored = $service->end('Support session completed');

        $this->assertNotNull($restored);
        $this->assertFalse($service->isActive());
        $this->assertSame((int) ($platform['user_id'] ?? 0), (int) Session::get('user_id'));
        $this->assertSame((int) ($platform['workspace_id'] ?? 0), (int) (WorkspaceContext::currentWorkspaceId() ?? 0));
    }
}
