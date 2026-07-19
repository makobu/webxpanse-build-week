<?php

namespace CRM\Tests\Integration;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Services\PlatformSecuritySettingsService;
use CRM\Services\WorkspaceSecuritySettingsService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;
use PragmaRX\Google2FA\Google2FA;

class SuperAdminWorkspaceAccessTest extends DatabaseTestCase
{
    use EndpointHarness;

    private int $superAdminUserId;
    private int $workspaceId;
    private int $membershipId;
    private string $workspaceUuid;
    private string $workspaceSlug;
    private string $workspaceName;

    protected function setUp(): void
    {
        parent::setUp();

        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Super Admin Directory Workspace',
            'workspace_slug' => 'super-admin-directory-workspace',
            'first_name' => 'Directory',
            'last_name' => 'Owner',
            'email' => 'directory.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $this->workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $this->superAdminUserId = (int) ($provisioned['user_id'] ?? 0);
        Database::execute("UPDATE users SET role = 'admin' WHERE id = ?", [$this->superAdminUserId]);
        $this->assignGlobalRole($this->superAdminUserId, 'superadmin');

        $workspace = Database::queryOne("SELECT uuid, slug, name FROM workspaces WHERE id = ?", [$this->workspaceId]) ?? [];
        $membership = Database::queryOne(
            "SELECT id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
             LIMIT 1",
            [$this->workspaceId, $this->superAdminUserId]
        ) ?? [];

        $this->workspaceUuid = (string) ($workspace['uuid'] ?? '');
        $this->workspaceSlug = (string) ($workspace['slug'] ?? '');
        $this->workspaceName = (string) ($workspace['name'] ?? '');
        $this->membershipId = (int) ($membership['id'] ?? 0);
    }

    public function testWorkspacesPageRendersSearchReportingColumnsAndLoginAction(): void
    {
        $response = $this->runWebEndpoint('public/workspaces.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['q' => 'directory.owner@example.com'],
        ]);

        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Platform Workspace 2FA', $body);
        $this->assertStringContainsString('Search', $body);
        $this->assertStringContainsString('Owner', $body);
        $this->assertStringContainsString('Available', $body);
        $this->assertStringContainsString('Last Operator Action', $body);
        $this->assertStringContainsString('workspace_login.php', $body);
        $this->assertStringContainsString('workspace_delete.php', $body);
        $this->assertStringContainsString('Delete selected workspaces', $body);
        $this->assertStringContainsString('workspace_provision.php', $body);
        $this->assertStringContainsString('Clarity Ops Guide', $body);
        $this->assertStringContainsString('data-superadmin="1"', $body);
        $this->assertStringContainsString('Super Admin Directory Workspace', $body);
    }

    public function testSuperAdminClarityOpsBillingGuideUsesPlatformSurface(): void
    {
        $response = $this->runWebEndpoint('api/chat/ask.php', $this->webSession(), [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'message' => 'Workspace billing guide',
                'surface' => 'superadmin_ops',
                'current_page' => 'workspaces.php',
            ]),
        ]);
        $payload = json_decode((string) ($response['body'] ?? ''), true);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame('superadmin_ops', (string) ($payload['diagnostics']['surface'] ?? ''));
        $this->assertStringContainsString('Workspace billing guide', (string) ($payload['answer'] ?? ''));
    }

    public function testSuperAdminClarityOpsCanCreatePlatformTasks(): void
    {
        $response = $this->runWebEndpoint('api/chat/ask.php', $this->webSession(), [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'message' => 'Create platform follow-up tasks',
                'surface' => 'superadmin_ops',
                'current_page' => 'workspaces.php',
            ]),
        ]);
        $payload = json_decode((string) ($response['body'] ?? ''), true);
        $task = Database::queryOne(
            "SELECT metadata_json
             FROM tasks
             WHERE assigned_to = ?
               AND metadata_json IS NOT NULL
             ORDER BY id DESC
             LIMIT 1",
            [$this->superAdminUserId]
        );

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame('superadmin_ops', (string) ($payload['diagnostics']['surface'] ?? ''));
        $this->assertNotEmpty($task);
        $this->assertStringContainsString('superadmin_ops', (string) ($task['metadata_json'] ?? ''));
    }

    public function testNonSuperAdminCannotUseSuperAdminClarityOpsSurface(): void
    {
        $viewerId = (int) (Auth::createUser('clarity.ops.viewer@example.com', 'P@ssword123!', 'viewer', 'Viewer', 'User') ?? 0);

        $response = $this->runWebEndpoint('api/chat/ask.php', [
            'user_id' => $viewerId,
            'user_uuid' => 'viewer-user',
            'user_email' => 'clarity.ops.viewer@example.com',
            'user_role' => 'viewer',
            'active_workspace_id' => $this->workspaceId,
            'csrf_token' => 'csrf-workspace-login',
            '__remember_restore_attempted' => true,
        ], [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'message' => 'Workspace billing guide',
                'surface' => 'superadmin_ops',
            ]),
        ]);

        $this->assertSame(403, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
    }

    public function testSettingsPageExposesWorkspaceDirectoryLinkForPlatformAdmins(): void
    {
        $response = $this->runWebEndpoint('public/settings.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['tab' => 'platform_admin'],
        ]);

        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace Directory', $body);
        $this->assertStringContainsString('workspaces.php', $body);
        $this->assertStringContainsString('Run/Repair Platform Ops Setup', $body);
        $this->assertStringContainsString('Artifact health', $body);
    }

    public function testSettingsPostOperationalizesDefaultWorkspaceForSuperAdmin(): void
    {
        $response = $this->runWebEndpoint('public/settings.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'tab' => 'platform_admin',
                'settings_action' => 'operationalize_default_workspace',
                'default_workspace_reason' => 'Integration test Platform Ops setup repair',
            ],
        ]);

        $workspace = Database::queryOne("SELECT settings_json FROM workspaces WHERE id = 1");
        $settings = json_decode((string) ($workspace['settings_json'] ?? '{}'), true);
        $state = Database::queryOne("SELECT status, readiness_score FROM workspace_onboarding_state WHERE workspace_id = 1");

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame('platform_ops', (string) ($settings['workspace_purpose'] ?? ''));
        $this->assertSame('completed', (string) ($state['status'] ?? ''));
        $this->assertSame(100, (int) ($state['readiness_score'] ?? 0));
    }

    public function testSettingsPostRejectsInvalidCsrfForPlatformOpsSetup(): void
    {
        $response = $this->runWebEndpoint('public/settings.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'wrong-token',
                'tab' => 'platform_admin',
                'settings_action' => 'operationalize_default_workspace',
            ],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Invalid security token', (string) ($response['body'] ?? ''));
    }

    public function testNormalUserDoesNotSeePlatformOpsSettingsAction(): void
    {
        $viewerId = (int) (Auth::createUser('settings.ops.viewer@example.com', 'P@ssword123!', 'viewer', 'Viewer', 'User') ?? 0);

        $response = $this->runWebEndpoint('public/settings.php', [
            'user_id' => $viewerId,
            'user_uuid' => 'settings-viewer',
            'user_email' => 'settings.ops.viewer@example.com',
            'user_role' => 'viewer',
            'active_workspace_id' => $this->workspaceId,
            'active_workspace_uuid' => $this->workspaceUuid,
            'active_workspace_slug' => $this->workspaceSlug,
            'active_workspace_name' => $this->workspaceName,
            'active_workspace_role' => 'viewer',
            'csrf_token' => 'csrf-workspace-login',
            '__remember_restore_attempted' => true,
        ], [
            'method' => 'GET',
            'query' => ['tab' => 'general'],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringNotContainsString('Run/Repair Platform Ops Setup', (string) ($response['body'] ?? ''));
    }

    public function testDefaultWorkspaceDashboardShowsOneHundredPercentOperationalAfterSetup(): void
    {
        (new \CRM\Services\DefaultWorkspaceOperationalizationService())->operationalize($this->superAdminUserId);

        $response = $this->runWebEndpoint('public/dashboard.php', $this->defaultWorkspaceSession(), [
            'method' => 'GET',
            'query' => ['user_id' => ''],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Your workspace is 100% operational', (string) ($response['body'] ?? ''));
    }

    public function testDefaultWorkspaceDashboardShowsDealCountsWhenConversionDealValueIsZero(): void
    {
        (new \CRM\Services\DefaultWorkspaceOperationalizationService())->operationalize($this->superAdminUserId);
        $deal = Database::queryOne(
            "SELECT d.id
             FROM default_workspace_owner_contacts map
             JOIN deals d ON d.id = map.conversion_deal_id AND d.workspace_id = map.default_workspace_id
             WHERE map.default_workspace_id = 1
               AND map.owner_workspace_id = ?
             LIMIT 1",
            [$this->workspaceId]
        );
        $this->assertNotEmpty($deal['id'] ?? null);

        Database::execute(
            "UPDATE deals SET stage = 'qualification', value = 0, assigned_to = ? WHERE id = ?",
            [$this->superAdminUserId, (int) $deal['id']]
        );

        $response = $this->runWebEndpoint('public/dashboard.php', $this->defaultWorkspaceSession(), ['method' => 'GET']);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Deals by Stage', $body);
        $this->assertStringContainsString('Open deal count and pipeline value', $body);
        $this->assertStringContainsString('deal-stage-summary', $body);
        $this->assertStringContainsString('Qualification', $body);
        $this->assertStringContainsString('Open Deals', $body);
        $this->assertStringContainsString('const dealCountData = ', $body);
    }

    public function testWorkspaceLoginRejectsNonPlatformUser(): void
    {
        $viewerId = (int) (Auth::createUser('workspace.login.viewer@example.com', 'P@ssword123!', 'viewer', 'Viewer', 'User') ?? 0);

        $response = $this->runWebEndpoint('public/workspace_login.php', [
            'user_id' => $viewerId,
            'user_uuid' => 'viewer-user',
            'user_email' => 'workspace.login.viewer@example.com',
            'user_role' => 'viewer',
            'csrf_token' => 'csrf-workspace-login',
            '__remember_restore_attempted' => true,
        ], [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'workspace_id' => $this->workspaceId,
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0));
    }

    public function testPlatformSettingsPermissionAloneCannotAccessWorkspaceDirectory(): void
    {
        $operatorId = (int) (Auth::createUser('workspace.directory.operator@example.com', 'P@ssword123!', 'admin', 'Platform', 'Operator') ?? 0);
        $this->assignPlatformSettingsOnlyRole($operatorId);

        $response = $this->runWebEndpoint('public/workspaces.php', $this->sessionForUser($operatorId), [
            'method' => 'GET',
        ]);
        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
    }

    public function testWorkspaceLoginStartsImpersonationWhen2FASettingIsOff(): void
    {
        (new PlatformSecuritySettingsService())->setRequireSuperadminWorkspace2FA(false, $this->superAdminUserId);

        $response = $this->runWebEndpoint('public/workspace_login.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'workspace_id' => $this->workspaceId,
            ],
        ]);

        $audit = Database::queryOne(
            "SELECT action_type
             FROM operator_audit_log
             WHERE target_workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$this->workspaceId]
        );

        $this->assertSame(302, (int) ($response['status'] ?? 0));
        $this->assertSame('impersonation_start', (string) ($audit['action_type'] ?? ''));
    }

    public function testWorkspaceLoginRedirectsTo2FAWhenSettingIsOn(): void
    {
        (new PlatformSecuritySettingsService())->setRequireSuperadminWorkspace2FA(true, $this->superAdminUserId);

        $response = $this->runWebEndpoint('public/workspace_login.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'workspace_id' => $this->workspaceId,
            ],
        ]);
        $auditCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM operator_audit_log
             WHERE target_workspace_id = ?
               AND action_type = 'impersonation_start'",
            [$this->workspaceId]
        )['c'] ?? 0);

        $this->assertSame(302, (int) ($response['status'] ?? 0));
        $this->assertSame(0, $auditCount);
    }

    public function testWorkspaceSwitchRedirectsTo2FASetupWhenWorkspaceRequiresIt(): void
    {
        (new WorkspaceSecuritySettingsService())->setRequireMember2FA($this->workspaceId, true, $this->superAdminUserId);

        $response = $this->runWebEndpoint('public/workspace_switch.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'workspace_id' => $this->workspaceId,
                'return_to' => 'dashboard.php',
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0));
    }

    public function testWorkspaceSwitchAllowsMemberWith2FAWhenWorkspaceRequiresIt(): void
    {
        (new WorkspaceSecuritySettingsService())->setRequireMember2FA($this->workspaceId, true, $this->superAdminUserId);
        $this->enableTotpForSuperAdmin('JBSWY3DPEHPK3PXP');

        $response = $this->runWebEndpoint('public/workspace_switch.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'workspace_id' => $this->workspaceId,
                'return_to' => 'dashboard.php',
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0));
    }

    public function testWorkspaceRequired2FASetupPageRendersWithoutActiveWorkspace(): void
    {
        (new WorkspaceSecuritySettingsService())->setRequireMember2FA($this->workspaceId, true, $this->superAdminUserId);

        $response = $this->runWebEndpoint('public/settings_2fa.php', array_merge($this->webSession(), [
            'pending_workspace_2fa_setup' => [
                'user_id' => $this->superAdminUserId,
                'workspace_id' => $this->workspaceId,
                'workspace_name' => $this->workspaceName,
                'workspace_slug' => $this->workspaceSlug,
                'return_to' => 'dashboard.php',
                'created_at' => time(),
            ],
        ]), [
            'method' => 'GET',
            'query' => ['required' => 'workspace'],
        ]);

        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('requires two-factor authentication before access is granted', $body);
        $this->assertStringContainsString('Enable Two-Factor Authentication', $body);
        $this->assertStringNotContainsString('Application error', $body);
    }

    public function testWorkspaceRequired2FASetupShowsRecoveryCodesBeforeContinuing(): void
    {
        (new WorkspaceSecuritySettingsService())->setRequireMember2FA($this->workspaceId, true, $this->superAdminUserId);
        $secret = 'JBSWY3DPEHPK3PXP';
        $code = (new Google2FA())->getCurrentOtp($secret);

        $response = $this->runWebEndpoint('public/settings_2fa.php', array_merge($this->webSession(), [
            '2fa_setup_secret' => $secret,
            'pending_workspace_2fa_setup' => [
                'user_id' => $this->superAdminUserId,
                'workspace_id' => $this->workspaceId,
                'workspace_name' => $this->workspaceName,
                'workspace_slug' => $this->workspaceSlug,
                'return_to' => 'dashboard.php',
                'created_at' => time(),
            ],
        ]), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'action' => 'enable_2fa_verify',
                'verification_code' => $code,
            ],
        ]);

        $body = (string) ($response['body'] ?? '');
        $user = Database::queryOne("SELECT two_factor_enabled, two_factor_secret FROM users WHERE id = ?", [$this->superAdminUserId]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Save Your Recovery Codes', $body);
        $this->assertStringContainsString('Continue to workspace', $body);
        $this->assertStringContainsString('You can now access', $body);
        $this->assertSame(1, (int) ($user['two_factor_enabled'] ?? 0));
        $this->assertSame($secret, (string) ($user['two_factor_secret'] ?? ''));
    }

    public function testWorkspaceRequired2FAVerifyRendersAfterWorkspaceSessionWasCleared(): void
    {
        (new WorkspaceSecuritySettingsService())->setRequireMember2FA($this->workspaceId, true, $this->superAdminUserId);
        $secret = 'JBSWY3DPEHPK3PXP';
        $code = (new Google2FA())->getCurrentOtp($secret);
        $session = $this->webSession();
        foreach ([
            'active_workspace_id',
            'active_workspace_uuid',
            'active_workspace_slug',
            'active_workspace_name',
            'active_workspace_role',
            'active_workspace_membership_id',
        ] as $key) {
            unset($session[$key]);
        }

        $response = $this->runWebEndpoint('public/settings_2fa.php', array_merge($session, [
            '2fa_setup_secret' => $secret,
            'pending_workspace_2fa_setup' => [
                'user_id' => $this->superAdminUserId,
                'workspace_id' => $this->workspaceId,
                'workspace_name' => $this->workspaceName,
                'workspace_slug' => $this->workspaceSlug,
                'return_to' => 'dashboard.php',
                'created_at' => time(),
            ],
        ]), [
            'method' => 'POST',
            'query' => ['required' => 'workspace'],
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'action' => 'enable_2fa_verify',
                'verification_code' => $code,
            ],
        ]);

        $body = (string) ($response['body'] ?? '');
        $user = Database::queryOne("SELECT two_factor_enabled, two_factor_secret FROM users WHERE id = ?", [$this->superAdminUserId]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Save Your Recovery Codes', $body);
        $this->assertStringNotContainsString('Application error', $body);
        $this->assertSame(1, (int) ($user['two_factor_enabled'] ?? 0));
        $this->assertSame($secret, (string) ($user['two_factor_secret'] ?? ''));
    }

    public function testWorkspaceLogin2FABlocksSuperAdminWithoutAuthenticator(): void
    {
        $response = $this->runWebEndpoint('public/workspace_login_2fa.php', $this->pending2FASession(), [
            'method' => 'GET',
        ]);

        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Set up two-factor authentication', $body);
        $this->assertStringContainsString('settings_2fa.php', $body);
    }

    public function testWorkspaceLogin2FARejectsInvalidTotp(): void
    {
        $this->enableTotpForSuperAdmin('JBSWY3DPEHPK3PXP');

        $response = $this->runWebEndpoint('public/workspace_login_2fa.php', $this->pending2FASession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'code' => '000000',
            ],
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Invalid authenticator code', (string) ($response['body'] ?? ''));
    }

    public function testWorkspaceLogin2FAAcceptsValidTotpAndAuditsVerification(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $this->enableTotpForSuperAdmin($secret);
        $code = (new Google2FA())->getCurrentOtp($secret);

        $response = $this->runWebEndpoint('public/workspace_login_2fa.php', $this->pending2FASession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'code' => $code,
            ],
        ]);
        $auditActions = Database::query(
            "SELECT action_type
             FROM operator_audit_log
             WHERE target_workspace_id = ?
             ORDER BY id DESC
             LIMIT 2",
            [$this->workspaceId]
        );
        $actions = array_map(static fn(array $row): string => (string) ($row['action_type'] ?? ''), $auditActions);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertContains('impersonation_start', $actions);
        $this->assertContains('workspace_login_2fa_verified', $actions);
    }

    public function testWorkspaceDeleteRejectsNonPlatformUser(): void
    {
        $targetWorkspaceId = $this->provisionTargetWorkspace('delete.reject@example.com');
        $viewerId = (int) (Auth::createUser('workspace.delete.viewer@example.com', 'P@ssword123!', 'viewer', 'Viewer', 'User') ?? 0);

        $response = $this->runWebEndpoint('public/workspace_delete.php', [
            'user_id' => $viewerId,
            'user_uuid' => 'viewer-user',
            'user_email' => 'workspace.delete.viewer@example.com',
            'user_role' => 'viewer',
            'csrf_token' => 'csrf-workspace-login',
            '__remember_restore_attempted' => true,
        ], [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'workspace_ids' => [$targetWorkspaceId],
                'delete_confirm_text' => 'DELETE WORKSPACE',
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0));
    }

    public function testWorkspaceDeleteRequiresConfirmationPhrase(): void
    {
        $targetWorkspaceId = $this->provisionTargetWorkspace('delete.confirm@example.com');

        $response = $this->runWebEndpoint('public/workspace_delete.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'workspace_ids' => [$targetWorkspaceId],
                'delete_confirm_text' => 'DELETE',
            ],
        ]);

        $remaining = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspaces WHERE id = ?", [$targetWorkspaceId])['c'] ?? 0);

        $this->assertSame(302, (int) ($response['status'] ?? 0));
        $this->assertSame(1, $remaining);
    }

    public function testWorkspaceDeleteDirectlyWhen2FASettingIsOff(): void
    {
        $targetWorkspaceId = $this->provisionTargetWorkspace('delete.direct@example.com');
        $targetWorkspace = Database::queryOne("SELECT name FROM workspaces WHERE id = ?", [$targetWorkspaceId]) ?? [];
        (new PlatformSecuritySettingsService())->setRequireSuperadminWorkspace2FA(false, $this->superAdminUserId);

        $response = $this->runWebEndpoint('public/workspace_delete.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'workspace_ids' => [$targetWorkspaceId],
                'delete_confirm_text' => 'DELETE WORKSPACE',
            ],
        ]);

        $remaining = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspaces WHERE id = ?", [$targetWorkspaceId])['c'] ?? 0);
        $ownerRemaining = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM users WHERE email = ?", ['delete.direct@example.com'])['c'] ?? 0);
        $audit = Database::queryOne(
            "SELECT action_type, metadata_json
             FROM operator_audit_log
             WHERE action_type = 'workspace_delete_completed'
             ORDER BY id DESC
             LIMIT 1"
        );

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame(0, $remaining);
        $this->assertSame(0, $ownerRemaining);
        $this->assertSame('workspace_delete_completed', (string) ($audit['action_type'] ?? ''));
        $this->assertStringContainsString((string) $targetWorkspaceId, (string) ($audit['metadata_json'] ?? ''));

        $recreated = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => (string) ($targetWorkspace['name'] ?? 'Delete Target delete direct example com'),
            'first_name' => 'Delete',
            'last_name' => 'Target',
            'email' => 'delete.direct@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $this->assertGreaterThan(0, (int) ($recreated['workspace_id'] ?? 0));
    }

    public function testWorkspaceDeleteRedirectsTo2FAWhenSettingIsOn(): void
    {
        $targetWorkspaceId = $this->provisionTargetWorkspace('delete.2fa.redirect@example.com');
        (new PlatformSecuritySettingsService())->setRequireSuperadminWorkspace2FA(true, $this->superAdminUserId);

        $response = $this->runWebEndpoint('public/workspace_delete.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'workspace_ids' => [$targetWorkspaceId],
                'delete_confirm_text' => 'DELETE WORKSPACE',
            ],
        ]);

        $remaining = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspaces WHERE id = ?", [$targetWorkspaceId])['c'] ?? 0);

        $this->assertSame(302, (int) ($response['status'] ?? 0));
        $this->assertSame(1, $remaining);
    }

    public function testWorkspaceDelete2FABlocksSuperAdminWithoutAuthenticator(): void
    {
        $targetWorkspaceId = $this->provisionTargetWorkspace('delete.no.totp@example.com');

        $response = $this->runWebEndpoint('public/workspace_delete_2fa.php', $this->pendingDelete2FASession([$targetWorkspaceId]), [
            'method' => 'GET',
        ]);

        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Set up two-factor authentication', $body);
        $this->assertStringContainsString('settings_2fa.php', $body);
    }

    public function testWorkspaceDelete2FARejectsInvalidTotp(): void
    {
        $targetWorkspaceId = $this->provisionTargetWorkspace('delete.invalid.totp@example.com');
        $this->enableTotpForSuperAdmin('JBSWY3DPEHPK3PXP');

        $response = $this->runWebEndpoint('public/workspace_delete_2fa.php', $this->pendingDelete2FASession([$targetWorkspaceId]), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'code' => '000000',
            ],
        ]);

        $remaining = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspaces WHERE id = ?", [$targetWorkspaceId])['c'] ?? 0);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Invalid authenticator code', (string) ($response['body'] ?? ''));
        $this->assertSame(1, $remaining);
    }

    public function testWorkspaceDelete2FAAcceptsValidTotpDeletesBulkAndAuditsVerification(): void
    {
        $firstWorkspaceId = $this->provisionTargetWorkspace('delete.valid.one@example.com');
        $secondWorkspaceId = $this->provisionTargetWorkspace('delete.valid.two@example.com');
        $secret = 'JBSWY3DPEHPK3PXP';
        $this->enableTotpForSuperAdmin($secret);
        $code = (new Google2FA())->getCurrentOtp($secret);

        $response = $this->runWebEndpoint('public/workspace_delete_2fa.php', $this->pendingDelete2FASession([$firstWorkspaceId, $secondWorkspaceId]), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-workspace-login',
                'code' => $code,
            ],
        ]);

        $remaining = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspaces WHERE id IN (?, ?)",
            [$firstWorkspaceId, $secondWorkspaceId]
        )['c'] ?? 0);
        $auditActions = Database::query(
            "SELECT action_type
             FROM operator_audit_log
             WHERE action_type IN ('workspace_delete_2fa_verified', 'workspace_delete_completed')
             ORDER BY id DESC
             LIMIT 2"
        );
        $actions = array_map(static fn(array $row): string => (string) ($row['action_type'] ?? ''), $auditActions);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame(0, $remaining);
        $this->assertContains('workspace_delete_2fa_verified', $actions);
        $this->assertContains('workspace_delete_completed', $actions);
    }

    /**
     * @return array<string,mixed>
     */
    private function webSession(): array
    {
        return [
            'user_id' => $this->superAdminUserId,
            'user_uuid' => 'super-admin-user',
            'user_email' => 'directory.owner@example.com',
            'user_role' => 'admin',
            'active_workspace_id' => $this->workspaceId,
            'active_workspace_uuid' => $this->workspaceUuid,
            'active_workspace_slug' => $this->workspaceSlug,
            'active_workspace_name' => $this->workspaceName,
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => $this->membershipId,
            'csrf_token' => 'csrf-workspace-login',
            '__remember_restore_attempted' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultWorkspaceSession(): array
    {
        $workspace = Database::queryOne("SELECT uuid, slug, name FROM workspaces WHERE id = 1") ?? [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = 1 AND user_id = ? LIMIT 1",
            [$this->superAdminUserId]
        ) ?? [];

        return array_merge($this->webSession(), [
            'active_workspace_id' => 1,
            'active_workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'active_workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'active_workspace_name' => (string) ($workspace['name'] ?? ''),
            'active_workspace_role' => 'superadmin',
            'active_workspace_membership_id' => (int) ($membership['id'] ?? 0),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function pending2FASession(): array
    {
        return array_merge($this->webSession(), [
            'pending_superadmin_workspace_login' => [
                'actor_user_id' => $this->superAdminUserId,
                'workspace_id' => $this->workspaceId,
                'target_user_id' => null,
                'reason' => 'Super Admin workspace login from workspace directory.',
                'created_at' => time(),
            ],
        ]);
    }

    /**
     * @param array<int> $workspaceIds
     * @return array<string,mixed>
     */
    private function pendingDelete2FASession(array $workspaceIds): array
    {
        return array_merge($this->webSession(), [
            'pending_superadmin_workspace_delete' => [
                'actor_user_id' => $this->superAdminUserId,
                'workspace_ids' => $workspaceIds,
                'reason' => 'Super Admin workspace deletion from workspace directory.',
                'active_workspace_id' => $this->workspaceId,
                'created_at' => time(),
            ],
        ]);
    }

    private function enableTotpForSuperAdmin(string $secret): void
    {
        Database::execute(
            "UPDATE users
             SET two_factor_enabled = 1,
                 two_factor_secret = ?
             WHERE id = ?",
            [$secret, $this->superAdminUserId]
        );
    }

    private function assignGlobalRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        $this->assertNotEmpty($role['id'] ?? null);
        Authorization::assignUserRole($userId, (int) $role['id'], $userId);
    }

    private function assignPlatformSettingsOnlyRole(int $userId): void
    {
        Database::execute(
            "INSERT INTO roles (name, slug, description, is_system, is_active)
             VALUES ('Platform Settings Only', 'platform-settings-only-test', 'Test-only platform settings permission without superadmin', 0, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = VALUES(is_active)"
        );
        Database::execute(
            "INSERT INTO role_permissions (role_id, permission_id, can_access)
             SELECT r.id, p.id, 1
             FROM roles r
             JOIN permissions p ON p.permission_key = 'platform.settings.manage'
             WHERE r.slug = 'platform-settings-only-test'
             ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)"
        );
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'platform-settings-only-test' LIMIT 1");
        $this->assertNotEmpty($role['id'] ?? null);
        Authorization::assignUserRole($userId, (int) $role['id'], $userId);
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionForUser(int $userId): array
    {
        $user = Database::queryOne("SELECT uuid, email, role FROM users WHERE id = ? LIMIT 1", [$userId]) ?? [];

        return [
            'user_id' => $userId,
            'user_uuid' => (string) ($user['uuid'] ?? ''),
            'user_email' => (string) ($user['email'] ?? ''),
            'user_role' => (string) ($user['role'] ?? 'viewer'),
            'active_workspace_id' => $this->workspaceId,
            'active_workspace_uuid' => $this->workspaceUuid,
            'active_workspace_slug' => $this->workspaceSlug,
            'active_workspace_name' => $this->workspaceName,
            'active_workspace_role' => 'viewer',
            'csrf_token' => 'csrf-workspace-login',
            '__remember_restore_attempted' => true,
        ];
    }

    private function provisionTargetWorkspace(string $email): int
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Delete Target ' . preg_replace('/[^a-z0-9]+/i', ' ', $email),
            'first_name' => 'Delete',
            'last_name' => 'Target',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        return (int) ($provisioned['workspace_id'] ?? 0);
    }

}
